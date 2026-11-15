<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Chat;

/**
 * One answer from the model: its text, the tool calls it wants, why it stopped and the
 * tokens it used.
 */
final readonly class ChatResponse
{
    /**
     * @param  list<ChatToolCall>  $toolCalls
     */
    public function __construct(
        public ?string $content,
        public array $toolCalls,
        public ?string $finishReason,
        public ChatUsage $usage,
    ) {}
}
