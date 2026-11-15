<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Unit\Services\Assistant\ChatClientTest;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Pterodactyl\Enum\AiProviderPreset;
use Pterodactyl\Exceptions\Assistant\ChatProviderException;
use Pterodactyl\Models\Assistant\AiProvider;
use Pterodactyl\Services\Assistant\Chat\ChatClient;
use Pterodactyl\Services\Assistant\Chat\ProviderUrl;
use Pterodactyl\Tests\Support\Assistant\FakeAi;
use Pterodactyl\Tests\TestCase;

uses(TestCase::class);

function provider(AiProviderPreset $preset = AiProviderPreset::Custom, array $attributes = []): AiProvider
{
    return new AiProvider([
        'name' => 'Test',
        'preset' => $preset,
        'base_url' => FakeAi::BASE_URL,
        'api_key' => 'sk-test-secret-key',
        'model' => 'cline-pass/deepseek-v4.1-flash',
        'supports_tools' => true,
        ...$attributes,
    ]);
}

/** @return list<array<string, mixed>> */
function tools(): array
{
    return [['type' => 'function', 'function' => ['name' => 'echo_text', 'description' => 'Echo', 'parameters' => ['type' => 'object', 'properties' => (object) []]]]];
}

test('Cline gets stream:false, the OpenAI response format header and no tool_choice', function (): void {
    Http::fake([FakeAi::URL => Http::response(FakeAi::text('Hello'))]);

    $response = resolve(ChatClient::class)->complete(provider(AiProviderPreset::ClinePass), [['role' => 'user', 'content' => 'Hi']], tools(), 512);

    expect($response->content)->toBe('Hello')->and($response->usage->input)->toBe(100)->and($response->usage->output)->toBe(20);

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $request->url() === FakeAi::URL
            && $request->hasHeader('X-Response-Format', 'openai')
            && $request->hasHeader('Authorization', 'Bearer sk-test-secret-key')
            && $body['stream'] === false
            && $body['max_tokens'] === 512
            && $body['model'] === 'cline-pass/deepseek-v4.1-flash'
            && count($body['tools']) === 1
            && ! array_key_exists('tool_choice', $body);
    });
});

test('the legacy Cline wrapper is unwrapped', function (): void {
    Http::fake([FakeAi::URL => Http::response(['success' => true, 'data' => FakeAi::text('Wrapped')])]);

    $response = resolve(ChatClient::class)->complete(provider(AiProviderPreset::Cline), [['role' => 'user', 'content' => 'Hi']]);

    expect($response->content)->toBe('Wrapped');
});

test('tools are left out for a provider without tool support, and a missing key sends no Authorization', function (): void {
    Http::fake([FakeAi::URL => Http::response(FakeAi::text('ok'))]);

    resolve(ChatClient::class)->complete(provider(AiProviderPreset::Ollama, ['api_key' => null, 'supports_tools' => false]), [['role' => 'user', 'content' => 'Hi']], tools());

    Http::assertSent(fn (Request $request): bool => ! array_key_exists('tools', $request->data())
        && ! array_key_exists('tool_choice', $request->data())
        && ! $request->hasHeader('Authorization')
        && ! $request->hasHeader('X-Response-Format'));
});

