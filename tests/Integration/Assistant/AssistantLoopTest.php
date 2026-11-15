<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Assistant\AssistantLoopTest;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Models\Assistant\AiConversation;
use Pterodactyl\Models\Assistant\AiMessage;
use Pterodactyl\Models\Assistant\AiToolCall;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;
use Pterodactyl\Tests\Support\Assistant\FakeAi;

uses(ClientApiIntegrationTestCase::class);

beforeEach(function (): void {
    FakeAi::enable();
    $this->tools = FakeAi::tools();
    [$this->user, $this->server] = $this->generateTestAccount();
    $this->base = "/api/client/servers/{$this->server->uuid}/assistant/conversations";
});

/** @return list<array<string, mixed>> */
function sentMessages(int $index): array
{
    $recorded = Http::recorded()->values();

    return $recorded[$index][0]->data()['messages'];
}

test('an auto tool runs right away and the loop continues to a final answer', function (): void {
    Http::fakeSequence(FakeAi::URL)
        ->push(FakeAi::toolCall('echo_text', ['text' => 'hi']))
        ->push(FakeAi::text('All done.'));

    $response = $this->actingAs($this->user)->postJson($this->base, ['message' => 'Say hi'])->assertOk();

    $response->assertJsonPath('status', 'idle')
        ->assertJsonPath('title', 'Say hi')
        ->assertJsonCount(3, 'messages')
        ->assertJsonPath('messages.0.role', 'user')
        ->assertJsonPath('messages.1.tool_calls.0.name', 'echo_text')
        ->assertJsonPath('messages.1.tool_calls.0.label', 'Echo hi')
        ->assertJsonPath('messages.1.tool_calls.0.status', 'done')
        ->assertJsonPath('messages.1.tool_calls.0.needs_confirmation', false)
        ->assertJsonPath('messages.1.tool_calls.0.result_preview', 'echo: hi')
        ->assertJsonPath('messages.1.tool_calls.0.display', ['echoed' => true])
        ->assertJsonPath('messages.2.content', 'All done.');

    expect($this->tools['echo']->calls)->toBe([['arguments' => ['text' => 'hi'], 'user' => $this->user->id]]);

    // The second call carries the tool result, and both offered the user's tools.
    Http::assertSentCount(2);
    $second = sentMessages(1);
    expect($second[0]['role'])->toBe('system')
        ->and($second[0]['content'])->toContain('untrusted data')
        ->and($second[2]['tool_calls'][0]['id'])->toBe('call_1')
        ->and($second[3])->toBe(['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'echo: hi']);
    Http::assertSent(fn (Request $request): bool => collect($request->data()['tools'])->pluck('function.name')->sort()->values()->all() === ['echo_text', 'pretend_delete']);
});

test('a tool that needs confirmation pauses the turn', function (): void {
    Http::fakeSequence(FakeAi::URL)->push(FakeAi::toolCall('pretend_delete', ['path' => '.env']));

    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Delete .env'])
        ->assertOk()
        ->assertJsonPath('status', 'awaiting_approval')
        ->assertJsonPath('messages.1.tool_calls.0.status', 'pending')
        ->assertJsonPath('messages.1.tool_calls.0.needs_confirmation', true)
        ->assertJsonPath('messages.1.tool_calls.0.label', 'Delete .env');

    expect($this->tools['confirm']->calls)->toBe([]);
    Http::assertSentCount(1);

    // A new message must wait for the decision.
    $conversation = AiConversation::query()->firstOrFail();
    $this->actingAs($this->user)->postJson("{$this->base}/{$conversation->uuid}/messages", ['message' => 'hello?'])->assertStatus(409);
});

test('allowing the call runs it and resumes the loop', function (): void {
    Http::fakeSequence(FakeAi::URL)
        ->push(FakeAi::toolCall('pretend_delete', ['path' => 'old.log']))
        ->push(FakeAi::text('Deleted it.'));

    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Delete old.log'])->assertOk();
    $conversation = AiConversation::query()->firstOrFail();
    $call = AiToolCall::query()->firstOrFail();

    $this->actingAs($this->user)->postJson("{$this->base}/{$conversation->uuid}/tool-calls/{$call->id}/approve")
        ->assertOk()
        ->assertJsonPath('status', 'idle')
        ->assertJsonPath('messages.1.tool_calls.0.status', 'done')
        ->assertJsonPath('messages.1.tool_calls.0.result_preview', 'deleted old.log')
        ->assertJsonPath('messages.2.content', 'Deleted it.');

    expect($this->tools['confirm']->calls)->toBe([['path' => 'old.log']]);
    expect($call->refresh()->decided_by)->toBe($this->user->id)->and($call->decided_at)->not->toBeNull();
    $this->assertActivityFor('server:assistant.tool', $this->user, $this->server);

    // Deciding twice changes nothing.
    $this->actingAs($this->user)->postJson("{$this->base}/{$conversation->uuid}/tool-calls/{$call->id}/approve")->assertStatus(409);
    expect($this->tools['confirm']->calls)->toHaveCount(1);
});

test('denying the call tells the model and the loop continues', function (): void {
    Http::fakeSequence(FakeAi::URL)
        ->push(FakeAi::toolCall('pretend_delete', ['path' => 'world']))
        ->push(FakeAi::text('Okay, I will not delete it.'));

    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Delete the world'])->assertOk();
    $conversation = AiConversation::query()->firstOrFail();
    $call = AiToolCall::query()->firstOrFail();

    $this->actingAs($this->user)->postJson("{$this->base}/{$conversation->uuid}/tool-calls/{$call->id}/deny")
        ->assertOk()
        ->assertJsonPath('status', 'idle')
        ->assertJsonPath('messages.1.tool_calls.0.status', 'denied')
        ->assertJsonPath('messages.2.content', 'Okay, I will not delete it.');

    expect($this->tools['confirm']->calls)->toBe([]);
    expect(sentMessages(1)[3])->toBe(['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'The user denied this action.']);
    $this->assertActivityFor('server:assistant.tool', $this->user, $this->server);
});

test('the permission is checked again when an allowed call runs', function (): void {
    Http::fakeSequence(FakeAi::URL)
        ->push(FakeAi::toolCall('pretend_delete', ['path' => 'a.txt']))
        ->push(FakeAi::text('I could not.'));

    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Delete a.txt'])->assertOk();
    $conversation = AiConversation::query()->firstOrFail();
    $call = AiToolCall::query()->firstOrFail();

    // The permission is gone by the time the user clicks Allow.
    $this->tools['confirm']->allowedFor = [];

    $this->actingAs($this->user)->postJson("{$this->base}/{$conversation->uuid}/tool-calls/{$call->id}/approve")
        ->assertOk()
        ->assertJsonPath('messages.1.tool_calls.0.status', 'failed')
        ->assertJsonPath('messages.1.tool_calls.0.result_preview', 'Error: you do not have permission to use this tool.');

    expect($this->tools['confirm']->calls)->toBe([]);
});

test('a tool error is reported to the model as the result', function (): void {
    Http::fakeSequence(FakeAi::URL)
        ->push(FakeAi::toolCall('pretend_delete', ['path' => 'boom']))
        ->push(FakeAi::text('That failed.'));

    $this->actingAs($this->user)->postJson($this->base, ['message' => 'x'])->assertOk();
    $conversation = AiConversation::query()->firstOrFail();
    $call = AiToolCall::query()->firstOrFail();

    $this->actingAs($this->user)->postJson("{$this->base}/{$conversation->uuid}/tool-calls/{$call->id}/approve")
        ->assertOk()
        ->assertJsonPath('messages.1.tool_calls.0.status', 'failed')
        ->assertJsonPath('messages.1.tool_calls.0.result_preview', 'Error: That file does not exist.');
});

test('the loop stops calling tools after max_tool_iterations and asks for a final answer', function (): void {
    config()->set('monoforge.ai.max_tool_iterations', 2);
    Http::fakeSequence(FakeAi::URL)
        ->push(FakeAi::toolCall('echo_text', ['text' => '1'], 'call_a'))
        ->push(FakeAi::toolCall('echo_text', ['text' => '2'], 'call_b'))
        ->push(FakeAi::text('Here is what I found.'));

    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Loop'])
        ->assertOk()
        ->assertJsonPath('status', 'idle')
        ->assertJsonPath('messages.3.content', 'Here is what I found.');

    Http::assertSentCount(3);
    $last = Http::recorded()->values()[2][0]->data();
    expect($last)->not->toHaveKey('tools')
        ->and($last['messages'][0]['content'])->toContain('most tool calls allowed');
    expect($this->tools['echo']->calls)->toHaveCount(2);
});

test('repeated call ids are replaced so every result stays matched', function (): void {
    Http::fakeSequence(FakeAi::URL)
        ->push(FakeAi::toolCall('echo_text', ['text' => '1'], 'call_0'))
        ->push(FakeAi::toolCall('echo_text', ['text' => '2'], 'call_0'))
        ->push(FakeAi::text('done'));

    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Twice'])->assertOk()->assertJsonPath('status', 'idle');

    $ids = AiToolCall::query()->orderBy('id')->pluck('call_id')->all();
    expect($ids[0])->toBe('call_0')->and($ids[1])->not->toBe('call_0');
});

test('unknown tools and invalid arguments fail without running anything', function (): void {
    Http::fakeSequence(FakeAi::URL)
        ->push(FakeAi::toolCall('format_disk', []))
        ->push([
            'choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'call_bad', 'type' => 'function', 'function' => ['name' => 'pretend_delete', 'arguments' => '{oops']]]], 'finish_reason' => 'tool_calls']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2],
        ])
        ->push(FakeAi::text('Sorry.'));

    $this->actingAs($this->user)->postJson($this->base, ['message' => 'x'])
        ->assertOk()
        ->assertJsonPath('status', 'idle')
        ->assertJsonPath('messages.1.tool_calls.0.status', 'failed')
        ->assertJsonPath('messages.1.tool_calls.0.result_preview', 'Error: there is no tool named "format_disk".')
        ->assertJsonPath('messages.2.tool_calls.0.status', 'failed')
        ->assertJsonPath('messages.2.tool_calls.0.needs_confirmation', false);

    expect($this->tools['confirm']->calls)->toBe([]);
});

test('a provider error ends the turn with a readable error and the user can continue', function (): void {
    Http::fakeSequence(FakeAi::URL)
        ->push(['error' => ['message' => 'bad key']], 401)
        ->push(FakeAi::text('Back again.'));

    $response = $this->actingAs($this->user)->postJson($this->base, ['message' => 'Hi'])->assertOk();
    $response->assertJsonPath('status', 'error');

    expect($response->json('error'))->toContain('rejected the API key');

    $conversation = AiConversation::query()->firstOrFail();
    $this->actingAs($this->user)->postJson("{$this->base}/{$conversation->uuid}/messages", ['message' => 'Again'])
        ->assertOk()
        ->assertJsonPath('status', 'idle')
        ->assertJsonPath('error', null)
        ->assertJsonPath('messages.2.content', 'Back again.');
});

test('a conversation stuck as running recovers after three minutes', function (): void {
    $conversation = AiConversation::query()->create([
        'uuid' => '0b5c1f3e-1111-4c7a-9b8e-2d5f4a1c0e93',
        'user_id' => $this->user->id,
        'scope' => AiConversation::SCOPE_SERVER,
        'server_id' => $this->server->id,
        'title' => 'Stuck',
        'status' => AiConversation::STATUS_RUNNING,
    ]);

    $this->actingAs($this->user)->getJson("{$this->base}/{$conversation->uuid}")->assertOk()->assertJsonPath('status', 'running');

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(4));
    $this->actingAs($this->user)->getJson("{$this->base}/{$conversation->uuid}")
        ->assertOk()
        ->assertJsonPath('status', 'error');

    Http::fakeSequence(FakeAi::URL)->push(FakeAi::text('Hello again.'));
    $this->actingAs($this->user)->postJson("{$this->base}/{$conversation->uuid}/messages", ['message' => 'Continue'])
        ->assertOk()
        ->assertJsonPath('status', 'idle');
});

test('tool messages are not listed but feed the next model call', function (): void {
    Http::fakeSequence(FakeAi::URL)
        ->push(FakeAi::toolCall('echo_text', ['text' => 'x']))
        ->push(FakeAi::text('ok'));

    $this->actingAs($this->user)->postJson($this->base, ['message' => 'x'])->assertOk()->assertJsonCount(3, 'messages');

    expect(AiMessage::query()->where('role', 'tool')->count())->toBe(1);
});
