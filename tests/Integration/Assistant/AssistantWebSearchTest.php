<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Assistant\AssistantWebSearchTest;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\TransientToken;
use Pterodactyl\Enum\AiProviderPreset;
use Pterodactyl\Models\Assistant\AiProvider;
use Pterodactyl\Models\Assistant\AiToolCall;
use Pterodactyl\Models\Assistant\AiUsage;
use Pterodactyl\Models\Setting;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Assistant\Search\PublicUrl;
use Pterodactyl\Services\Assistant\Search\WebSearch;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;
use Pterodactyl\Tests\Support\Assistant\FakeAi;

uses(ClientApiIntegrationTestCase::class);

const BRAVE = 'https://api.search.brave.com/res/v1/web/search*';

beforeEach(function (): void {
    $this->provider = FakeAi::enable();
    [$this->user, $this->server] = $this->generateTestAccount();
    $this->base = "/api/client/servers/{$this->server->uuid}/assistant/conversations";
    // No DNS in tests: the search API's name resolves to a public address.
    $this->app->instance(PublicUrl::class, new PublicUrl(fn (string $host): array => ['93.184.216.34']));
});

function useBrave(string $key = 'brave-test-key'): void
{
    config()->set('monoforge.ai.web_search_enabled', true);
    config()->set('monoforge.ai.web_search_provider', 'brave');
    config()->set('monoforge.ai.web_search_key', $key);
}

/** @return list<string> */
function offeredTools(int $index = 0): array
{
    return collect(Http::recorded()->values()[$index][0]->data()['tools'] ?? [])->pluck('function.name')->all();
}

test('web search is not offered until it is turned on and set up', function (): void {
    Http::fake([FakeAi::URL => Http::response(FakeAi::text('Hi.'))]);

    // Off by default.
    $this->actingAs($this->user)->postJson($this->base, ['message' => 'One'])->assertOk();
    expect(offeredTools(0))->not->toContain('web_search')->toContain('read_console');

    // Turned on with Brave but without a key.
    useBrave('');
    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Two'])->assertOk();
    expect(offeredTools(1))->not->toContain('web_search');

    // Native search with a provider that has none.
    config()->set('monoforge.ai.web_search_provider', 'native');
    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Three'])->assertOk();
    expect(offeredTools(2))->not->toContain('web_search');

    useBrave();
    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Four'])->assertOk();
    expect(offeredTools(3))->toContain('web_search');

    // Off again in the settings.
    config()->set('monoforge.ai.web_search_enabled', false);
    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Five'])->assertOk();
    expect(offeredTools(4))->not->toContain('web_search');
});

test('a Brave search returns public sources to the model and the card, and is billed', function (): void {
    useBrave();
    Http::fake([
        BRAVE => Http::response(['web' => ['results' => [
            ['title' => 'Paper <strong>docs</strong>', 'url' => 'https://docs.papermc.io/paper', 'description' => 'Paper is a <strong>fork</strong> of Spigot.'],
            ['title' => 'Internal', 'url' => 'http://10.0.0.5/admin', 'description' => 'should be dropped'],
            ['title' => 'Script', 'url' => 'javascript:alert(1)', 'description' => 'should be dropped'],
            ['title' => 'Paper docs again', 'url' => 'https://docs.papermc.io/paper', 'description' => 'duplicate'],
        ]]]),
        FakeAi::URL => Http::sequence()
            ->push(FakeAi::toolCall('web_search', ['query' => '  paper   server docs ']))
            ->push(FakeAi::text('Paper is a fork of Spigot.')),
    ]);

    $this->actingAs($this->user)->postJson($this->base, ['message' => 'What is Paper?'])
        ->assertOk()
        ->assertJsonPath('status', 'idle')
        ->assertJsonPath('messages.1.tool_calls.0.name', 'web_search')
        ->assertJsonPath('messages.1.tool_calls.0.label', 'Search the web for "paper server docs"')
        ->assertJsonPath('messages.1.tool_calls.0.needs_confirmation', false)
        ->assertJsonPath('messages.1.tool_calls.0.status', 'done')
        // Field by field: MySQL stores JSON keys in its own order.
        ->assertJsonCount(1, 'messages.1.tool_calls.0.display.sources')
        ->assertJsonPath('messages.1.tool_calls.0.display.sources.0.title', 'Paper docs')
        ->assertJsonPath('messages.1.tool_calls.0.display.sources.0.url', 'https://docs.papermc.io/paper')
        ->assertJsonPath('messages.2.content', 'Paper is a fork of Spigot.');

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.search.brave.com/')
        && $request->hasHeader('X-Subscription-Token', 'brave-test-key')
        && $request['q'] === 'paper server docs');

    $result = AiToolCall::query()->sole()->result;
    expect($result)->toContain('1. Paper docs - https://docs.papermc.io/paper')
        ->toContain('Paper is a fork of Spigot.')
        ->not->toContain('10.0.0.5')
        ->not->toContain('javascript:');

    // Two model calls and one search.
    expect(AiUsage::query()->orderBy('id')->pluck('ai_tokens')->all())->toBe([120, WebSearch::SEARCH_COST_TOKENS, 120]);
});

