<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Assistant\AssistantControlsTest;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\TransientToken;
use Pterodactyl\Jobs\Assistant\RunAssistantTurn;
use Pterodactyl\Models\ActivityLog;
use Pterodactyl\Models\Assistant\AiConversation;
use Pterodactyl\Models\Assistant\AiMessage;
use Pterodactyl\Models\Assistant\AiToolCall;
use Pterodactyl\Models\Assistant\AiUsage;
use Pterodactyl\Models\Assistant\AiUserSetting;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Assistant\AssistantAgent;
use Pterodactyl\Services\Assistant\ConversationService;
use Pterodactyl\Services\Assistant\ToolExecutor;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;
use Pterodactyl\Tests\Support\Assistant\FakeAi;

uses(ClientApiIntegrationTestCase::class);

beforeEach(function (): void {
    FakeAi::enable();
    $this->tools = FakeAi::tools();
    [$this->user, $this->server] = $this->generateTestAccount();
    $this->base = "/api/client/servers/{$this->server->uuid}/assistant/conversations";
});

/**
 * One model answer with several tool calls.
 *
 * @param  list<array{string, array<string, mixed>}>  $calls
 * @return array<string, mixed>
 */
function answerWithCalls(array $calls): array
{
    return [
        'choices' => [[
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => array_map(fn (array $call, int $index): array => [
                    'id' => 'call_'.$index,
                    'type' => 'function',
                    'function' => ['name' => $call[0], 'arguments' => json_encode($call[1])],
                ], $calls, array_keys($calls)),
            ],
            'finish_reason' => 'tool_calls',
        ]],
        'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
    ];
}

function onlyConversation(): AiConversation
{
    return AiConversation::query()->sole();
}

// --- Stop -----------------------------------------------------------------------------------

test('stopping while a call waits for approval cancels it and says so in the thread', function (): void {
    Http::fakeSequence(FakeAi::URL)->push(FakeAi::toolCall('pretend_delete', ['path' => 'a.txt']));

    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Delete a.txt'])->assertJsonPath('status', 'awaiting_approval');
    $conversation = onlyConversation();

    $this->actingAs($this->user)->postJson("{$this->base}/{$conversation->uuid}/stop")
        ->assertOk()
        ->assertJsonPath('status', 'idle')
        ->assertJsonPath('messages.1.tool_calls.0.status', 'failed')
        ->assertJsonPath('messages.1.tool_calls.0.result_preview', ToolExecutor::STOPPED)
        ->assertJsonPath('messages.2.role', 'assistant')
        ->assertJsonPath('messages.2.notice', 'stopped')
        ->assertJsonPath('messages.2.content', 'Stopped by you.');

    expect($this->tools['confirm']->calls)->toBe([]);
    Http::assertSentCount(1);
    $this->assertActivityFor('server:assistant.stop', $this->user, $this->server);

    // The call can no longer be allowed, and stopping again changes nothing.
    $call = AiToolCall::query()->sole();
    $this->actingAs($this->user)->postJson("{$this->base}/{$conversation->uuid}/tool-calls/{$call->id}/approve")->assertStatus(409);
    $this->actingAs($this->user)->postJson("{$this->base}/{$conversation->uuid}/stop")->assertOk()->assertJsonCount(3, 'messages');
    expect(AiMessage::query()->where('notice', AiMessage::NOTICE_STOPPED)->count())->toBe(1);
    expect($this->tools['confirm']->calls)->toBe([]);
});

test('the model is told about the stop on the next message', function (): void {
    Http::fakeSequence(FakeAi::URL)
        ->push(FakeAi::toolCall('pretend_delete', ['path' => 'a.txt']))
        ->push(FakeAi::text('Okay.'));

    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Delete a.txt']);
    $conversation = onlyConversation();
    $this->actingAs($this->user)->postJson("{$this->base}/{$conversation->uuid}/stop")->assertOk();

    $this->actingAs($this->user)->postJson("{$this->base}/{$conversation->uuid}/messages", ['message' => 'Never mind'])
        ->assertOk()
        ->assertJsonPath('status', 'idle');

    $messages = Http::recorded()->values()[1][0]->data()['messages'];
    expect(collect($messages)->pluck('content')->all())->toContain('[The user stopped this request here. Do not continue it unless they ask again.]', ToolExecutor::STOPPED);
});

