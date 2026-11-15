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
 * message, and again after the user allows or denies a tool call.
 */
class RunAssistantTurn implements ShouldQueue
{
    use Queueable;

    /** A turn is never retried: a retry could repeat tool calls that changed something. */
    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly int $conversationId) {}

    public function handle(AssistantAgent $agent): void
    {
        $conversation = AiConversation::query()->find($this->conversationId);
        if ($conversation instanceof AiConversation) {
            $agent->run($conversation);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $conversation = AiConversation::query()->find($this->conversationId);
        if ($conversation instanceof AiConversation) {
            resolve(AssistantAgent::class)->fail($conversation, 'The assistant stopped unexpectedly. Send your message again to continue.');
        }
    }
}
