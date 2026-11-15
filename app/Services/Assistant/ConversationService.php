<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pterodactyl\Exceptions\Assistant\AssistantUnavailableException;
use Pterodactyl\Jobs\Assistant\RunAssistantTurn;
use Pterodactyl\Models\Assistant\AiConversation;
use Pterodactyl\Models\Assistant\AiMessage;
use Pterodactyl\Models\Assistant\AiToolCall;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * What the chat endpoints do: start a conversation, add a message, allow or deny a tool
 * call. Each of them queues the agent loop; the browser polls the conversation meanwhile.
 *
 * A user has at most one conversation the assistant is working on ("running") at a time,
 * across both assistants: starting or continuing another one meanwhile gives 409.
 */
class ConversationService
{
    public const string BUSY = 'The assistant is still working on this conversation.';

    public const string USER_BUSY = 'The assistant is already working on another of your conversations. Wait for it to finish, then try again.';

    public function __construct(
        private readonly AssistantBilling $billing,
        private readonly ToolExecutor $executor,
    ) {}

    /**
     * Starts a conversation with the user's message. $prepare runs after the message is stored
     * and before the turn is queued: the crash doctor records the calls that pre-load the logs.
     *
     * @param  (Closure(AiConversation): void)|null  $prepare
     *
     * @throws AssistantUnavailableException
     * @throws ConflictHttpException while another conversation of the user is running
     */
    public function start(
        User $user,
        string $scope,
        ?Server $server,
        string $message,
        string $mode = AiConversation::MODE_CHAT,
        ?string $title = null,
        ?Closure $prepare = null,
    ): AiConversation {
        $this->assertAvailable($user, $scope);
        $provider = AssistantProviders::resolve();

        [$conversation, $token] = $this->withUserSlot($user, null, function () use ($user, $scope, $server, $provider, $message, $mode, $title): array {
            $conversation = AiConversation::query()->create([
                'uuid' => Str::uuid()->toString(),
                'user_id' => $user->id,
                'scope' => $scope,
                'mode' => $mode,
                'server_id' => $scope === AiConversation::SCOPE_SERVER ? $server?->id : null,
                'provider_id' => $provider->id,
                'title' => $title ?? $this->title($message),
                'status' => AiConversation::STATUS_IDLE,
            ]);

            return [$conversation, (string) $conversation->startRun([AiConversation::STATUS_IDLE])];
        });

        $this->addUserMessage($conversation, $message, $token, $prepare);

        return $conversation->refresh();
    }

    /**
     * @throws AssistantUnavailableException
     * @throws ConflictHttpException while a turn is running or waiting for a decision, or
     *                               another conversation of the user is running
     */
    public function send(AiConversation $conversation, string $message): AiConversation
    {
        $conversation->recoverIfStale();
        throw_if($conversation->isBusy(), ConflictHttpException::class, self::BUSY);

        $this->assertAvailable($conversation->user, $conversation->scope);

        $token = $this->withUserSlot($conversation->user, $conversation, fn (): ?string => $conversation->startRun([AiConversation::STATUS_IDLE, AiConversation::STATUS_ERROR]));
        throw_if($token === null, ConflictHttpException::class, self::BUSY);

        $this->addUserMessage($conversation, $message, $token);

        return $conversation->refresh();
    }

    /**
     * Runs the allowed call now, then lets the loop continue. A call that waited longer than
     * AiToolCall::EXPIRES_AFTER_MINUTES can no longer be allowed: it fails, the loop continues
     * so the model is told, and the request gets 409.
     *
     * @throws ConflictHttpException when the call is not waiting for a decision
     */
    public function approve(AiConversation $conversation, AiToolCall $call, User $user): AiConversation
    {
        $this->assertAwaiting($conversation, $call);

        $expired = $call->isExpired();
        $token = $this->withUserSlot($conversation->user, $conversation, function () use ($conversation, $call, $user, $expired): ?string {
            $decided = $expired ? $this->executor->expire($call) : $this->executor->approve($call, $user);

            return $decided ? $conversation->startRun([AiConversation::STATUS_AWAITING_APPROVAL]) : null;
        });

        if ($expired) {
            if ($token !== null) {
                dispatch(new RunAssistantTurn($conversation->id, $token));
            }

            throw new ConflictHttpException('This action waited too long and expired. Ask the assistant again if it is still needed.');
        }

        throw_if($token === null, ConflictHttpException::class, 'This action is not waiting for a decision.');

        $this->executor->run($conversation, $call);
        dispatch(new RunAssistantTurn($conversation->id, $token));

        return $conversation->refresh();
    }

    /**
     * Tells the model the user said no, then lets the loop continue.
     *
     * @throws ConflictHttpException when the call is not waiting for a decision
     */
    public function deny(AiConversation $conversation, AiToolCall $call, User $user): AiConversation
    {
        $this->assertAwaiting($conversation, $call);

        $token = $this->withUserSlot($conversation->user, $conversation, fn (): ?string => $this->executor->deny($call, $user)
            ? $conversation->startRun([AiConversation::STATUS_AWAITING_APPROVAL])
            : null);
        throw_if($token === null, ConflictHttpException::class, 'This action is not waiting for a decision.');

        dispatch(new RunAssistantTurn($conversation->id, $token));

        return $conversation->refresh();
    }

    /**
     * @throws AssistantUnavailableException
     */
    public function assertAvailable(User $user, string $scope): void
    {
        throw_unless(AssistantSettings::scopeEnabled($scope), AssistantUnavailableException::class, 'The AI assistant is turned off.');

        $this->billing->assertCanSpend($user, $scope);
    }

    /**
     * Runs $callback while holding the user's row lock, after making sure no other
     * conversation of the user is running, so two requests can never both start one.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws ConflictHttpException
     */
    private function withUserSlot(User $user, ?AiConversation $except, callable $callback): mixed
    {
        return DB::transaction(function () use ($user, $except, $callback): mixed {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $running = AiConversation::query()
                ->where('user_id', $user->id)
                ->where('status', AiConversation::STATUS_RUNNING)
                ->whereKeyNot($except->id ?? 0)
                ->get();

            foreach ($running as $other) {
                $other->recoverIfStale();
                throw_if($other->status === AiConversation::STATUS_RUNNING, ConflictHttpException::class, self::USER_BUSY);
            }

            return $callback();
        });
    }

    private function title(string $message): string
    {
        $line = mb_trim((string) preg_replace('/\s+/u', ' ', $message));

        return Str::limit($line === '' ? 'New conversation' : $line, 60);
    }

    /**
     * The conversation waits for a decision, on a call of the assistant message that paused it.
     *
     * @throws ConflictHttpException
     */
    private function assertAwaiting(AiConversation $conversation, AiToolCall $call): void
    {
        throw_if($conversation->status !== AiConversation::STATUS_AWAITING_APPROVAL, ConflictHttpException::class, 'The assistant is not waiting for a decision.');

        $latest = $conversation->messages()->where('role', AiMessage::ROLE_ASSISTANT)->max('id');
        throw_if(! is_numeric($latest) || (int) $latest !== $call->message_id, ConflictHttpException::class, 'This action is from an earlier step and can no longer be decided.');
    }

    /**
     * @param  (Closure(AiConversation): void)|null  $prepare
     */
    private function addUserMessage(AiConversation $conversation, string $message, string $token, ?Closure $prepare = null): void
    {
        AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => AiMessage::ROLE_USER,
            'content' => $message,
        ]);

        if ($prepare instanceof Closure) {
            $prepare($conversation);
        }

        dispatch(new RunAssistantTurn($conversation->id, $token));
    }
}
