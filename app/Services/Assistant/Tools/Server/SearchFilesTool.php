<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools\Server;

use Pterodactyl\Contracts\Files\ListsDirectories;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolResult;
use Pterodactyl\Services\Assistant\Tools\ServerPaths;

/**
 * Finds files and folders by name, walking the directory tree breadth first. The walk is
 * bounded in depth and in the number of directories listed, so one search costs at most a
 * fixed number of Wings calls.
 */
final class SearchFilesTool extends ServerTool
{
    public const int MAX_DEPTH = 5;

    public const int MAX_DIRECTORIES = 40;

    public const int MAX_RESULTS = 100;

    /** Folders that are large and rarely what anyone looks for. */
    private const array SKIPPED = ['node_modules', '.git', 'cache', '.cache', 'libraries', 'versions'];

    public function __construct(private readonly ListsDirectories $lister) {}

    public function name(): string
    {
        return 'search_files';
    }

    public function description(): string
    {
        return 'Find files and folders whose name contains the query (case-insensitive). "*" and "?" work as wildcards, for example "*.yml". Searches up to '.self::MAX_DEPTH.' levels deep.';
    }

    public function parameters(): array
    {
        return $this->schema([
            'query' => ['type' => 'string', 'description' => 'Part of the name, or a wildcard pattern such as "*.json".'],
            'directory' => ['type' => 'string', 'description' => 'Where to start searching. Defaults to "/".'],
        ], ['query']);
    }

    public function describeCall(array $arguments): string
    {
        return sprintf('Search for "%s" in %s', $this->labelArgument($arguments, 'query'), ServerPaths::normalize($this->labelArgument($arguments, 'directory', '/')));
    }

    protected function permissions(): array
    {
        return [Permissions::FileRead];
    }

    protected function run(array $arguments, ToolContext $context): ToolResult
    {
        $query = mb_strtolower(mb_trim($this->string($arguments, 'query', null, 191)));
        $start = ServerPaths::normalize($this->string($arguments, 'directory', '/'));
        $pattern = str_contains($query, '*') || str_contains($query, '?') ? $query : "*{$query}*";
        $server = $this->server($context);

        $queue = [['path' => $start, 'depth' => 0]];
        $next = 0;
        $listed = 0;
        $matches = [];
        $incomplete = false;

        while (isset($queue[$next]) && count($matches) < self::MAX_RESULTS) {
            if ($listed >= self::MAX_DIRECTORIES) {
                $incomplete = true;

                break;
            }

            ['path' => $directory, 'depth' => $depth] = $queue[$next];
            $next++;
            $listed++;

            try {
                $entries = $this->lister->list($server, $directory);
            } catch (DaemonConnectionException $daemonConnectionException) {
                // The starting directory must exist; a folder further down that cannot be
                // listed is skipped.
                throw_if($directory === $start, $daemonConnectionException);

                continue;
            }

            foreach ($entries as $entry) {
                $row = ListFilesTool::row($entry);
                $path = ServerPaths::join($directory, $row['name']);

                if ($row['name'] !== '' && ServerPaths::matches($pattern, $row['name'])) {
                    $matches[] = ['path' => $path, 'type' => $row['type'], 'size' => $row['size']];
                    if (count($matches) >= self::MAX_RESULTS) {
                        $incomplete = true;

                        break;
                    }
                }

                if ($row['type'] === 'directory' && $depth + 1 < self::MAX_DEPTH && ! in_array(mb_strtolower($row['name']), self::SKIPPED, true)) {
                    $queue[] = ['path' => $path, 'depth' => $depth + 1];
                }
            }
        }

        $data = ['query' => $query, 'directory' => $start, 'matches' => $matches];
        if ($incomplete || isset($queue[$next])) {
            $data['note'] = 'The search stopped early; narrow it down with a more specific directory.';
        }

        return new ToolResult($this->json($data), ['count' => count($matches)]);
    }
}
