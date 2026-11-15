<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant;

/**
 * What a tool call returned: the text the model reads, and a small structured summary
 * the chat shows on the tool call's card. A secret the user allowed to read (a sensitive
 * file, a revealed variable) is $private: it is shown only to the user, encrypted at rest,
 * and the model reads the redacted $content instead.
 */
final readonly class ToolResult
{
    /**
     * @param  string  $content  for the model; treated as untrusted data
     * @param  array<string, mixed>  $display  small structured summary for the UI
     * @param  string|null  $private  the full result for the user only
     */
    public function __construct(
        public string $content,
        public array $display = [],
        public ?string $private = null,
    ) {}
}