test('tool calls without ids get one, and object arguments (Ollama) are accepted', function (): void {
    Http::fake([FakeAi::URL => Http::response([
        'choices' => [[
            'message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                ['function' => ['name' => 'echo_text', 'arguments' => ['text' => 'a']]],
                ['id' => '', 'type' => 'function', 'function' => ['name' => 'echo_text', 'arguments' => '{"text":"b"}']],
                ['id' => 'call_x', 'type' => 'function', 'function' => ['name' => 'echo_text', 'arguments' => '{not json']],
            ]],
            'finish_reason' => 'tool_calls',
        ]],
        'prompt_eval_count' => 40,
        'eval_count' => 9,
    ])]);

    $response = resolve(ChatClient::class)->complete(provider(AiProviderPreset::Ollama), [['role' => 'user', 'content' => 'Hi']], tools());

    expect($response->toolCalls)->toHaveCount(3)
        ->and($response->toolCalls[0]->id)->toStartWith('call_')->and(mb_strlen($response->toolCalls[0]->id))->toBe(29)
        ->and($response->toolCalls[0]->arguments)->toBe(['text' => 'a'])
        ->and($response->toolCalls[0]->rawArguments)->toBe('{"text":"a"}')
        ->and($response->toolCalls[1]->id)->toStartWith('call_')->not->toBe($response->toolCalls[0]->id)
        ->and($response->toolCalls[1]->arguments)->toBe(['text' => 'b'])
        ->and($response->toolCalls[2]->id)->toBe('call_x')
        ->and($response->toolCalls[2]->arguments)->toBeNull()
        ->and($response->content)->toBeNull()
        ->and($response->usage->input)->toBe(40)
        ->and($response->usage->output)->toBe(9)
        ->and($response->usage->reported)->toBeTrue();
});

test('usage is normalised across providers and estimated when missing', function (): void {
    Http::fakeSequence(FakeAi::URL)
        ->push([...FakeAi::text('a'), 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 5, 'prompt_tokens_details' => ['cached_tokens' => 30], 'cost' => 0.0012]])
        ->push([...FakeAi::text('b'), 'usage' => ['prompt_tokens' => 60, 'completion_tokens' => 6, 'prompt_cache_hit_tokens' => 20]])
        ->push([...FakeAi::text('b'), 'usage' => ['input_tokens' => 70, 'output_tokens' => 7]])
        ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => str_repeat('x', 40)], 'finish_reason' => 'stop']]]);

    $client = resolve(ChatClient::class);
    $messages = [['role' => 'user', 'content' => str_repeat('y', 400)]];

    $cline = $client->complete(provider(), $messages)->usage;
    $deepseek = $client->complete(provider(), $messages)->usage;
    $other = $client->complete(provider(), $messages)->usage;
    $estimated = $client->complete(provider(), $messages)->usage;

    expect([$cline->input, $cline->output, $cline->cached, $cline->cost])->toBe([50, 5, 30, 0.0012])
        ->and([$deepseek->input, $deepseek->output, $deepseek->cached])->toBe([60, 6, 20])
        ->and([$other->input, $other->output])->toBe([70, 7])
        ->and($estimated->reported)->toBeFalse()
        ->and($estimated->output)->toBe(10)
        ->and($estimated->input)->toBeGreaterThanOrEqual(100);
});

test('an error with HTTP 200 and finish_reason "error" fails the call', function (): void {
    Http::fake([FakeAi::URL => Http::response([
        'choices' => [['message' => ['role' => 'assistant', 'content' => ''], 'finish_reason' => 'error', 'error' => ['code' => 'context_length_exceeded', 'message' => 'The context is too long.']]],
    ])]);

    expect(fn () => resolve(ChatClient::class)->complete(provider(), [['role' => 'user', 'content' => 'Hi']]))
        ->toThrow(ChatProviderException::class, 'The context is too long.');
});

test('a streamed answer is read even though stream:false was sent', function (): void {
    $chunks = [
        ': OPENROUTER PROCESSING',
        'data: '.json_encode(['choices' => [['delta' => ['content' => 'Hel']]]]),
        'data: '.json_encode(['choices' => [['delta' => ['content' => 'lo', 'tool_calls' => [['index' => 0, 'id' => 'call_s', 'function' => ['name' => 'echo_text', 'arguments' => '{"te']]]]]]]),
        'data: '.json_encode(['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => 'xt":"z"}']]]], 'finish_reason' => 'tool_calls']]]),
        'data: '.json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 3]]),
        'data: [DONE]',
    ];
    Http::fake([FakeAi::URL => Http::response(implode("\n\n", $chunks), 200, ['Content-Type' => 'text/event-stream'])]);

    $response = resolve(ChatClient::class)->complete(provider(), [['role' => 'user', 'content' => 'Hi']], tools());

    expect($response->content)->toBe('Hello')
        ->and($response->toolCalls[0]->id)->toBe('call_s')
        ->and($response->toolCalls[0]->arguments)->toBe(['text' => 'z'])
        ->and($response->usage->input)->toBe(12);
});

