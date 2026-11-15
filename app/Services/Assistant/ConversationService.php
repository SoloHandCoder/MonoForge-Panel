<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant;

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
 */
class ConversationService
{
    public function __construct(
        private readonly AssistantBilling $billing,
        private readonly ToolExecutor $executor,
    ) {}

    /**
     * @throws AssistantUnavailableException
     */
    public function start(User $user, string $scope, ?Server $server, string $message): AiConversation
    {
        $this->assertAvailable($user, $scope);
        $provider = AssistantProviders::resolve();

        $conversation = AiConversation::query()->create([
            'uuid' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'scope' => $scope,
            'server_id' => $scope === AiConversation::SCOPE_SERVER ? $server?->id : null,
            'provider_id' => $provider->id,
            'title' => $this->title($message),
            'status' => AiConversation::STATUS_RUNNING,
        ]);

        $this->addUserMessage($conversation, $message);

        return $conversation->refresh();
    }

    /**
     * @throws AssistantUnavailableException
     * @throws ConflictHttpException while a turn is running or waiting for a decision
     */
    public function send(AiConversation $conversation, string $message): AiConversation
    {
        $conversation->recoverIfStale();
        throw_if($conversation->isBusy(), ConflictHttpException::class, 'The assistant is still working on this conversation.');

        $this->assertAvailable($conversation->user, $conversation->scope);

        throw_unless(
            $conversation->transition([AiConversation::STATUS_IDLE, AiConversation::STATUS_ERROR], AiConversation::STATUS_RUNNING),
            ConflictHttpException::class,
            'The assistant is still working on this conversation.',
        );

        $this->addUserMessage($conversation, $message);

        return $conversation->refresh();
    }

    /**
     * Runs the allowed call now, then lets the loop continue.
     *
     * @throws ConflictHttpException when the call is not waiting for a decision
     */
    public function approve(AiConversation $conversation, AiToolCall $call, User $user): AiConversation
    {
        $this->assertAwaiting($conversation);
        throw_unless($this->executor->approve($call, $user), ConflictHttpException::class, 'This action is not waiting for a decision.');

        $conversation->transition([AiConversation::STATUS_AWAITING_APPROVAL], AiConversation::STATUS_RUNNING);
        $this->executor->run($conversation, $call);
        dispatch(new RunAssistantTurn($conversation->id));

        return $conversation->refresh();
    }

    /**
     * Tells the model the user said no, then lets the loop continue.
     *
     * @throws ConflictHttpException when the call is not waiting for a decision
     */
    public function deny(AiConversation $conversation, AiToolCall $call, User $user): AiConversation
    {
        $this->assertAwaiting($conversation);
        throw_unless($this->executor->deny($call, $user), ConflictHttpException::class, 'This action is not waiting for a decision.');

        $conversation->transition([AiConversation::STATUS_AWAITING_APPROVAL], AiConversation::STATUS_RUNNING);
        dispatch(new RunAssistantTurn($conversation->id));

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

    private function title(string $message): string
    {
        $line = mb_trim((string) preg_replace('/\s+/u', ' ', $message));

        return Str::limit($line === '' ? 'New conversation' : $line, 60);
    }

    private function assertAwaiting(AiConversation $conversation): void
    {
        throw_if($conversation->status !== AiConversation::STATUS_AWAITING_APPROVAL, ConflictHttpException::class, 'The assistant is not waiting for a decision.');
    }

    private function addUserMessage(AiConversation $conversation, string $message): void
    {
        AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => AiMessage::ROLE_USER,
            'content' => $message,
        ]);

        dispatch(new RunAssistantTurn($conversation->id));
    }
}
