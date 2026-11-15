<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Admin\Assistant\AdminAssistantApiTest;

use Illuminate\Support\Facades\Http;
use Pterodactyl\Models\ApiKey;
use Pterodactyl\Models\Assistant\AiConversation;
use Pterodactyl\Models\Assistant\AiProvider;
use Pterodactyl\Models\Assistant\AiToolCall;
use Pterodactyl\Models\Assistant\AiUsage;
use Pterodactyl\Models\Setting;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Tests\Integration\Api\Admin\AdminApiIntegrationTestCase;
use Pterodactyl\Tests\Support\Assistant\FakeAi;

uses(AdminApiIntegrationTestCase::class);

test('a user who is not a root administrator is refused everywhere', function (string $method, string $uri): void {
    FakeAi::enable();
    $this->actingAsNonAdmin();
    Http::fake();

    $this->json($method, $uri, ['message' => 'Hi'])->assertForbidden();
    Http::assertNothingSent();
})->with([
    ['GET', '/api/admin/assistant/conversations'],
    ['POST', '/api/admin/assistant/conversations'],
    ['GET', '/api/admin/assistant/providers'],
    ['POST', '/api/admin/assistant/providers'],
    ['GET', '/api/admin/assistant/settings'],
    ['PUT', '/api/admin/assistant/settings'],
    ['GET', '/api/admin/assistant/usage'],
]);

test('a root administrator API key is refused: the admin assistant is session only', function (): void {
    FakeAi::enable();
    $key = ApiKey::factory()->create(['user_id' => $this->getAdminUser()->id, 'key_type' => ApiKey::TYPE_ACCOUNT]);
    $this->app['auth']->forgetGuards();

    $this->withHeaders(['Authorization' => 'Bearer '.$key->identifier.decrypt($key->token), 'Accept' => 'application/vnd.pterodactyl.v1+json'])
        ->getJson('/api/admin/assistant/providers')
        ->assertForbidden();
});

test('the admin assistant chats with admin tools, asks before sensitive ones and is never charged', function (): void {
    FakeAi::enable(freeTokens: 0);
    $tools = FakeAi::tools(ToolContext::SCOPE_ADMIN);
    Http::fakeSequence(FakeAi::URL)
        ->push(FakeAi::toolCall('pretend_delete', ['path' => 'server 1']))
        ->push(FakeAi::text('Suspended.'));

    $response = $this->postJson('/api/admin/assistant/conversations', ['message' => 'Suspend server 1'])
        ->assertOk()
        ->assertJsonPath('status', 'awaiting_approval');
    $uuid = $response->json('uuid');
    $call = AiToolCall::query()->firstOrFail();

    $this->postJson("/api/admin/assistant/conversations/{$uuid}/tool-calls/{$call->id}/approve")
        ->assertOk()
        ->assertJsonPath('status', 'idle')
        ->assertJsonPath('messages.2.content', 'Suspended.');

    expect($tools['confirm']->calls)->toBe([['path' => 'server 1']]);
    expect(AiUsage::query()->where('scope', 'admin')->sum('panel_tokens_charged'))->toEqual(0);
    $this->assertActivityLogged('admin:assistant.tool');

    $this->getJson('/api/admin/assistant/conversations')->assertOk()->assertJsonPath('data.0.uuid', $uuid);
});

test("another admin's conversation and server conversations are not found", function (): void {
    FakeAi::enable();
    $other = User::factory()->create(['root_admin' => true]);
    $theirs = AiConversation::query()->create(['uuid' => '1d3c1f3e-3333-4c7a-9b8e-2d5f4a1c0e93', 'user_id' => $other->id, 'scope' => 'admin', 'title' => 'Theirs']);
    [, $server] = $this->generateTestAccount();
    $serverScoped = AiConversation::query()->create(['uuid' => '2d3c1f3e-4444-4c7a-9b8e-2d5f4a1c0e93', 'user_id' => $this->getAdminUser()->id, 'scope' => 'server', 'server_id' => $server->id, 'title' => 'Server']);

    $this->getJson("/api/admin/assistant/conversations/{$theirs->uuid}")->assertNotFound();
    $this->deleteJson("/api/admin/assistant/conversations/{$theirs->uuid}")->assertNotFound();
    $this->getJson("/api/admin/assistant/conversations/{$serverScoped->uuid}")->assertNotFound();
    $this->getJson('/api/admin/assistant/conversations')->assertOk()->assertJsonCount(0, 'data');
});

test('providers are managed with a write-only key', function (): void {
    $response = $this->postJson('/api/admin/assistant/providers', [
        'name' => 'ClinePass',
        'preset' => 'clinepass',
        'base_url' => 'https://api.cline.bot/api/v1',
        'api_key' => 'sk-very-secret',
        'model' => 'cline-pass/deepseek-v4.1-flash',
        'extra_headers' => ['X-Team' => 'ops'],
    ])->assertCreated();

    $response->assertJsonPath('has_key', true)->assertJsonPath('extra_headers', ['X-Team' => 'ops'])->assertJsonMissingPath('api_key');
    expect($response->getContent())->not->toContain('sk-very-secret');
    $id = $response->json('id');
    $this->assertActivityLogged('admin:assistant.provider.create');

    // Leaving the key out keeps it; null removes it.
    $this->putJson("/api/admin/assistant/providers/{$id}", ['name' => 'Renamed'])->assertOk()->assertJsonPath('name', 'Renamed')->assertJsonPath('has_key', true);
    expect(AiProvider::query()->findOrFail($id)->api_key)->toBe('sk-very-secret');
    $this->putJson("/api/admin/assistant/providers/{$id}", ['api_key' => null])->assertOk()->assertJsonPath('has_key', false);

    $list = $this->getJson('/api/admin/assistant/providers')->assertOk();
    $list->assertJsonPath('data.0.id', $id)->assertJsonPath('meta.presets.1.preset', 'clinepass')->assertJsonPath('meta.presets.1.base_url', 'https://api.cline.bot/api/v1');
    expect(collect($list->json('meta.presets'))->firstWhere('preset', 'ollama')['base_url'])->toBe('http://127.0.0.1:11434/v1');

    $this->deleteJson("/api/admin/assistant/providers/{$id}")->assertNoContent();
    expect(AiProvider::query()->count())->toBe(0);
});

