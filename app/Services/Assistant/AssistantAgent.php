<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant;

use Illuminate\Support\Str;
use Pterodactyl\Exceptions\Assistant\AssistantUnavailableException;
use Pterodactyl\Exceptions\Assistant\ChatProviderException;
use Pterodactyl\Models\Assistant\AiConversation;
use Pterodactyl\Models\Assistant\AiMessage;
use Pterodactyl\Models\Assistant\AiToolCall;
use Pterodactyl\Services\Assistant\Chat\ChatClient;
use Pterodactyl\Services\Assistant\Chat\ChatResponse;
use Pterodactyl\Services\Assistant\Chat\ChatToolCall;
use Throwable;

/**
 * The agent loop of one turn: run the tool calls that are ready, call the model with the
 * context and the user's tools, store its answer and usage, and repeat until the model
 * answers without tools, a call needs the user's confirmation (the turn pauses as
 * "awaiting_approval"), or `max_tool_iterations` rounds of tools were used (the model is
 * then asked for a final answer without tools).
 */
class AssistantAgent
{
    public function __construct(
        private readonly ChatClient $client,
        private readonly ConversationContext $context,
        private readonly ToolRegistry $registry,
        private readonly ToolExecutor $executor,
        private readonly AssistantBilling $billing,
    ) {}

    public function run(AiConversation $conversation): void
    {
        $conversation->refresh();
        if ($conversation->status !== AiConversation::STATUS_RUNNING) {
            return;
        }

        try {
            $this->loop($conversation);
        } catch (AssistantUnavailableException|ChatProviderException $exception) {
            $this->fail($conversation, $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);
            $this->fail($conversation, 'The assistant ran into an unexpected error. Try again.');
        }
    }

    public function fail(AiConversation $conversation, string $message): void
    {
        $conversation->transition([AiConversation::STATUS_RUNNING], AiConversation::STATUS_ERROR, $message);
    }

    private function loop(AiConversation $conversation): void
    {
        while (true) {
            throw_unless(AssistantSettings::scopeEnabled($conversation->scope), AssistantUnavailableException::class, 'The AI assistant is turned off.');

            if (! $this->runReadyCalls($conversation)) {
                $conversation->transition([AiConversation::STATUS_RUNNING], AiConversation::STATUS_AWAITING_APPROVAL);

                return;
            }

            $final = $this->toolRounds($conversation) >= AssistantSettings::maxToolIterations();

            $this->billing->assertCanSpend($conversation->user, $conversation->scope);
            $provider = AssistantProviders::resolve($conversation);
            $conversation->touch();

            $tools = $final ? [] : $this->registry->for($conversation->scope, ToolExecutor::context($conversation));
            $messages = $this->context->build($conversation, $final ? 'You have used the most tool calls allowed for this request. Answer the user now with what you found, without calling tools.' : null);

            $response = $this->client->complete($provider, $messages, ToolRegistry::definitions($tools), AssistantSettings::maxOutputTokens());
            $calls = $tools === [] ? [] : $this->uniqueIds($conversation, $response->toolCalls);

            $message = $this->store($conversation, $response, $calls);
            $this->billing->record($conversation->user, $conversation, $conversation->scope, $response->usage->total());

            if ($calls === []) {
                $conversation->transition([AiConversation::STATUS_RUNNING], AiConversation::STATUS_IDLE);

                return;
            }

            $this->executor->record($conversation, $message, $calls);
        }
    }

    /**
     * Runs the latest assistant message's calls that need no decision (or were allowed), in
     * order. Returns false when a call waits for the user's confirmation.
     */
    private function runReadyCalls(AiConversation $conversation): bool
    {
        $latest = $conversation->messages()->where('role', AiMessage::ROLE_ASSISTANT)->latest('id')->first();
        if (! $latest instanceof AiMessage) {
            return true;
        }

        $calls = $latest->calls()
            ->whereIn('status', [AiToolCall::STATUS_PENDING, AiToolCall::STATUS_APPROVED])
            ->orderBy('id')
            ->get();

        foreach ($calls as $call) {
            if ($call->status === AiToolCall::STATUS_PENDING && $call->needs_confirmation) {
                return false;
            }

            $this->executor->run($conversation, $call);
            $conversation->touch();
        }

        return true;
    }

    /** How many times the model called tools since the user's last message. */
    private function toolRounds(AiConversation $conversation): int
    {
        $lastUser = $conversation->messages()->where('role', AiMessage::ROLE_USER)->max('id');

        $query = $conversation->messages()->where('role', AiMessage::ROLE_ASSISTANT)->whereNotNull('tool_calls');
        if (is_numeric($lastUser)) {
            $query->where('id', '>', $lastUser);
        }

        return $query->count();
    }

    /**
     * @param  list<ChatToolCall>  $calls
     */
    private function store(AiConversation $conversation, ChatResponse $response, array $calls): AiMessage
    {
        $content = $response->content;
        if ($calls === [] && ($content === null || mb_trim($content) === '')) {
            $content = '(The model returned an empty answer.)';
        }

        return AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => AiMessage::ROLE_ASSISTANT,
            'content' => $content,
            'tool_calls' => $calls === [] ? null : array_map(fn (ChatToolCall $call): array => [
                'id' => $call->id,
                'type' => 'function',
                'function' => ['name' => $call->name, 'arguments' => $call->rawArguments],
            ], $calls),
            'prompt_tokens' => $response->usage->input,
            'completion_tokens' => $response->usage->output,
        ]);
    }

    /**
     * Some providers number their calls "call_0", "call_1" in every answer; an id seen before
     * in the conversation (or twice in one answer) is replaced so results stay matched.
     *
     * @param  list<ChatToolCall>  $calls
     * @return list<ChatToolCall>
     */
    private function uniqueIds(AiConversation $conversation, array $calls): array
    {
        $used = AiToolCall::query()->where('conversation_id', $conversation->id)->pluck('call_id')->flip()->all();
        $result = [];

        foreach ($calls as $call) {
            $id = mb_substr($call->id, 0, 191);
            if (isset($used[$id])) {
                $id = 'call_'.Str::random(24);
            }

            $used[$id] = true;
            $result[] = new ChatToolCall($id, $call->name, $call->arguments, $call->rawArguments);
        }

        return $result;
    }
}
