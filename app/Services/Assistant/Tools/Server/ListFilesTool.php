<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools\Server;

use Pterodactyl\Contracts\Files\ListsDirectories;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolResult;
use Pterodactyl\Services\Assistant\Tools\ServerPaths;

final class ListFilesTool extends ServerTool
{
    public const int MAX_ENTRIES = 200;

    public function __construct(private readonly ListsDirectories $lister) {}

    /**
     * @param  DaemonFileObject  $entry
     * @return array{name: string, type: string, size: int|null, modified: string|null}
     */
    public static function row(array $entry): array
    {
        $type = match (true) {
            $entry['symlink'] ?? false => 'symlink',
            $entry['file'] ?? true => 'file',
            default => 'directory',
        };

        return [
            'name' => $entry['name'] ?? '',
            'type' => $type,
            'size' => $type === 'file' ? ($entry['size'] ?? null) : null,
            'modified' => $entry['modified'] ?? null,
        ];
    }

    public function name(): string
    {
        return 'list_files';
    }

    public function description(): string
    {
        return 'List the files and folders in a directory of the server. Paths start at the server root "/".';
    }

    public function parameters(): array
    {
        return $this->schema([
            'directory' => ['type' => 'string', 'description' => 'The directory to list, such as "/" or "/config". Defaults to "/".'],
        ]);
    }

    public function describeCall(array $arguments): string
    {
        return 'List '.ServerPaths::normalize($this->labelArgument($arguments, 'directory', '/'));
    }

    protected function permissions(): array
    {
        return [Permissions::FileRead];
    }

    protected function run(array $arguments, ToolContext $context): ToolResult
    {
        $directory = ServerPaths::normalize($this->string($arguments, 'directory', '/'));
        $entries = $this->lister->list($this->server($context), $directory);

        $rows = [];
        foreach (array_slice($entries, 0, self::MAX_ENTRIES) as $entry) {
            $rows[] = self::row($entry);
        }

        $data = ['directory' => $directory, 'entries' => $rows];
        if (count($entries) > self::MAX_ENTRIES) {
            $data['note'] = sprintf('Only the first %d of %d entries are shown.', self::MAX_ENTRIES, count($entries));
        }

        return new ToolResult($this->json($data), ['directory' => $directory, 'count' => count($entries)]);
    }
}