test('a provider cannot point at cloud metadata or set its own Authorization header', function (): void {
    $base = ['name' => 'x', 'preset' => 'custom', 'model' => 'm'];

    $this->postJson('/api/admin/assistant/providers', [...$base, 'base_url' => 'http://169.254.169.254/v1'])->assertUnprocessable()->assertJsonPath('errors.0.meta.source_field', 'base_url');
    $this->postJson('/api/admin/assistant/providers', [...$base, 'base_url' => 'https://ai.example.test/v1', 'extra_headers' => ['Authorization' => 'Bearer x']])->assertUnprocessable();
    $this->postJson('/api/admin/assistant/providers', [...$base, 'base_url' => 'https://ai.example.test/v1', 'extra_headers' => ['X-Bad' => "a\r\nInjected: 1"]])->assertUnprocessable();
    expect(AiProvider::query()->count())->toBe(0);
});

test('the provider test reports success and failure without charging', function (): void {
    $provider = FakeAi::provider();
    Http::fakeSequence(FakeAi::URL)
        ->push(FakeAi::text('OK'))
        ->push(['error' => ['message' => 'nope']], 401);

    $this->postJson("/api/admin/assistant/providers/{$provider->id}/test")->assertOk()->assertJsonPath('ok', true)->assertJsonPath('reply', 'OK')->assertJsonPath('error', null);
    $failed = $this->postJson("/api/admin/assistant/providers/{$provider->id}/test")->assertOk()->assertJsonPath('ok', false);
    expect($failed->json('error'))->toContain('rejected the API key')->and($failed->json('latency_ms'))->toBeInt();
    expect(AiUsage::query()->count())->toBe(0);
});

test('settings are read and stored', function (): void {
    $provider = FakeAi::provider();
    $this->getJson('/api/admin/assistant/settings')->assertOk()->assertJsonPath('enabled', false)->assertJsonPath('ratio', 1000)->assertJsonPath('free_tokens_monthly', 50000);

    $settings = [
        'enabled' => true,
        'server_enabled' => true,
        'admin_enabled' => false,
        'default_provider_id' => $provider->id,
        'ratio' => 500,
        'free_tokens_monthly' => 20000,
        'warn_percent' => 90,
        'max_tool_iterations' => 5,
        'context_tokens' => 16000,
        'max_output_tokens' => 1024,
    ];
    $this->putJson('/api/admin/assistant/settings', $settings)->assertOk()->assertExactJson($settings);
    $this->assertActivityLogged('admin:assistant.settings.update');

    expect(Setting::query()->where('key', 'settings::monoforge:ai:ratio')->value('value'))->toBe('500');
    $this->putJson('/api/admin/assistant/settings', [...$settings, 'ratio' => 0])->assertUnprocessable();
});

test('usage is reported per user for a month', function (): void {
    $alex = User::factory()->create(['username' => 'alex']);
    $sam = User::factory()->create(['username' => 'sam']);
    AiUsage::query()->create(['user_id' => $alex->id, 'scope' => 'server', 'period' => '2026-10', 'ai_tokens' => 1000, 'free_tokens' => 800, 'panel_tokens_charged' => 1]);
    AiUsage::query()->create(['user_id' => $alex->id, 'scope' => 'server', 'period' => '2026-10', 'ai_tokens' => 500, 'free_tokens' => 0, 'panel_tokens_charged' => 1]);
    AiUsage::query()->create(['user_id' => $sam->id, 'scope' => 'admin', 'period' => '2026-10', 'ai_tokens' => 300, 'free_tokens' => 0, 'panel_tokens_charged' => 0]);
    AiUsage::query()->create(['user_id' => $sam->id, 'scope' => 'server', 'period' => '2026-09', 'ai_tokens' => 9999, 'free_tokens' => 0, 'panel_tokens_charged' => 9]);

    $this->getJson('/api/admin/assistant/usage?period=2026-10')
        ->assertOk()
        ->assertJsonPath('period', '2026-10')
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.user.username', 'alex')
        ->assertJsonPath('data.0.ai_tokens', 1500)
        ->assertJsonPath('data.0.free_tokens', 800)
        ->assertJsonPath('data.0.panel_tokens_charged', 2)
        ->assertJsonPath('data.1.admin_ai_tokens', 300)
        ->assertJsonPath('totals.ai_tokens', 1800)
        ->assertJsonPath('totals.calls', 3);

    $this->getJson('/api/admin/assistant/usage?period=2026-13')->assertUnprocessable();
});
