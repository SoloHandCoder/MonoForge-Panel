<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools\Server;

use Pterodactyl\Contracts\Files\DeletesFiles;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Exceptions\Assistant\ToolException;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolResult;
use Pterodactyl\Services\Assistant\Tools\ServerPaths;

final class DeleteFilesTool extends ServerTool
{
    public const int MAX_FILES = 50;

    public function __construct(private readonly DeletesFiles $deleter) {}

    public function name(): string
    {
        return 'delete_files';
    }

    public function description(): string
    {
        return 'Permanently delete files or folders (folders with everything in them). The user is asked to confirm first.';
    }

    public function parameters(): array
    {
        return $this->schema([
            'root' => ['type' => 'string', 'description' => 'The directory the names are in. Defaults to "/".'],
            'files' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => self::MAX_FILES, 'description' => 'The names (or paths below root) to delete.'],
        ], ['files']);
    }

    public function needsConfirmation(array $arguments, ToolContext $context): bool
    {
        return true;
    }

    public function describeCall(array $arguments): string
    {
        $files = $arguments['files'] ?? [];
        $count = is_array($files) ? count($files) : 1;
        $root = ServerPaths::normalize($this->labelArgument($arguments, 'root', '/'));

        return $count === 1
            ? 'Delete '.ServerPaths::join($root, $this->labelArgument($arguments, 'files'))
            : sprintf('Delete %d files in %s', $count, $root);
    }

    protected function permissions(): array
    {
        return [Permissions::FileDelete];
    }

    protected function run(array $arguments, ToolContext $context): ToolResult
    {
        $root = ServerPaths::normalize($this->string($arguments, 'root', '/'));

        $paths = [];
        foreach ($this->stringList($arguments, 'files', self::MAX_FILES) as $file) {
            $path = ServerPaths::join($root, $file);
            if ($path === '/' || ($root !== '/' && ! str_starts_with($path, $root.'/'))) {
                throw new ToolException(sprintf('"%s" is not inside %s.', $file, $root));
            }

            $paths[] = $path;
        }

        $paths = array_values(array_unique($paths));
        $relative = array_map(fn (string $path): string => mb_ltrim($root === '/' ? $path : mb_substr($path, mb_strlen($root)), '/'), $paths);

        $this->deleter->delete($this->server($context), $root, $relative);

        return new ToolResult('Deleted: '.implode(', ', $paths), ['root' => $root, 'files' => $relative]);
    }
}
