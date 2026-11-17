<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Chat;

/**
 * One answer from the model: its text, the tool calls it wants, why it stopped, the tokens it
 * used and, for a web search answer, the sources it cites.
 */
final readonly class ChatResponse
{
    /**
     * @param  list<ChatToolCall>  $toolCalls
     * @param  list<array{url: string, title: string}>  $citations
     */
    public function __construct(
        public ?string $content,
        public array $toolCalls,
        public ?string $finishReason,
        public ChatUsage $usage,
        public array $citations = [],
    ) {}
}