test('a queued turn that was stopped before it started calls nothing', function (): void {
    Queue::fake();
    Http::fake();

    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Hello'])->assertJsonPath('status', 'running');
    $conversation = onlyConversation();

    $this->actingAs($this->user)->postJson("{$this->base}/{$conversation->uuid}/stop")->assertOk()->assertJsonPath('status', 'idle');

    $job = Queue::pushed(RunAssistantTurn::class)->sole();
    $job->handle(resolve(AssistantAgent::class));

    Http::assertNothingSent();
    expect(AiUsage::query()->count())->toBe(0)
        ->and($conversation->refresh()->status)->toBe(AiConversation::STATUS_IDLE);
});

test('a stop while the provider answers discards the answer and starts nothing more', function (): void {
    $calls = 0;
    Http::fake([FakeAi::URL => function () use (&$calls) {
        $calls++;
        // The user clicks Stop while this call is in flight.
        resolve(ConversationService::class)->stop(onlyConversation());

        return Http::response(FakeAi::toolCall('echo_text', ['text' => 'hi']));
    }]);

    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Echo hi'])
        ->assertOk()
        ->assertJsonPath('status', 'idle')
        ->assertJsonCount(2, 'messages')
        ->assertJsonPath('messages.1.notice', 'stopped');

    expect($calls)->toBe(1)
        ->and($this->tools['echo']->calls)->toBe([])
        ->and(AiToolCall::query()->count())->toBe(0);
    // The provider charged for the completed call, so it is billed; nothing after it is.
    expect(AiUsage::query()->pluck('ai_tokens')->all())->toBe([120]);
});

test('a stop before a pending tool keeps it from running and from calling the model again', function (): void {
    Http::fakeSequence(FakeAi::URL)
        ->push(answerWithCalls([['echo_text', ['text' => 'one']], ['echo_text', ['text' => 'two']]]))
        ->push(FakeAi::text('never'));

    // The stop arrives while the first tool runs.
    $this->tools['echo']->onRun = function (): void {
        resolve(ConversationService::class)->stop(onlyConversation());
    };

    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Echo twice'])
        ->assertOk()
        ->assertJsonPath('status', 'idle')
        ->assertJsonPath('messages.1.tool_calls.0.status', 'done')
        ->assertJsonPath('messages.1.tool_calls.1.status', 'failed')
        ->assertJsonPath('messages.1.tool_calls.1.result_preview', ToolExecutor::STOPPED);

    expect($this->tools['echo']->calls)->toHaveCount(1);
    Http::assertSentCount(1);
    expect(AiUsage::query()->count())->toBe(1);
});

test('stopping a finished conversation changes nothing', function (): void {
    Http::fakeSequence(FakeAi::URL)->push(FakeAi::text('Hi.'));

    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Hello'])->assertJsonPath('status', 'idle');
    $conversation = onlyConversation();

    $this->actingAs($this->user)->postJson("{$this->base}/{$conversation->uuid}/stop")
        ->assertOk()
        ->assertJsonPath('status', 'idle')
        ->assertJsonCount(2, 'messages');

    expect(ActivityLog::query()->where('event', 'server:assistant.stop')->exists())->toBeFalse();
});

test('another user cannot stop a conversation', function (): void {
    Http::fakeSequence(FakeAi::URL)->push(FakeAi::toolCall('pretend_delete', ['path' => 'a.txt']));

    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Delete a.txt']);
    $conversation = onlyConversation();
    [$other] = $this->generateTestAccount();

    $this->actingAs($other)->postJson("{$this->base}/{$conversation->uuid}/stop")->assertNotFound();
    expect($conversation->refresh()->status)->toBe(AiConversation::STATUS_AWAITING_APPROVAL);
});

// --- Pause ----------------------------------------------------------------------------------

