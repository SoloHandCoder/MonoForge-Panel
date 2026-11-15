<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Assistant\AssistantEndToEndTest;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Contracts\Assistant\AssistantTool;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Models\Assistant\AiConversation;
use Pterodactyl\Models\Assistant\AiToolCall;
use Pterodactyl\Models\Assistant\AiUsage;
use Pterodactyl\Models\Billing\TokenTransaction;
use Pterodactyl\Models\User;
use Pterodactyl\Providers\AssistantToolServiceProvider;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolRegistry;
use Pterodactyl\Services\Billing\TokenWallet;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;
use Pterodactyl\Tests\Support\Assistant\FakeAi;
use Pterodactyl\Tests\Support\Fakes\FakeDaemonFile;

uses(ClientApiIntegrationTestCase::class);

/** @return list<string> */
function toolNames(string $scope, ToolContext $context): array
{
    return array_map(fn (AssistantTool $tool): string => $tool->name(), resolve(ToolRegistry::class)->for($scope, $context));
}

test('the real tools are registered in both scopes and filtered by allowed()', function (): void {
    [$owner, $server] = $this->generateTestAccount();
    $owner->forceFill(['root_admin' => true])->save();

    // An owner (and root admin) is offered every tool of each scope, and only that scope's tools.
    $serverTools = toolNames(ToolContext::SCOPE_SERVER, new ToolContext($owner, ToolContext::SCOPE_SERVER, $server));
    $adminTools = toolNames(ToolContext::SCOPE_ADMIN, new ToolContext($owner, ToolContext::SCOPE_ADMIN));

    expect($serverTools)->toHaveCount(count(AssistantToolServiceProvider::SERVER_TOOLS))
        ->toContain('read_console', 'list_files', 'delete_files', 'power')
        ->and($adminTools)->toContain('list_servers', 'list_users', 'suspend_server', 'unsuspend_server', 'adjust_tokens')
        ->and(array_intersect($serverTools, $adminTools))->toBe([]);

    // A subuser with only file.read and assistant.use gets the tools those allow, nothing that writes.
    [$subuser, $shared] = $this->generateTestAccount([Permissions::AssistantUse->value, Permissions::FileRead->value]);
    expect(toolNames(ToolContext::SCOPE_SERVER, new ToolContext($subuser, ToolContext::SCOPE_SERVER, $shared)))
        ->toEqualCanonicalizing(['server_status', 'list_files', 'search_files'])
        ->and(toolNames(ToolContext::SCOPE_ADMIN, new ToolContext($subuser, ToolContext::SCOPE_ADMIN)))->toBe([]);

    // Without assistant.use nothing is offered at all.
    [$noAssistant, $other] = $this->generateTestAccount([Permissions::FileRead->value]);
    expect(toolNames(ToolContext::SCOPE_SERVER, new ToolContext($noAssistant, ToolContext::SCOPE_SERVER, $other)))->toBe([]);
});

