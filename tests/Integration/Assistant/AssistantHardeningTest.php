<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Assistant\AssistantHardeningTest;

use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Pterodactyl\Jobs\Assistant\RunAssistantTurn;
use Pterodactyl\Models\ActivityLog;
use Pterodactyl\Models\Assistant\AiConversation;
use Pterodactyl\Models\Assistant\AiMessage;
use Pterodactyl\Models\Assistant\AiToolCall;
use Pterodactyl\Services\Assistant\AssistantAgent;
use Pterodactyl\Services\Assistant\ConversationContext;
use Pterodactyl\Services\Assistant\ConversationPresenter;
use Pterodactyl\Services\Assistant\ConversationService;
use Pterodactyl\Services\Assistant\ToolExecutor;
use Pterodactyl\Services\Assistant\Tools\SecretRedactor;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;
use Pterodactyl\Tests\Support\Assistant\FakeAi;
use Pterodactyl\Tests\Support\Fakes\FakeDaemonFile;

uses(ClientApiIntegrationTestCase::class);

beforeEach(function (): void {
    FakeAi::enable();
    $this->tools = FakeAi::tools();
    [$this->user, $this->server] = $this->generateTestAccount();
    $this->base = "/api/client/servers/{$this->server->uuid}/assistant/conversations";
});

/**
 * An answer with several tool calls.
 *
 * @param  list<array{string, array<string, mixed>}>  $calls
 * @return array<string, mixed>
 */
function manyCalls(array $calls): array
{
    $answer = FakeAi::toolCall('echo_text', []);
    $answer['choices'][0]['message']['tool_calls'] = array_map(fn (array $call, int $i): array => [
        'id' => "call_{$i}",
        'type' => 'function',
        'function' => ['name' => $call[0], 'arguments' => json_encode($call[1])],
    ], $calls, array_keys($calls));

    return $answer;
}

/** A conversation of the test user in the given state. */
function conversation(object $test, string $status = AiConversation::STATUS_IDLE): AiConversation
{
    return AiConversation::query()->create([
        'uuid' => fake()->uuid(),
        'user_id' => $test->user->id,
        'scope' => AiConversation::SCOPE_SERVER,
        'server_id' => $test->server->id,
        'title' => 'Test',
        'status' => $status,
    ]);
}

test('H1: the card of delete_files shows the resolved path that runs, not the padded one the model wrote', function (): void {
    $files = new FakeDaemonFile;
    $sneaky = 'logs/'.str_repeat('a', 70).'/../../world';
    Http::fakeSequence(FakeAi::URL)
        ->push(FakeAi::toolCall('delete_files', ['files' => [$sneaky]]))
        ->push(FakeAi::text('Deleted.'));

    $response = $this->actingAs($this->user)->postJson($this->base, ['message' => 'Clean up the logs'])->assertOk();

    $response->assertJsonPath('status', 'awaiting_approval')
        ->assertJsonPath('messages.1.tool_calls.0.label', 'Delete /world')
        ->assertJsonPath('messages.1.tool_calls.0.arguments', ['root' => '/', 'files' => ['world']])
        ->assertJsonPath('messages.1.tool_calls.0.details', [['label' => 'Path', 'value' => '/world']]);

    $uuid = $response->json('uuid');
    $call = $response->json('messages.1.tool_calls.0.id');
    $this->actingAs($this->user)->postJson("{$this->base}/{$uuid}/tool-calls/{$call}/approve")->assertOk();

    // What ran is exactly what the card showed.
    $files->assertFilesDeleted('/', ['world']);
});

test('H1: arguments that can never run fail when recorded instead of reaching the card', function (): void {
    new FakeDaemonFile;
    Http::fakeSequence(FakeAi::URL)
        ->push(FakeAi::toolCall('delete_files', ['files' => array_map(fn (int $i): string => "f{$i}", range(1, 51))]))
        ->push(FakeAi::text('Too many.'));

    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Delete everything'])
        ->assertOk()
        ->assertJsonPath('status', 'idle')
        ->assertJsonPath('messages.1.tool_calls.0.status', 'failed');

    expect(AiToolCall::query()->sole()->result)->toContain('at most 50');
});

