<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Support\Assistant;

use Pterodactyl\Contracts\Assistant\AssistantTool;
use Pterodactyl\Contracts\Assistant\AuthorizesAssistantTool;
use Pterodactyl\Exceptions\Assistant\ToolException;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolResult;

/**
 * A harmless "sensitive" tool for the loop tests: always needs the user's confirmation,
 * and only records that it ran. allowedFor limits it to some users, like a permission.
 */
final class ConfirmTool implements AssistantTool, AuthorizesAssistantTool
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /** @var list<int>|null */
    public ?array $allowedFor = null;

    public function name(): string
    {
        return 'pretend_delete';
    }

    public function description(): string
    {
        return 'Pretends to delete a file.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']];
    }

    public function needsConfirmation(array $arguments, ToolContext $context): bool
    {
        return true;
    }

    public function describeCall(array $arguments): string
    {
        return 'Delete '.(is_string($arguments['path'] ?? null) ? $arguments['path'] : '?');
    }

    public function allowed(ToolContext $context): bool
    {
        return $this->allowedFor === null || in_array($context->user->id, $this->allowedFor, true);
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        throw_if(($arguments['path'] ?? null) === 'boom', ToolException::class, 'That file does not exist.');

        $this->calls[] = $arguments;

        return new ToolResult('deleted '.(is_string($arguments['path'] ?? null) ? $arguments['path'] : ''));
    }
}
