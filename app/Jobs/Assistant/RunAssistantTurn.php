<?php

declare(strict_types=1);

namespace Pterodactyl\Jobs\Assistant;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Pterodactyl\Models\Assistant\AiConversation;
use Pterodactyl\Services\Assistant\AssistantAgent;
use Throwable;

/**
 * Runs the assistant's agent loop for a conversation that is "running": after a new user
 * message, and again after the user allows or denies a tool call. It carries the run token the
 * conversation got when the turn started, so only this run may work on that turn.
 *
 * Turns run on their own "assistant" connection and queue, whose retry_after (960 s) is above
 * the timeout, so a slow turn is never handed to a second worker while it still runs.
 */
class RunAssistantTurn implements ShouldQueue
{
    use Queueable;

    public const string CONNECTION = 'assistant';

    public const string QUEUE = 'assistant';

    public const int TIMEOUT = 900;

    /**
     * How long a turn waits for an older run to let go of the conversation. An older run that
     * lost its token stops at its next check, at the latest after one model call (290 s) and
     * a tool run.
     */
    public const int LOCK_WAIT_SECONDS = 420;

    /**
     * One attempt only: a worker that crashed or was killed never hands the turn to another
     * one, which would call the model (and charge) again and could repeat tool calls. The
     * redelivered job fails at once, and failed() marks the turn as stopped.
     */
    public int $tries = 1;

    public int $maxExceptions = 1;

    public bool $failOnTimeout = true;

    public int $timeout = self::TIMEOUT;

    public function __construct(public readonly int $conversationId, public readonly string $runToken)
    {
        $this->onConnection(self::CONNECTION);
        $this->onQueue(self::QUEUE);
    }

    public function handle(AssistantAgent $agent): void
    {
        $conversation = AiConversation::query()->find($this->conversationId);
        if ($conversation instanceof AiConversation && ! $agent->run($conversation, $this->runToken, self::LOCK_WAIT_SECONDS)) {
            $agent->fail($conversation, 'The assistant was still busy with an earlier step. Send your message again to continue.', $this->runToken);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $conversation = AiConversation::query()->find($this->conversationId);
        if ($conversation instanceof AiConversation) {
            resolve(AssistantAgent::class)->fail($conversation, 'The assistant stopped unexpectedly. Send your message again to continue.', $this->runToken);
        }
    }
}