test('M3: at most 8 tool calls of one answer run, the rest fail with an error result', function (): void {
    Http::fakeSequence(FakeAi::URL)
        ->push(manyCalls(array_map(fn (int $i): array => ['echo_text', ['text' => "t{$i}"]], range(1, 10))))
        ->push(FakeAi::text('Done.'));

    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Go'])->assertOk()->assertJsonPath('status', 'idle');

    expect($this->tools['echo']->calls)->toHaveCount(ToolExecutor::MAX_CALLS_PER_STEP);
    $failed = AiToolCall::query()->where('status', AiToolCall::STATUS_FAILED)->get();
    expect($failed)->toHaveCount(2)
        ->and($failed->pluck('result')->unique()->all())->toBe([ToolExecutor::TOO_MANY_CALLS]);

    // The model is told about each of them.
    $last = Http::recorded()->values()[1][0]->data()['messages'];
    expect(collect($last)->where('role', 'tool')->where('content', ToolExecutor::TOO_MANY_CALLS))->toHaveCount(2);
});

test('M3: inside one long turn older tool results are left out to fit the context, and a turn that cannot fit stops', function (): void {
    config()->set('monoforge.ai.context_tokens', 2000);
    $big = str_repeat('x', 2500);
    Http::fakeSequence(FakeAi::URL)
        ->push(FakeAi::toolCall('echo_text', ['text' => $big], 'call_a'))
        ->push(FakeAi::toolCall('echo_text', ['text' => $big.'b'], 'call_b'))
        ->push(FakeAi::text('Done.'))
        // The next turn: the earlier one is summarised first, then a result too large to fit.
        ->push(FakeAi::text('Summary: the user echoed text.'))
        ->push(FakeAi::toolCall('echo_text', ['text' => str_repeat('y', 9000)], 'call_c'));

    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Go'])->assertOk()->assertJsonPath('status', 'idle');

    $third = Http::recorded()->values()[2][0]->data()['messages'];
    $results = collect($third)->where('role', 'tool')->pluck('content', 'tool_call_id');
    expect($results['call_a'])->toBe(ConversationContext::OMITTED)
        ->and($results['call_b'])->toBe('echo: '.$big.'b')
        ->and(ConversationContext::estimateMessages($third))->toBeLessThanOrEqual(2000);

    // A newest result that alone does not fit ends the turn before the provider is paid again.
    $uuid = AiConversation::query()->sole()->uuid;
    $this->actingAs($this->user)->postJson("{$this->base}/{$uuid}/messages", ['message' => 'Again'])
        ->assertOk()
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('error', AssistantAgent::CONTEXT_FULL);
    Http::assertSentCount(5);
});

test('M2: assistant turns run on their own queue connection whose retry_after is above the job timeout', function (): void {
    Queue::fake();

    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Hi'])->assertOk()->assertJsonPath('status', 'running');

    $conversation = AiConversation::query()->sole();
    Queue::assertPushed(RunAssistantTurn::class, fn (RunAssistantTurn $job): bool => $job->connection === 'assistant'
        && $job->queue === 'assistant'
        && $job->runToken === $conversation->run_token);

    $job = new RunAssistantTurn($conversation->id, 'token');
    expect(config('queue.connections.assistant.retry_after'))->toBeGreaterThan($job->timeout)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->maxExceptions)->toBe(1)
        ->and(config('queue.connections.assistant.queue'))->toBe('assistant');
});

test('M2: a loop whose run token was replaced stops before calling the model or running tools', function (): void {
    Queue::fake();
    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Hi'])->assertOk();
    $conversation = AiConversation::query()->sole();
    $old = (string) $conversation->run_token;

    // The turn was declared stale and restarted meanwhile: the old run has nothing left to do.
    $conversation->forceFill(['run_token' => 'newer-run'])->save();
    Http::fake();

    expect(resolve(AssistantAgent::class)->run($conversation, $old))->toBeTrue();
    Http::assertNothingSent();
    expect($conversation->refresh()->status)->toBe(AiConversation::STATUS_RUNNING);
});

