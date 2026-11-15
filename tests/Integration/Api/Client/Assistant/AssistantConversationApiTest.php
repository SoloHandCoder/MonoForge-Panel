<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Client\Assistant\AssistantConversationApiTest;

use Illuminate\Support\Facades\Http;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Models\ApiKey;
use Pterodactyl\Models\Assistant\AiConversation;
use Pterodactyl\Models\Assistant\AiProvider;
use Pterodactyl\Models\Assistant\AiToolCall;
use Pterodactyl\Models\Subuser;
use Pterodactyl\Models\User;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;
use Pterodactyl\Tests\Support\Assistant\FakeAi;

use function pterodactylTestCase;

uses(ClientApiIntegrationTestCase::class);

beforeEach(function (): void {
    FakeAi::enable();
    FakeAi::tools();
});

function startConversation(User $user, string $serverUuid, array $answer): AiConversation
{
    Http::fakeSequence(FakeAi::URL)->push($answer);
    pterodactylTestCase()->actingAs($user)->postJson("/api/client/servers/{$serverUuid}/assistant/conversations", ['message' => 'Hello'])->assertOk();

    return AiConversation::query()->latest('id')->firstOrFail();
}

test('a subuser without assistant.use is refused', function (): void {
    [$user, $server] = $this->generateTestAccount([Permissions::FileRead->value]);
    Http::fake();

    $this->actingAs($user)->getJson("/api/client/servers/{$server->uuid}/assistant/conversations")->assertForbidden();
    $this->actingAs($user)->postJson("/api/client/servers/{$server->uuid}/assistant/conversations", ['message' => 'Hi'])->assertForbidden();
    Http::assertNothingSent();
});

test('a subuser with assistant.use can chat', function (): void {
    [$user, $server] = $this->generateTestAccount([Permissions::AssistantUse->value]);
    $conversation = startConversation($user, $server->uuid, FakeAi::text('Hi there'));

    $this->actingAs($user)->getJson("/api/client/servers/{$server->uuid}/assistant/conversations")
        ->assertOk()
        ->assertJsonPath('data.0.uuid', $conversation->uuid)
        ->assertJsonPath('data.0.status', 'idle');
});

test("another user's conversation is not found, even for the server owner", function (): void {
    [$owner, $server] = $this->generateTestAccount();
    $helper = User::factory()->create();
    Subuser::query()->create(['user_id' => $helper->id, 'server_id' => $server->id, 'permissions' => [Permissions::AssistantUse->value]]);

    $conversation = startConversation($helper, $server->uuid, FakeAi::toolCall('pretend_delete', ['path' => 'x']));
    $call = AiToolCall::query()->firstOrFail();
    $base = "/api/client/servers/{$server->uuid}/assistant/conversations/{$conversation->uuid}";

    $this->actingAs($owner)->getJson($base)->assertNotFound();
    $this->actingAs($owner)->postJson("{$base}/messages", ['message' => 'Hi'])->assertNotFound();
    $this->actingAs($owner)->postJson("{$base}/tool-calls/{$call->id}/approve")->assertNotFound();
    $this->actingAs($owner)->deleteJson($base)->assertNotFound();
    $this->actingAs($owner)->getJson("/api/client/servers/{$server->uuid}/assistant/conversations")->assertOk()->assertJsonCount(0, 'data');

    expect($call->refresh()->status)->toBe('pending');
});

test('a conversation is not found through another server', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $other = $this->createServerModel(['user_id' => $user->id]);
    $conversation = startConversation($user, $server->uuid, FakeAi::text('ok'));

    $this->actingAs($user)->getJson("/api/client/servers/{$other->uuid}/assistant/conversations/{$conversation->uuid}")->assertNotFound();
});

test('a tool call of another conversation is not found', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $first = startConversation($user, $server->uuid, FakeAi::toolCall('pretend_delete', ['path' => 'x']));
    $second = startConversation($user, $server->uuid, FakeAi::text('ok'));
    $call = AiToolCall::query()->where('conversation_id', $first->id)->firstOrFail();

    $this->actingAs($user)->postJson("/api/client/servers/{$server->uuid}/assistant/conversations/{$second->uuid}/tool-calls/{$call->id}/approve")->assertNotFound();
});

test('an API key cannot allow or deny a tool call', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $conversation = startConversation($user, $server->uuid, FakeAi::toolCall('pretend_delete', ['path' => 'x']));
    $call = AiToolCall::query()->firstOrFail();
    $key = ApiKey::factory()->create(['user_id' => $user->id, 'key_type' => ApiKey::TYPE_ACCOUNT]);

    $this->app['auth']->forgetGuards();
    $headers = ['Authorization' => 'Bearer '.$key->identifier.decrypt($key->token), 'Accept' => 'application/vnd.pterodactyl.v1+json'];
    $base = "/api/client/servers/{$server->uuid}/assistant/conversations/{$conversation->uuid}/tool-calls/{$call->id}";

    $this->withHeaders($headers)->postJson("{$base}/approve")->assertForbidden();
    $this->withHeaders($headers)->postJson("{$base}/deny")->assertForbidden();
    // Reading the conversation with a key is fine.
    $this->withHeaders($headers)->getJson("/api/client/servers/{$server->uuid}/assistant/conversations/{$conversation->uuid}")->assertOk();

    expect($call->refresh()->status)->toBe('pending');
});

test('the assistant can be turned off', function (): void {
    [$user, $server] = $this->generateTestAccount();
    config()->set('monoforge.ai.server_enabled', false);
    Http::fake();

    $this->actingAs($user)->postJson("/api/client/servers/{$server->uuid}/assistant/conversations", ['message' => 'Hi'])
        ->assertForbidden()
        ->assertJsonPath('errors.0.detail', 'The AI assistant is turned off.');
    $this->actingAs($user)->getJson('/api/client/assistant')->assertOk()->assertJsonPath('enabled', true)->assertJsonPath('server_enabled', false);
});

test('without a provider the conversation is refused with 503', function (): void {
    [$user, $server] = $this->generateTestAccount();
    AiProvider::query()->update(['enabled' => false]);

    $this->actingAs($user)->postJson("/api/client/servers/{$server->uuid}/assistant/conversations", ['message' => 'Hi'])->assertStatus(503);
});

test('a message is validated and a conversation can be deleted', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $this->actingAs($user)->postJson("/api/client/servers/{$server->uuid}/assistant/conversations", ['message' => ''])->assertUnprocessable();

    $conversation = startConversation($user, $server->uuid, FakeAi::text('ok'));
    $this->actingAs($user)->deleteJson("/api/client/servers/{$server->uuid}/assistant/conversations/{$conversation->uuid}")->assertNoContent();

    expect(AiConversation::query()->count())->toBe(0);
});

test('the provider key never reaches the browser', function (): void {
    [$user, $server] = $this->generateTestAccount();
    Http::fakeSequence(FakeAi::URL)->push(FakeAi::text('Hi'));

    $response = $this->actingAs($user)->postJson("/api/client/servers/{$server->uuid}/assistant/conversations", ['message' => 'Hello'])->assertOk();

    expect($response->getContent())->not->toContain('sk-test-secret-key');
    expect(AiProvider::query()->toBase()->value('api_key'))->not->toContain('sk-test-secret-key');
});