test('a turn runs an auto tool, pauses on delete_files, and finishes after Allow with billing recorded', function (): void {
    FakeAi::enable(freeTokens: 200, ratio: 100);
    [$user, $server] = $this->generateTestAccount();
    resolve(TokenWallet::class)->credit($user, 100, TokenTransaction::TYPE_PURCHASE, 'Test tokens');

    $wings = new FakeDaemonFile;
    $wings->directory = [
        ['name' => 'latest.log', 'file' => true, 'symlink' => false, 'size' => 2048, 'modified' => '2026-11-15T10:00:00Z'],
        ['name' => 'old.log', 'file' => true, 'symlink' => false, 'size' => 1048576, 'modified' => '2026-10-01T10:00:00Z'],
    ];
    Http::fakeSequence(FakeAi::URL)
        ->push(FakeAi::toolCall('list_files', ['directory' => '/logs'], 'call_list'))
        ->push(FakeAi::toolCall('delete_files', ['root' => '/logs', 'files' => ['old.log']], 'call_delete'))
        ->push(FakeAi::text('I deleted logs/old.log; 1 MB is free again.'));

    $base = "/api/client/servers/{$server->uuid}/assistant/conversations";

    // 1. The user asks; the model lists the directory (auto), then asks to delete (needs a click).
    $response = $this->actingAs($user)->postJson($base, ['message' => 'Clean up my old logs'])
        ->assertOk()
        ->assertJsonPath('status', 'awaiting_approval')
        ->assertJsonCount(3, 'messages')
        ->assertJsonPath('messages.1.tool_calls.0.name', 'list_files')
        ->assertJsonPath('messages.1.tool_calls.0.status', 'done')
        ->assertJsonPath('messages.1.tool_calls.0.needs_confirmation', false)
        ->assertJsonPath('messages.2.tool_calls.0.name', 'delete_files')
        ->assertJsonPath('messages.2.tool_calls.0.status', 'pending')
        ->assertJsonPath('messages.2.tool_calls.0.needs_confirmation', true);

    expect($response->json('messages.1.tool_calls.0.result_preview'))->toContain('old.log')
        ->and($response->json('messages.2.tool_calls.0.label'))->toContain('old.log');
    $wings->assertDirectoryListed('/logs');
    expect($wings->callsFor('deleteFiles'))->toBe([]);

    // The second model call carried the listing as the tool result, and offered the real tools.
    $aiRequests = collect(Http::recorded())->filter(fn (array $pair): bool => $pair[0]->url() === FakeAi::URL)->values();
    expect($aiRequests)->toHaveCount(2);
    $messages = $aiRequests[1][0]->data()['messages'];
    $toolMessage = collect($messages)->firstWhere('role', 'tool');
    expect($toolMessage['tool_call_id'])->toBe('call_list')
        ->and($toolMessage['content'])->toContain('old.log');
    Http::assertSent(fn (Request $request): bool => $request->url() === FakeAi::URL
        && collect($request->data()['tools'] ?? [])->pluck('function.name')->contains('delete_files'));

    // 2. Allow runs the deletion on Wings, and the turn resumes to the final answer.
    $conversation = AiConversation::query()->sole();
    $call = AiToolCall::query()->where('name', 'delete_files')->sole();

    $this->actingAs($user)->postJson("{$base}/{$conversation->uuid}/tool-calls/{$call->id}/approve")
        ->assertOk()
        ->assertJsonPath('status', 'idle')
        ->assertJsonPath('messages.2.tool_calls.0.status', 'done')
        ->assertJsonPath('messages.3.role', 'assistant')
        ->assertJsonPath('messages.3.content', 'I deleted logs/old.log; 1 MB is free again.');

    $wings->assertFilesDeleted('/logs', ['old.log']);
    expect($call->refresh()->decided_by)->toBe($user->id);
    $this->assertActivityFor('server:assistant.tool', $user, $server);

    // 3. Three model calls of 120 AI tokens each: 200 free, the rest charged at 100 per token.
    $usage = AiUsage::query()->where('user_id', $user->id)->orderBy('id')->get();
    expect($usage->map(fn (AiUsage $row): array => [$row->ai_tokens, $row->free_tokens, $row->panel_tokens_charged])->all())
        ->toBe([[120, 120, 0], [120, 80, 1], [120, 0, 2]])
        ->and($usage->pluck('conversation_id')->unique()->all())->toBe([$conversation->id]);

    expect(resolve(TokenWallet::class)->balance(User::query()->findOrFail($user->id)))->toBe(97)
        ->and(TokenTransaction::query()->where('user_id', $user->id)->where('type', TokenTransaction::TYPE_AI_USAGE)->sum('amount'))->toEqual(-3);

    $this->actingAs($user)->getJson('/api/client/assistant')
        ->assertOk()
        ->assertJsonPath('free_used', 200)
        ->assertJsonPath('balance', 97)
        ->assertJsonPath('warning', 'free_exhausted');
});