test('a paused assistant starts and continues nothing until it is resumed', function (): void {
    Http::fakeSequence(FakeAi::URL)
        ->push(FakeAi::toolCall('pretend_delete', ['path' => 'a.txt']))
        ->push(FakeAi::text('Hello again.'));

    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Delete a.txt'])->assertJsonPath('status', 'awaiting_approval');
    $conversation = onlyConversation();

    // Pausing stops the waiting turn too.
    $this->actingAs($this->user)->putJson('/api/client/assistant/pause', ['paused' => true])->assertOk()->assertJsonPath('paused', true);
    expect($conversation->refresh()->status)->toBe(AiConversation::STATUS_IDLE)
        ->and(AiUserSetting::paused($this->user))->toBeTrue();
    $this->actingAs($this->user)->getJson('/api/client/assistant')->assertJsonPath('paused', true);

    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Hi'])->assertForbidden()->assertJsonPath('errors.0.detail', ConversationService::PAUSED);
    $this->actingAs($this->user)->postJson("{$this->base}/{$conversation->uuid}/messages", ['message' => 'Hi'])->assertForbidden();
    Http::assertSentCount(1);

    $this->actingAs($this->user)->putJson('/api/client/assistant/pause', ['paused' => false])->assertOk()->assertJsonPath('paused', false);
    $this->actingAs($this->user)->postJson("{$this->base}/{$conversation->uuid}/messages", ['message' => 'Hi'])->assertOk()->assertJsonPath('status', 'idle');
    Http::assertSentCount(2);
});

test('a turn that reaches the loop after a pause fails without calling the model', function (): void {
    Queue::fake();
    Http::fake();

    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Hello']);
    AiUserSetting::setPaused($this->user, true);

    Queue::pushed(RunAssistantTurn::class)->sole()->handle(resolve(AssistantAgent::class));

    Http::assertNothingSent();
    expect(onlyConversation()->error)->toBe(ConversationService::PAUSED);
});

test('the pause switch needs a boolean', function (): void {
    $this->actingAs($this->user)->putJson('/api/client/assistant/pause', [])->assertUnprocessable();
});

// --- Approval modes -------------------------------------------------------------------------

test('the approval mode is stored per conversation and Allow everything needs the warning confirmed', function (): void {
    Http::fakeSequence(FakeAi::URL)->push(FakeAi::text('Hi.'));
    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Hello'])
        ->assertJsonPath('approval_mode', 'ask')
        ->assertJsonPath('allow_everything_available', true);
    $conversation = onlyConversation();
    $url = "{$this->base}/{$conversation->uuid}/approval-mode";

    $this->actingAs($this->user)->putJson($url, ['mode' => 'allow_everything'])->assertUnprocessable()->assertJsonPath('errors.0.meta.source_field', 'confirmed');
    $this->actingAs($this->user)->putJson($url, ['mode' => 'allow_everything', 'confirmed' => false])->assertUnprocessable();
    $this->actingAs($this->user)->putJson($url, ['mode' => 'everything', 'confirmed' => true])->assertUnprocessable();
    expect($conversation->refresh()->approval_mode)->toBe('ask');

    $this->actingAs($this->user)->putJson($url, ['mode' => 'allow_everything', 'confirmed' => true])->assertOk()->assertJsonPath('approval_mode', 'allow_everything');
    $this->actingAs($this->user)->getJson("{$this->base}/{$conversation->uuid}")->assertJsonPath('approval_mode', 'allow_everything');
    $this->assertActivityFor('server:assistant.approval-mode', $this->user, $this->server);
    expect(ActivityLog::query()->where('event', 'server:assistant.approval-mode')->sole()->properties['mode'])->toBe('Allow everything');

    // Back to asking needs no confirmation.
    $this->actingAs($this->user)->putJson($url, ['mode' => 'ask'])->assertOk()->assertJsonPath('approval_mode', 'ask');
});

test('Allow everything can be turned off panel-wide', function (): void {
    Http::fakeSequence(FakeAi::URL)
        ->push(FakeAi::text('Hi.'))
        ->push(FakeAi::toolCall('pretend_delete', ['path' => 'a.txt']));
    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Hello']);
    $conversation = onlyConversation();
    $conversation->forceFill(['approval_mode' => AiConversation::APPROVAL_ALLOW_EVERYTHING])->save();

    config()->set('monoforge.ai.allow_everything_enabled', false);

    $this->actingAs($this->user)->putJson("{$this->base}/{$conversation->uuid}/approval-mode", ['mode' => 'allow_everything', 'confirmed' => true])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.detail', ConversationService::ALLOW_EVERYTHING_OFF);

    // A conversation switched on before asks again.
    $this->actingAs($this->user)->postJson("{$this->base}/{$conversation->uuid}/messages", ['message' => 'Delete a.txt'])
        ->assertJsonPath('status', 'awaiting_approval')
        ->assertJsonPath('allow_everything_available', false);
    expect($this->tools['confirm']->calls)->toBe([]);
});