test('the search API is never called at a private or non-https address', function (string $endpoint, ?string $resolvesTo): void {
    useBrave();
    config()->set('monoforge.ai.web_search_endpoints.brave', $endpoint);
    if ($resolvesTo !== null) {
        $this->app->instance(PublicUrl::class, new PublicUrl(fn (string $host): array => [$resolvesTo]));
    }

    Http::fake([
        FakeAi::URL => Http::sequence()
            ->push(FakeAi::toolCall('web_search', ['query' => 'anything']))
            ->push(FakeAi::text('No luck.')),
        '*' => Http::response(['web' => ['results' => []]]),
    ]);

    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Search'])
        ->assertOk()
        ->assertJsonPath('messages.1.tool_calls.0.status', 'failed')
        ->assertJsonPath('messages.1.tool_calls.0.result_preview', 'Error: The web search address is not a public https address, so it was not called.');

    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $request): bool => ! str_starts_with($request->url(), FakeAi::BASE_URL));
    expect(AiUsage::query()->count())->toBe(2);
})->with([
    'loopback' => ['https://127.0.0.1/search', null],
    'localhost' => ['https://localhost/search', null],
    'link-local metadata' => ['https://169.254.169.254/latest/meta-data', null],
    'plain http' => ['http://api.search.brave.com/res/v1/web/search', null],
    'another port' => ['https://api.search.brave.com:8443/res/v1/web/search', null],
    'user info' => ['https://user:pass@api.search.brave.com/search', null],
    'decimal address' => ['https://2130706433/search', null],
    'name resolving to a private address' => ['https://api.search.brave.com/res/v1/web/search', '10.1.2.3'],
    'name resolving to an IPv4-mapped loopback' => ['https://api.search.brave.com/res/v1/web/search', '::ffff:127.0.0.1'],
]);

test('a search answer that is too large is refused', function (): void {
    useBrave();
    Http::fake([
        BRAVE => Http::response(str_repeat('x', WebSearch::MAX_BYTES + 1)),
        FakeAi::URL => Http::sequence()
            ->push(FakeAi::toolCall('web_search', ['query' => 'big']))
            ->push(FakeAi::text('Too big.')),
    ]);

    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Search'])
        ->assertOk()
        ->assertJsonPath('messages.1.tool_calls.0.status', 'failed')
        ->assertJsonPath('messages.1.tool_calls.0.result_preview', 'Error: '.WebSearch::FAILED);
});

test('native search uses the OpenRouter web plugin and is billed like a model call', function (): void {
    $this->provider->forceFill(['preset' => AiProviderPreset::OpenRouter])->save();
    config()->set('monoforge.ai.web_search_enabled', true);
    config()->set('monoforge.ai.web_search_provider', 'native');

    $search = FakeAi::text('Paper needs Java 21.', 300, 50);
    $search['choices'][0]['message']['annotations'] = [
        ['type' => 'url_citation', 'url_citation' => ['url' => 'https://docs.papermc.io/java', 'title' => 'Java versions']],
        ['type' => 'url_citation', 'url_citation' => ['url' => 'http://192.168.1.1/', 'title' => 'Router']],
    ];

    Http::fake([FakeAi::URL => Http::sequence()
        ->push(FakeAi::toolCall('web_search', ['query' => 'paper java version']))
        ->push($search)
        ->push(FakeAi::text('Use Java 21.'))]);

    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Which Java?'])
        ->assertOk()
        ->assertJsonPath('messages.1.tool_calls.0.status', 'done')
        ->assertJsonPath('messages.1.tool_calls.0.display.summary', 'Paper needs Java 21.')
        ->assertJsonCount(1, 'messages.1.tool_calls.0.display.sources')
        ->assertJsonPath('messages.1.tool_calls.0.display.sources.0.title', 'Java versions')
        ->assertJsonPath('messages.1.tool_calls.0.display.sources.0.url', 'https://docs.papermc.io/java');

    $request = Http::recorded()->values()[1][0]->data();
    expect($request['plugins'])->toBe([['id' => 'web', 'max_results' => WebSearch::MAX_RESULTS]])
        ->and($request)->not->toHaveKey('tools')
        ->and($request['messages'][1]['content'])->toBe('paper java version');
    expect(AiUsage::query()->orderBy('id')->pluck('ai_tokens')->all())->toBe([120, 350, 120]);
});