test('M2: a run that loses the turn while the model answers stores nothing and runs no tools', function (): void {
    Queue::fake();
    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Hi'])->assertOk();
    $conversation = AiConversation::query()->sole();
    $token = (string) $conversation->run_token;

    Http::fake([FakeAi::URL => function () use ($conversation): PromiseInterface {
        // Meanwhile the stale check moved the turn to error and the user sent a new message.
        DB::table('ai_conversations')->where('id', $conversation->id)->update(['run_token' => 'newer-run']);

        return Http::response(FakeAi::toolCall('echo_text', ['text' => 'hi']));
    }]);

    resolve(AssistantAgent::class)->run($conversation, $token);

    Http::assertSentCount(1);
    expect($this->tools['echo']->calls)->toBe([])
        ->and(AiMessage::query()->where('role', AiMessage::ROLE_ASSISTANT)->count())->toBe(0)
        ->and(AiToolCall::query()->count())->toBe(0);
});

test('M2: a second loop cannot run on a conversation whose lock is held', function (): void {
    Queue::fake();
    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Hi'])->assertOk();
    $conversation = AiConversation::query()->sole();
    Http::fake();

    $lock = Cache::lock(AssistantAgent::lockKey($conversation->id), 60);
    expect($lock->get())->toBeTrue()
        ->and(resolve(AssistantAgent::class)->run($conversation, (string) $conversation->run_token))->toBeFalse();
    Http::assertNothingSent();
    $lock->release();
});

test('M4: a user has at most one running conversation at a time', function (): void {
    Queue::fake();
    $this->actingAs($this->user)->postJson($this->base, ['message' => 'First'])->assertOk()->assertJsonPath('status', 'running');

    $this->actingAs($this->user)->postJson($this->base, ['message' => 'Second'])
        ->assertStatus(409)
        ->assertJsonPath('errors.0.detail', ConversationService::USER_BUSY);

    $idle = conversation($this);
    $this->actingAs($this->user)->postJson("{$this->base}/{$idle->uuid}/messages", ['message' => 'Third'])->assertStatus(409);
    expect(AiConversation::query()->count())->toBe(2);

    // Another user is not affected, and a stale running conversation does not block.
    [$other, $server] = $this->generateTestAccount();
    $this->actingAs($other)->postJson("/api/client/servers/{$server->uuid}/assistant/conversations", ['message' => 'Mine'])->assertOk();

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(AiConversation::STALE_AFTER_SECONDS + 1));
    $this->actingAs($this->user)->postJson("{$this->base}/{$idle->uuid}/messages", ['message' => 'Now'])->assertOk()->assertJsonPath('status', 'running');
});

test('L6: a pending call older than 30 minutes expires and cannot be allowed', function (): void {
    Http::fakeSequence(FakeAi::URL)
        ->push(FakeAi::toolCall('pretend_delete', ['path' => 'old.txt']))
        ->push(FakeAi::text('It expired, so I did nothing.'));

    $response = $this->actingAs($this->user)->postJson($this->base, ['message' => 'Delete it'])->assertOk()->assertJsonPath('status', 'awaiting_approval');
    $uuid = $response->json('uuid');
    $call = $response->json('messages.1.tool_calls.0.id');

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(AiToolCall::EXPIRES_AFTER_MINUTES + 1));
    $this->actingAs($this->user)->postJson("{$this->base}/{$uuid}/tool-calls/{$call}/approve")->assertStatus(409);

    expect($this->tools['confirm']->calls)->toBe([])
        ->and(AiToolCall::query()->findOrFail($call)->status)->toBe(AiToolCall::STATUS_FAILED)
        ->and(AiToolCall::query()->findOrFail($call)->result)->toBe(ToolExecutor::EXPIRED);

    // The model is told and the turn finishes.
    $this->actingAs($this->user)->getJson("{$this->base}/{$uuid}")->assertOk()->assertJsonPath('status', 'idle');
});

