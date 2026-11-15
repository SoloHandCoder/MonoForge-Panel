<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Assistant\ConversationContextTest;

use Illuminate\Support\Facades\Http;
use Pterodactyl\Models\Assistant\AiConversation;
use Pterodactyl\Models\Assistant\AiMessage;
use Pterodactyl\Models\Assistant\AiUsage;
use Pterodactyl\Services\Assistant\ConversationContext;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;
use Pterodactyl\Tests\Support\Assistant\FakeAi;

uses(ClientApiIntegrationTestCase::class);

beforeEach(function (): void {
    $this->provider = FakeAi::enable();
    [$this->user, $this->server] = $this->generateTestAccount();
    $this->conversation = AiConversation::query()->create([
        'uuid' => '9d3c1f3e-2222-4c7a-9b8e-2d5f4a1c0e93',
        'user_id' => $this->user->id,
        'scope' => AiConversation::SCOPE_SERVER,
        'server_id' => $this->server->id,
        'provider_id' => $this->provider->id,
        'title' => 'Long',
        'status' => AiConversation::STATUS_IDLE,
    ]);
});

function addMessage(AiConversation $conversation, string $role, string $content, ?array $toolCalls = null, ?string $toolCallId = null): AiMessage
{
    return AiMessage::query()->create([
        'conversation_id' => $conversation->id,
        'role' => $role,
        'content' => $content,
        'tool_calls' => $toolCalls,
        'tool_call_id' => $toolCallId,
    ]);
}

test('a short history is sent as it is', function (): void {
    addMessage($this->conversation, 'user', 'Hello');
    Http::fake();

    $messages = resolve(ConversationContext::class)->build($this->conversation);

    expect($messages)->toHaveCount(2)
        ->and($messages[0]['role'])->toBe('system')
        ->and($messages[0]['content'])->toContain('Never reveal secrets')
        ->and($messages[0]['content'])->toContain($this->server->name)
        ->and($messages[1])->toBe(['role' => 'user', 'content' => 'Hello']);
    Http::assertNothingSent();
});

test('a history over context_tokens is summarised, keeping the recent turns', function (): void {
    config()->set('monoforge.ai.context_tokens', 2000);
    // Four old turns of about 600 tokens each, then the current question.
    foreach (range(1, 4) as $turn) {
        addMessage($this->conversation, 'user', "Question {$turn} ".str_repeat('q', 1200));
        addMessage($this->conversation, 'assistant', "Answer {$turn} ".str_repeat('a', 1200));
    }

    $boundary = AiMessage::query()->max('id');
    addMessage($this->conversation, 'user', 'What now?');
    Http::fakeSequence(FakeAi::URL)->push(FakeAi::text('SUMMARY: the user asked four questions.', 900, 30));

    $messages = resolve(ConversationContext::class)->build($this->conversation);

    Http::assertSentCount(1);
    $sent = Http::recorded()->values()[0][0]->data();
    expect($sent)->not->toHaveKey('tools')
        ->and($sent['messages'][1]['content'])->toContain('Question 1');

    $this->conversation->refresh();
    expect($this->conversation->summary)->toBe('SUMMARY: the user asked four questions.')
        ->and($this->conversation->summarized_until_message_id)->toBeLessThanOrEqual((int) $boundary)
        ->and($messages[0]['content'])->toContain('SUMMARY: the user asked four questions.')
        ->and(collect($messages)->pluck('content')->implode(' '))->not->toContain('Question 1')
        ->and(end($messages))->toBe(['role' => 'user', 'content' => 'What now?']);

    // The summary call is billed like any other.
    expect(AiUsage::query()->sole()->ai_tokens)->toBe(930);
});

test('a tool call stays together with its result when the history is cut', function (): void {
    config()->set('monoforge.ai.context_tokens', 2000);
    addMessage($this->conversation, 'user', 'Old '.str_repeat('o', 4000));
    addMessage($this->conversation, 'assistant', 'Old answer');
    addMessage($this->conversation, 'user', 'Read it');
    addMessage($this->conversation, 'assistant', '', [['id' => 'call_r', 'type' => 'function', 'function' => ['name' => 'read_file', 'arguments' => '{}']]]);
    addMessage($this->conversation, 'tool', str_repeat('r', 10000), toolCallId: 'call_r');
    Http::fakeSequence(FakeAi::URL)->push(FakeAi::text('SUMMARY'));

    $messages = resolve(ConversationContext::class)->build($this->conversation);

    $roles = collect($messages)->pluck('role')->all();
    expect($roles)->toBe(['system', 'user', 'assistant', 'tool'])
        ->and($messages[3]['tool_call_id'])->toBe('call_r')
        // Tool results are cut to 4 KB in the context.
        ->and(mb_strlen($messages[3]['content'], '8bit'))->toBeLessThanOrEqual(ConversationContext::TOOL_RESULT_BYTES)
        ->and($messages[3]['content'])->toContain('truncated');
});

test('a tool call that never got a result gets a placeholder so the provider accepts the history', function (): void {
    addMessage($this->conversation, 'user', 'Hi');
    addMessage($this->conversation, 'assistant', '', [['id' => 'call_lost', 'type' => 'function', 'function' => ['name' => 'x', 'arguments' => '{}']]]);
    addMessage($this->conversation, 'user', 'Still there?');

    $messages = resolve(ConversationContext::class)->build($this->conversation);

    expect($messages[3])->toBe(['role' => 'tool', 'tool_call_id' => 'call_lost', 'content' => 'This tool call did not run.']);
});
