<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Chat;

/**
 * A tool call the model asked for. Arguments that were not valid JSON are null.
 */
final readonly class ChatToolCall
{
    /**
     * @param  array<string, mixed>|null  $arguments
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?array $arguments,
        public string $rawArguments,
    ) {}
}
