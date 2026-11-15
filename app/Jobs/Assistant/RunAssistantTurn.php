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

    /** Seconds to wait before trying again while another run holds the conversation. */
    public const int RELEASE_SECONDS = 10;

    /**
     * Attempts only cover waiting for the conversation lock (release()); an exception or a
     * timeout fails the job at once, so a turn and its tool calls are never repeated.
     */
    public int $tries = 30;

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
        if ($conversation instanceof AiConversation && ! $agent->run($conversation, $this->runToken)) {
            $this->release(self::RELEASE_SECONDS);
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
