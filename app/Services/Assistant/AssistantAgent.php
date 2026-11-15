<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Pterodactyl\Exceptions\Assistant\AssistantUnavailableException;
use Pterodactyl\Exceptions\Assistant\ChatProviderException;
use Pterodactyl\Jobs\Assistant\RunAssistantTurn;
use Pterodactyl\Models\Assistant\AiConversation;
use Pterodactyl\Models\Assistant\AiMessage;
use Pterodactyl\Models\Assistant\AiToolCall;
use Pterodactyl\Services\Assistant\Chat\ChatClient;
use Pterodactyl\Services\Assistant\Chat\ChatResponse;
use Pterodactyl\Services\Assistant\Chat\ChatToolCall;
use Pterodactyl\Services\Assistant\Tools\OutputRedactor;
use Throwable;

/**
 * The agent loop of one turn: run the tool calls that are ready, call the model with the
 * context and the user's tools, store its answer and usage, and repeat until the model
 * answers without tools, a call needs the user's confirmation (the turn pauses as
 * "awaiting_approval"), or `max_tool_iterations` rounds of tools were used (the model is
 * then asked for a final answer without tools).
 *
 * Only one loop works on a conversation at a time (a cache lock), and only the run whose
 * token the conversation holds: before every model call and tool run the loop checks that the
 * conversation is still "running" under its token, and stops when it is not.
 */
class AssistantAgent
{
    /** Shown to players when the provider fails; the details go to the log. */
    public const string PROVIDER_ERROR = 'The assistant could not reach its AI provider. Try again in a moment.';

    public const string CONTEXT_FULL = 'This request needs more context than the assistant allows. Start a new conversation or ask for something smaller.';

    public function __construct(
        private readonly ChatClient $client,
        private readonly ConversationContext $context,
        private readonly ToolRegistry $registry,
        private readonly ToolExecutor $executor,
        private readonly AssistantBilling $billing,
    ) {}

    public static function lockKey(int $conversationId): string
    {
        return "assistant:conversation:{$conversationId}:run";
    }

    /**
     * Runs the turn the token belongs to. Returns false when another loop still holds the
     * conversation's lock after waiting up to $waitSeconds for it.
     */
    public function run(AiConversation $conversation, string $token, int $waitSeconds = 0): bool
    {
        $lock = Cache::lock(self::lockKey($conversation->id), RunAssistantTurn::TIMEOUT + 60);

        try {
            $acquired = $waitSeconds > 0 ? $lock->block($waitSeconds) : $lock->get();
        } catch (LockTimeoutException) {
            $acquired = false;
        }

        if (! $acquired) {
            return false;
        }

        try {
            $conversation->refresh();
            if ($conversation->ownedBy($token)) {
                $this->guarded($conversation, $token);
            }
        } finally {
            $lock->release();
        }

        return true;
    }

    /**
     * Marks the run as failed, unless another run owns the conversation by now.
     */
    public function fail(AiConversation $conversation, string $message, string $token): void
    {
        $conversation->finishRun($token, AiConversation::STATUS_ERROR, $message);
    }

    private function guarded(AiConversation $conversation, string $token): void
    {
        try {
            $this->loop($conversation, $token);
        } catch (AssistantUnavailableException $exception) {
            $this->fail($conversation, $exception->getMessage(), $token);
        } catch (ChatProviderException $exception) {
            $this->failProvider($conversation, $exception, $token);
        } catch (Throwable $exception) {
            report($exception);
            $this->fail($conversation, 'The assistant ran into an unexpected error. Try again.', $token);
        }
    }

    /**
     * Provider errors can name internal addresses or accounts, so players get a general
     * message. The details are logged, and root admins see them in the admin assistant.
     */
    private function failProvider(AiConversation $conversation, ChatProviderException $exception, string $token): void
    {
        Log::warning('The AI assistant provider failed.', [
            'conversation_id' => $conversation->id,
            'provider_id' => $conversation->provider_id,
            'scope' => $conversation->scope,
            'message' => $exception->getMessage(),
        ]);

        $message = $conversation->scope === AiConversation::SCOPE_ADMIN
            ? (new OutputRedactor)->redact($exception->getMessage())
            : self::PROVIDER_ERROR;

        $this->fail($conversation, $message, $token);
    }

    private function loop(AiConversation $conversation, string $token): void
    {
        while (true) {
            throw_unless(AssistantSettings::scopeEnabled($conversation->scope), AssistantUnavailableException::class, 'The AI assistant is turned off.');

            $ready = $this->runReadyCalls($conversation, $token);
            if ($ready === null) {
                return;
            }

            if (! $ready) {
                $conversation->finishRun($token, AiConversation::STATUS_AWAITING_APPROVAL);

                return;
            }

            $final = $this->toolRounds($conversation) >= AssistantSettings::maxToolIterations();

            $this->billing->assertCanSpend($conversation->user, $conversation->scope);
            $provider = AssistantProviders::resolve($conversation);

            $tools = $final ? [] : $this->registry->for($conversation->scope, ToolExecutor::context($conversation));
            $messages = $this->context->build($conversation, $final ? 'You have used the most tool calls allowed for this request. Answer the user now with what you found, without calling tools.' : null);
            if (! $this->context->fits($messages)) {
                $this->fail($conversation, self::CONTEXT_FULL, $token);

                return;
            }

            // The last check before the provider is paid: a run that lost the turn stops here.
            if (! $conversation->touchRun($token)) {
                return;
            }

            $response = $this->client->complete($provider, $messages, ToolRegistry::definitions($tools), AssistantSettings::maxOutputTokens());
            $this->billing->record($conversation->user, $conversation, $conversation->scope, $response->usage->total());

            if (! $conversation->touchRun($token)) {
                return;
            }

            $calls = $tools === [] ? [] : $this->uniqueIds($conversation, $response->toolCalls);
            $message = $this->store($conversation, $response, $calls);

            if ($calls === []) {
                $conversation->finishRun($token, AiConversation::STATUS_IDLE);

                return;
            }

            $this->executor->record($conversation, $message, $calls);
        }
    }

    /**
     * Runs the latest assistant message's calls that need no decision (or were allowed), in
     * order. Returns false when a call waits for the user's confirmation, and null when this
     * run no longer owns the conversation.
     */
    private function runReadyCalls(AiConversation $conversation, string $token): ?bool
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

            if (! $conversation->touchRun($token)) {
                return null;
            }

            $this->executor->run($conversation, $call);
        }

        return $conversation->touchRun($token) ? true : null;
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