test('a streamed error ends the call with that error', function (): void {
    Http::fake([FakeAi::URL => Http::response('data: '.json_encode(['choices' => [['delta' => [], 'finish_reason' => 'error', 'error' => ['message' => 'rate_limit']]]])."\n\ndata: [DONE]\n", 200)]);

    expect(fn () => resolve(ChatClient::class)->complete(provider(), [['role' => 'user', 'content' => 'Hi']]))
        ->toThrow(ChatProviderException::class, 'rate_limit');
});

test('429 and 5xx are retried twice with backoff', function (): void {
    Http::fakeSequence(FakeAi::URL)
        ->push(['error' => ['message' => 'slow down']], 429, ['Retry-After' => '3'])
        ->push('', 502)
        ->push(FakeAi::text('finally'));

    $response = resolve(ChatClient::class)->complete(provider(), [['role' => 'user', 'content' => 'Hi']]);

    expect($response->content)->toBe('finally');
    Http::assertSentCount(3);
    Sleep::assertSlept(fn ($duration): bool => (int) $duration->totalMilliseconds === 3000, 1);
    Sleep::assertSlept(fn ($duration): bool => (int) $duration->totalMilliseconds === 2000, 1);
});

test('the call gives up after two retries', function (): void {
    Http::fakeSequence(FakeAi::URL)->push('', 503)->push('', 503)->push('', 503)->push(FakeAi::text('never'));

    expect(fn () => resolve(ChatClient::class)->complete(provider(), [['role' => 'user', 'content' => 'Hi']]))
        ->toThrow(ChatProviderException::class, 'HTTP 503');
    Http::assertSentCount(3);
});

test('a rejected key is reported without the key or long tokens', function (): void {
    Http::fake([FakeAi::URL => Http::response(['error' => ['message' => 'Incorrect API key provided: sk-test-secret-key']], 401)]);

    try {
        resolve(ChatClient::class)->complete(provider(), [['role' => 'user', 'content' => 'Hi']]);
        $this->fail('The call should have failed.');
    } catch (ChatProviderException $chatProviderException) {
        expect($chatProviderException->getMessage())->toContain('rejected the API key')->not->toContain('sk-test-secret-key');
    }

    Http::assertSentCount(1);
});

test('extra headers are sent but never replace the Authorization header', function (): void {
    Http::fake([FakeAi::URL => Http::response(FakeAi::text('ok'))]);

    resolve(ChatClient::class)->complete(provider(attributes: ['extra_headers' => ['X-Proxy' => 'yes', 'Authorization' => 'Bearer evil']]), [['role' => 'user', 'content' => 'Hi']]);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Proxy', 'yes') && $request->hasHeader('Authorization', 'Bearer sk-test-secret-key'));
});

test('cloud metadata addresses are never called', function (string $url, bool $allowed): void {
    expect(ProviderUrl::isAllowed($url))->toBe($allowed);
})->with([
    ['https://api.cline.bot/api/v1', true],
    ['http://127.0.0.1:11434/v1', true],
    ['http://192.168.1.20:11434/v1', true],
    ['http://169.254.169.254/latest', false],
    ['http://[fd00:ec2::254]/', false],
    ['http://metadata.google.internal/computeMetadata', false],
    ['http://2852039166/', false],
    ['http://0xa9fea9fe/', false],
    ['http://0251.0376.0251.0376/', false],
    ['http://user:pass@example.com/v1', false],
    ['ftp://example.com/v1', false],
    ['https://deadbeef.cafe/v1', true],
]);

test('a disallowed address fails before any request', function (): void {
    Http::fake();

    expect(fn () => resolve(ChatClient::class)->complete(provider(attributes: ['base_url' => 'http://169.254.169.254/v1']), [['role' => 'user', 'content' => 'Hi']]))
        ->toThrow(ChatProviderException::class);
    Http::assertNothingSent();
});
