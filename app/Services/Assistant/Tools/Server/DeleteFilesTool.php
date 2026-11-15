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

    /**
     * The root and the names below it, resolved and checked, so the label lists exactly the
     * paths that are deleted.
     */
    public function prepareArguments(array $arguments, ToolContext $context): array
    {
        $root = ServerPaths::normalize($this->string($arguments, 'root', '/'));

        $files = [];
        foreach ($this->stringList($arguments, 'files', self::MAX_FILES) as $file) {
            $path = ServerPaths::join($root, $file);
            if ($path === '/' || ($root !== '/' && ! str_starts_with($path, $root.'/'))) {
                throw new ToolException(sprintf('"%s" is not inside %s.', $file, $root));
            }

            $files[] = mb_ltrim($root === '/' ? $path : mb_substr($path, mb_strlen($root)), '/');
        }

        return ['root' => $root, 'files' => array_values(array_unique($files))];
    }

    public function describeCall(array $arguments): string
    {
        $paths = $this->paths($arguments);
        $root = ServerPaths::normalize($this->labelValue($arguments, 'root', '/'));

        return count($paths) === 1
            ? 'Delete '.self::shorten($paths[0])
            : sprintf('Delete %d files in %s', count($paths), self::shorten($root, 80));
    }

    public function callDetails(array $arguments, ToolContext $context): array
    {
        $paths = $this->paths($arguments);

        return [['label' => count($paths) === 1 ? 'Path' : sprintf('Paths (%d)', count($paths)), 'value' => implode("\n", $paths)]];
    }

    protected function permissions(): array
    {
        return [Permissions::FileDelete];
    }

    protected function run(array $arguments, ToolContext $context): ToolResult
    {
        // The stored arguments are already prepared; preparing again keeps a direct call safe.
        $prepared = $this->prepareArguments($arguments, $context);
        /** @var string $root */
        $root = $prepared['root'];
        /** @var list<string> $relative */
        $relative = $prepared['files'];

        $this->deleter->delete($this->server($context), $root, $relative);

        return new ToolResult('Deleted: '.implode(', ', $this->paths($prepared)), ['root' => $root, 'files' => $relative]);
    }

    /**
     * The absolute paths of prepared arguments.
     *
     * @param  array<string, mixed>  $arguments
     * @return list<string>
     */
    private function paths(array $arguments): array
    {
        $root = ServerPaths::normalize($this->labelValue($arguments, 'root', '/'));
        $files = $arguments['files'] ?? [];
        $files = is_array($files) ? array_values(array_filter($files, is_string(...))) : (is_string($files) ? [$files] : []);

        return array_map(fn (string $file): string => ServerPaths::join($root, $file), $files);
    }
}