test('admins set up web search and Allow everything, with the key write-only', function (): void {
    $admin = User::factory()->create(['root_admin' => true]);
    $admin->withAccessToken(new TransientToken);

    $settings = [
        'enabled' => true,
        'server_enabled' => true,
        'admin_enabled' => true,
        'default_provider_id' => null,
        'ratio' => 1000,
        'free_tokens_monthly' => 50000,
        'warn_percent' => 80,
        'max_tool_iterations' => 8,
        'context_tokens' => 24000,
        'max_output_tokens' => 2048,
    ];

    $this->actingAs($admin)->getJson('/api/admin/assistant/settings')
        ->assertOk()
        ->assertJsonPath('allow_everything_enabled', true)
        ->assertJsonPath('web_search_enabled', false)
        ->assertJsonPath('web_search_provider', 'native')
        ->assertJsonPath('web_search_key_set', false);

    $this->actingAs($admin)->putJson('/api/admin/assistant/settings', [
        ...$settings,
        'allow_everything_enabled' => false,
        'web_search_enabled' => true,
        'web_search_provider' => 'tavily',
        'web_search_key' => 'tvly-secret',
    ])->assertOk()
        ->assertJsonPath('allow_everything_enabled', false)
        ->assertJsonPath('web_search_provider', 'tavily')
        ->assertJsonPath('web_search_key_set', true)
        ->assertJsonMissing(['web_search_key' => 'tvly-secret']);

    $stored = Setting::query()->where('key', 'settings::monoforge:ai:web_search_key')->value('value');
    expect($stored)->not->toBe('tvly-secret')->and(Crypt::decrypt((string) $stored))->toBe('tvly-secret');

    // Left out, everything keeps its value; a blank key keeps the stored one.
    $this->actingAs($admin)->putJson('/api/admin/assistant/settings', [...$settings, 'web_search_key' => ''])
        ->assertOk()
        ->assertJsonPath('allow_everything_enabled', false)
        ->assertJsonPath('web_search_enabled', true)
        ->assertJsonPath('web_search_key_set', true);

    $this->actingAs($admin)->putJson('/api/admin/assistant/settings', [...$settings, 'web_search_provider' => 'google'])->assertUnprocessable();

    $this->actingAs($admin)->putJson('/api/admin/assistant/settings', [...$settings, 'clear_web_search_key' => true])
        ->assertOk()
        ->assertJsonPath('web_search_key_set', false);
});

test('a Tavily search sends the key as a bearer token and uses its answer', function (): void {
    config()->set('monoforge.ai.web_search_enabled', true);
    config()->set('monoforge.ai.web_search_provider', 'tavily');
    config()->set('monoforge.ai.web_search_key', 'tvly-key');

    Http::fake([
        'https://api.tavily.com/search' => Http::response([
            'answer' => 'Use /reload confirm.',
            'results' => [['title' => 'Reloading', 'url' => 'https://example.org/reload', 'content' => 'How to reload.']],
        ]),
        FakeAi::URL => Http::sequence()
            ->push(FakeAi::toolCall('web_search', ['query' => 'reload plugins']))
            ->push(FakeAi::text('Done.')),
    ]);

    $this->actingAs($this->user)->postJson($this->base, ['message' => 'How do I reload?'])
        ->assertOk()
        ->assertJsonPath('messages.1.tool_calls.0.display.summary', 'Use /reload confirm.');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.tavily.com/search'
        && $request->hasHeader('Authorization', 'Bearer tvly-key')
        && $request['query'] === 'reload plugins');
});

test('only OpenRouter has a native web search', function (): void {
    expect(WebSearch::supportsNativeSearch(new AiProvider(['preset' => AiProviderPreset::OpenRouter])))->toBeTrue()
        ->and(WebSearch::supportsNativeSearch(new AiProvider(['preset' => AiProviderPreset::OpenAi])))->toBeFalse()
        ->and(WebSearch::supportsNativeSearch(new AiProvider(['preset' => AiProviderPreset::Ollama])))->toBeFalse();
});