test('Allow everything runs calls that need confirmation without a click and logs them', function (): void {
    Http::fakeSequence(FakeAi::URL)
        ->push(FakeAi::text('Hi.'))
        ->push(FakeAi::toolCall('pretend_delete', ['path' => 'old.log']))
        ->push(FakeAi::text('Deleted.'));
    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Hello']);
    $conversation = onlyConversation();
    $this->actingAs($this->user)->putJson("{$this->base}/{$conversation->uuid}/approval-mode", ['mode' => 'allow_everything', 'confirmed' => true])->assertOk();

    $this->actingAs($this->user)->postJson("{$this->base}/{$conversation->uuid}/messages", ['message' => 'Delete old.log'])
        ->assertOk()
        ->assertJsonPath('status', 'idle')
        ->assertJsonPath('messages.3.tool_calls.0.status', 'done')
        ->assertJsonPath('messages.3.tool_calls.0.needs_confirmation', true)
        ->assertJsonPath('messages.3.tool_calls.0.auto_approved', 'allow_everything');

    expect($this->tools['confirm']->calls)->toBe([['path' => 'old.log']]);
    $call = AiToolCall::query()->sole();
    expect($call->decided_by)->toBe($this->user->id)->and($call->auto_approved)->toBe(AiToolCall::AUTO_ALLOW_EVERYTHING);

    $this->assertActivityFor('server:assistant.auto-approve', $this->user, $this->server);
    $log = ActivityLog::query()->where('event', 'server:assistant.auto-approve')->sole();
    expect($log->properties['reason'])->toBe('Allow everything')->and($log->properties['label'])->toBe('Delete old.log');
});

test('Allow everything still respects the permission checked when the call runs', function (): void {
    $answers = [FakeAi::text('Hi.'), FakeAi::toolCall('pretend_delete', ['path' => 'a.txt']), FakeAi::text('I could not.')];
    $call = 0;
    Http::fake([FakeAi::URL => function () use (&$call, $answers) {
        if ($call === 1) {
            // The permission is taken away while the model answers.
            $this->tools['confirm']->allowedFor = [];
        }

        return Http::response($answers[$call++]);
    }]);
    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Hello']);
    $conversation = onlyConversation();
    $conversation->forceFill(['approval_mode' => AiConversation::APPROVAL_ALLOW_EVERYTHING])->save();

    $this->actingAs($this->user)->postJson("{$this->base}/{$conversation->uuid}/messages", ['message' => 'Delete a.txt'])
        ->assertOk()
        ->assertJsonPath('messages.3.tool_calls.0.status', 'failed');

    expect($this->tools['confirm']->calls)->toBe([]);
});

test('actions that always ask still wait for a click in Allow everything', function (): void {
    Http::fakeSequence(FakeAi::URL)
        ->push(FakeAi::text('Hi.'))
        ->push(FakeAi::toolCall('delete_files', ['root' => '/', 'files' => ['world']]));
    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Hello']);
    $conversation = onlyConversation();
    $conversation->forceFill(['approval_mode' => AiConversation::APPROVAL_ALLOW_EVERYTHING])->save();

    $this->actingAs($this->user)->postJson("{$this->base}/{$conversation->uuid}/messages", ['message' => 'Delete the world'])
        ->assertOk()
        ->assertJsonPath('status', 'awaiting_approval')
        ->assertJsonPath('messages.3.tool_calls.0.status', 'pending')
        ->assertJsonPath('messages.3.tool_calls.0.always_ask', true)
        ->assertJsonPath('messages.3.tool_calls.0.auto_approved', null);

    expect(ActivityLog::query()->where('event', 'server:assistant.auto-approve')->exists())->toBeFalse();
});