test('L6: only a call of the message that paused the turn can be decided', function (): void {
    $conversation = conversation($this, AiConversation::STATUS_AWAITING_APPROVAL);
    $older = AiMessage::query()->create(['conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => null]);
    $orphan = AiToolCall::query()->create(['conversation_id' => $conversation->id, 'message_id' => $older->id, 'call_id' => 'c1', 'name' => 'pretend_delete', 'arguments' => ['path' => 'x'], 'needs_confirmation' => true]);
    AiMessage::query()->create(['conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => 'later']);

    $this->actingAs($this->user)->postJson("{$this->base}/{$conversation->uuid}/tool-calls/{$orphan->id}/approve")->assertStatus(409);
    expect($this->tools['confirm']->calls)->toBe([])
        ->and($orphan->refresh()->status)->toBe(AiToolCall::STATUS_PENDING);
});

test('M1: an allowed sensitive read is shown in full to the user, masked in the history, and encrypted at rest', function (): void {
    $files = new FakeDaemonFile;
    $files->directory = [['name' => '.env', 'file' => true, 'symlink' => false, 'size' => 40]];
    $files->content = "APP_NAME=Bot\nDISCORD_TOKEN=abc.def.secret\n";
    Http::fakeSequence(FakeAi::URL)
        ->push(FakeAi::toolCall('read_file', ['path' => '/.env']))
        ->push(FakeAi::text('Your bot is named Bot.'))
        ->push(FakeAi::text('Still here.'));

    $response = $this->actingAs($this->user)->postJson($this->base, ['message' => 'Check my .env'])->assertOk()->assertJsonPath('status', 'awaiting_approval');
    $uuid = $response->json('uuid');
    $call = $response->json('messages.1.tool_calls.0.id');

    $approved = $this->actingAs($this->user)->postJson("{$this->base}/{$uuid}/tool-calls/{$call}/approve")->assertOk();
    expect($approved->json('messages.1.tool_calls.0.result_preview'))->toContain('DISCORD_TOKEN=abc.def.secret');

    // The model never got the value, neither now nor in a later turn.
    $this->actingAs($this->user)->postJson("{$this->base}/{$uuid}/messages", ['message' => 'More?'])->assertOk();
    foreach (Http::recorded() as [$request]) {
        /** @var Request $request */
        expect(json_encode($request->data()))->not->toContain('abc.def.secret');
    }

    expect(AiMessage::query()->where('role', 'tool')->sole()->content)->toContain('DISCORD_TOKEN='.SecretRedactor::PLACEHOLDER)->not->toContain('abc.def.secret')
        ->and((string) DB::table('ai_tool_calls')->where('id', $call)->value('private_result'))->not->toContain('abc.def.secret')
        ->and((string) DB::table('ai_tool_calls')->where('id', $call)->value('result'))->not->toContain('abc.def.secret');
});

test('L5: tool labels are logged with secrets and addresses masked', function (): void {
    $call = new AiToolCall(['name' => 'update_startup_variable', 'label' => 'Set RCON_PASSWORD to "hunter2"']);
    $command = new AiToolCall(['name' => 'send_command', 'label' => 'Run the command: say hi 203.0.113.9 ghp_abcdefghijklmnop1234567890XYZ']);

    expect(ConversationPresenter::activityLabel($call))->toBe('Set RCON_PASSWORD to "'.SecretRedactor::PLACEHOLDER.'"')
        ->and(ConversationPresenter::activityLabel($command))->toBe('Run the command: say hi [hidden-ip] '.SecretRedactor::PLACEHOLDER);
});

test('L5: the activity of an allowed call keeps the tool name and a masked label', function (): void {
    $conversation = conversation($this, AiConversation::STATUS_AWAITING_APPROVAL);
    $message = AiMessage::query()->create(['conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => null]);
    $call = AiToolCall::query()->create(['conversation_id' => $conversation->id, 'message_id' => $message->id, 'call_id' => 'c1', 'name' => 'pretend_delete', 'label' => 'Delete token=ghp_abcdefghijklmnop1234567890XYZ', 'arguments' => ['path' => 'x'], 'needs_confirmation' => true]);
    Http::fakeSequence(FakeAi::URL)->push(FakeAi::text('ok'));

    $this->actingAs($this->user)->postJson("{$this->base}/{$conversation->uuid}/tool-calls/{$call->id}/approve")->assertOk();

    $log = ActivityLog::query()->where('event', 'server:assistant.tool')->sole();
    expect($log->properties->get('tool'))->toBe('pretend_delete')
        ->and($log->properties->get('label'))->toBeString()->not->toContain('ghp_abcdefghijklmnop1234567890XYZ');
});
