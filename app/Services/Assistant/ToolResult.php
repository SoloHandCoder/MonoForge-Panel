<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant;

/**
 * What a tool call returned: the text the model reads, and a small structured summary
 * the chat shows on the tool call's card.
 */
final readonly class ToolResult
{
    /**
     * @param  string  $content  for the model; treated as untrusted data
     * @param  array<string, mixed>  $display  small structured summary for the UI
     */
    public function __construct(
        public string $content,
        public array $display = [],
    ) {}
}