test('Allow until my next message allows the rest of the turn and ends with the next message', function (): void {
    Http::fakeSequence(FakeAi::URL)
        ->push(FakeAi::toolCall('pretend_delete', ['path' => 'one'], 'call_a'))
        ->push(FakeAi::toolCall('pretend_delete', ['path' => 'two'], 'call_b'))
        ->push(FakeAi::text('Both deleted.'))
        ->push(FakeAi::toolCall('pretend_delete', ['path' => 'three'], 'call_c'));

    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Delete one and two'])->assertJsonPath('status', 'awaiting_approval');
    $conversation = onlyConversation();
    $first = AiToolCall::query()->sole();

    $this->actingAs($this->user)->postJson("{$this->base}/{$conversation->uuid}/tool-calls/{$first->id}/approve", ['until_next_message' => true])
        ->assertOk()
        ->assertJsonPath('status', 'idle')
        ->assertJsonPath('messages.1.tool_calls.0.auto_approved', null)
        ->assertJsonPath('messages.2.tool_calls.0.status', 'done')
        ->assertJsonPath('messages.2.tool_calls.0.auto_approved', 'until_next_message')
        ->assertJsonPath('messages.3.content', 'Both deleted.');

    expect($this->tools['confirm']->calls)->toBe([['path' => 'one'], ['path' => 'two']]);
    $this->assertActivityFor('server:assistant.auto-approve', $this->user, $this->server);
    expect(ActivityLog::query()->where('event', 'server:assistant.auto-approve')->sole()->properties['reason'])->toBe('Allow until my next message');

    // A new message resets it: the next call asks again.
    $this->actingAs($this->user)->postJson("{$this->base}/{$conversation->uuid}/messages", ['message' => 'Now delete three'])
        ->assertOk()
        ->assertJsonPath('status', 'awaiting_approval')
        ->assertJsonPath('allow_until_next_message', false);

    expect($this->tools['confirm']->calls)->toHaveCount(2)
        ->and($conversation->refresh()->allow_until_message_id)->toBeNull();
});

test('Allow until my next message never allows actions that always ask', function (): void {
    Http::fakeSequence(FakeAi::URL)
        ->push(FakeAi::toolCall('pretend_delete', ['path' => 'one'], 'call_a'))
        ->push(FakeAi::toolCall('delete_files', ['files' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k']], 'call_b'));

    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Clean up']);
    $conversation = onlyConversation();
    $first = AiToolCall::query()->sole();

    $this->actingAs($this->user)->postJson("{$this->base}/{$conversation->uuid}/tool-calls/{$first->id}/approve", ['until_next_message' => true])
        ->assertOk()
        ->assertJsonPath('status', 'awaiting_approval')
        ->assertJsonPath('allow_until_next_message', true)
        ->assertJsonPath('messages.2.tool_calls.0.name', 'delete_files')
        ->assertJsonPath('messages.2.tool_calls.0.always_ask', true)
        ->assertJsonPath('messages.2.tool_calls.0.status', 'pending');
});

test('approval modes work in the admin assistant without bypassing its always-ask tools', function (): void {
    $admin = User::factory()->create(['root_admin' => true]);
    Http::fakeSequence(FakeAi::URL)
        ->push(FakeAi::text('Hi.'))
        ->push(FakeAi::toolCall('adjust_tokens', ['user' => (string) $this->user->id, 'amount' => 10, 'reason' => 'Thanks']));

    $this->actingAs($admin)->postJson('/api/admin/assistant/conversations', ['message' => 'Hello'])->assertOk();
    $conversation = onlyConversation();
    $base = "/api/admin/assistant/conversations/{$conversation->uuid}";

    $this->actingAs($admin)->putJson("{$base}/approval-mode", ['mode' => 'allow_everything'])->assertUnprocessable();
    $this->actingAs($admin)->putJson("{$base}/approval-mode", ['mode' => 'allow_everything', 'confirmed' => true])->assertOk()->assertJsonPath('approval_mode', 'allow_everything');
    $this->assertActivityLogged('admin:assistant.approval-mode');

    $this->actingAs($admin)->postJson("{$base}/messages", ['message' => 'Give 10 tokens'])
        ->assertOk()
        ->assertJsonPath('status', 'awaiting_approval')
        ->assertJsonPath('messages.3.tool_calls.0.always_ask', true);

    $this->actingAs($admin)->postJson("{$base}/stop")->assertOk()->assertJsonPath('status', 'idle');
    $this->assertActivityLogged('admin:assistant.stop');

    // Someone else's conversation does not exist for another admin.
    $other = User::factory()->create(['root_admin' => true]);
    $other->withAccessToken(new TransientToken);
    $this->actingAs($other)->postJson("{$base}/stop")->assertNotFound();
});
