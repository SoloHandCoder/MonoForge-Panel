<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Support\Assistant;

use Pterodactyl\Contracts\Assistant\AssistantTool;
use Pterodactyl\Contracts\Assistant\ToolContext;
use Pterodactyl\Contracts\Assistant\ToolResult;

/** A harmless read tool for the loop tests: runs without confirmation and echoes its text. */
final class EchoTool implements AssistantTool
{
    /** @var list<array{arguments: array<string, mixed>, user: int}> */
    public array $calls = [];

    public function name(): string
    {
        return 'echo_text';
    }

    public function description(): string
    {
        return 'Echoes the given text.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']];
    }

    public function needsConfirmation(array $arguments, ToolContext $context): bool
    {
        return false;
    }

    public function describeCall(array $arguments): string
    {
        return 'Echo '.(is_string($arguments['text'] ?? null) ? $arguments['text'] : '');
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $this->calls[] = ['arguments' => $arguments, 'user' => $context->user->id];

        return new ToolResult('echo: '.(is_string($arguments['text'] ?? null) ? $arguments['text'] : ''), ['echoed' => true]);
    }
}
