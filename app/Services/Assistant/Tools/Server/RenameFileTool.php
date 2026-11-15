<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools\Server;

use Pterodactyl\Contracts\Files\RenamesFiles;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Exceptions\Assistant\ToolException;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolResult;
use Pterodactyl\Services\Assistant\Tools\ServerPaths;

final class RenameFileTool extends ServerTool
{
    public function __construct(private readonly RenamesFiles $renamer) {}

    public function name(): string
    {
        return 'rename_file';
    }

    public function description(): string
    {
        return 'Rename or move a file or folder. The user is asked to confirm first.';
    }

    public function parameters(): array
    {
        return $this->schema([
            'from' => ['type' => 'string', 'description' => 'The current path, such as "/plugins/old.jar".'],
            'to' => ['type' => 'string', 'description' => 'The new path, such as "/plugins/disabled/old.jar".'],
        ], ['from', 'to']);
    }

    public function needsConfirmation(array $arguments, ToolContext $context): bool
    {
        return true;
    }

    public function prepareArguments(array $arguments, ToolContext $context): array
    {
        $from = ServerPaths::normalize($this->string($arguments, 'from'));
        $to = ServerPaths::normalize($this->string($arguments, 'to'));
        throw_if($from === '/' || $to === '/', ToolException::class, 'The server root cannot be renamed.');

        throw_if($from === $to, ToolException::class, 'The new path is the same as the current one.');

        return ['from' => $from, 'to' => $to];
    }

    public function describeCall(array $arguments): string
    {
        return sprintf('Rename %s to %s', self::shorten(ServerPaths::normalize($this->labelValue($arguments, 'from')), 80), self::shorten(ServerPaths::normalize($this->labelValue($arguments, 'to')), 80));
    }

    protected function permissions(): array
    {
        return [Permissions::FileUpdate];
    }

    protected function run(array $arguments, ToolContext $context): ToolResult
    {
        $from = ServerPaths::normalize($this->string($arguments, 'from'));
        $to = ServerPaths::normalize($this->string($arguments, 'to'));
        throw_if($from === '/' || $to === '/', ToolException::class, 'The server root cannot be renamed.');

        throw_if($from === $to, ToolException::class, 'The new path is the same as the current one.');

        $this->renamer->rename($this->server($context), '/', [['from' => mb_ltrim($from, '/'), 'to' => mb_ltrim($to, '/')]]);

        return new ToolResult("Renamed {$from} to {$to}.", ['from' => $from, 'to' => $to]);
    }
}
