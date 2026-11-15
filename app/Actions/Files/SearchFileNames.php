<?php

declare(strict_types=1);

namespace Pterodactyl\Actions\Files;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Pterodactyl\Contracts\Files\ListsDirectories;
use Pterodactyl\Contracts\Files\SearchesFileNames;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;

/**
 * Finds files (never folders) by path for the editor's quick open. The directory tree is walked breadth first,
 * bounded in depth, in directories listed and in entries kept, so one walk costs a fixed number
 * of Wings calls. The walk is cached briefly, so typing a query never lists a directory twice.
 */
final readonly class SearchFileNames implements SearchesFileNames
{
    public const int MAX_DEPTH = 6;

    public const int MAX_DIRECTORIES = 40;

    public const int MAX_ENTRIES = 5000;

    public const int MAX_RESULTS = 50;

    public const int CACHE_SECONDS = 60;

    /** Folders that are large and rarely what anyone looks for. */
    private const array SKIPPED = ['node_modules', '.git', 'cache', '.cache', 'libraries', 'versions', 'logs', 'crash-reports'];

    public function __construct(private ListsDirectories $lister, private CacheRepository $cache) {}

    public function search(Server $server, string $query, string $directory = '/'): array
    {
        $start = $this->normalize($directory);
        $words = array_values(array_filter(
            preg_split('/\s+/', mb_strtolower(mb_trim($query))) ?: [],
            fn (string $word): bool => $word !== '',
        ));

        if ($words === []) {
            return ['matches' => [], 'truncated' => false];
        }

        /** @var array{entries: list<array{path: string, name: string, is_file: bool, size: int}>, complete: bool} $index */
        $index = $this->cache->remember(
            sprintf('server:%s:file-index:%s', $server->uuid, sha1($start)),
            self::CACHE_SECONDS,
            fn (): array => $this->walk($server, $start),
        );

        $ranked = [];
        foreach ($index['entries'] as $entry) {
            $rank = $entry['is_file'] ? $this->rank($entry, $words) : null;
            if ($rank !== null) {
                $ranked[] = ['rank' => $rank, 'entry' => $entry];
            }
        }

        usort($ranked, fn (array $a, array $b): int => [$a['rank'], mb_strlen($a['entry']['path']), $a['entry']['path']]
            <=> [$b['rank'], mb_strlen($b['entry']['path']), $b['entry']['path']]);

        return [
            'matches' => array_map(fn (array $row): array => $row['entry'], array_slice($ranked, 0, self::MAX_RESULTS)),
            'truncated' => ! $index['complete'] || count($ranked) > self::MAX_RESULTS,
        ];
    }

    /**
     * Lower ranks sort first: the name starting with the last word, the name containing it, then
     * only the path containing it.
     *
     * @param  array{path: string, name: string, is_file: bool, size: int}  $entry
     * @param  non-empty-list<string>  $words
     */
    private function rank(array $entry, array $words): ?int
    {
        $path = mb_strtolower($entry['path']);
        foreach ($words as $word) {
            if (! str_contains($path, $word)) {
                return null;
            }
        }

        $name = mb_strtolower($entry['name']);
        $last = $words[count($words) - 1];

        return match (true) {
            str_starts_with($name, $last) => 0,
            str_contains($name, $last) => 1,
            default => 2,
        };
    }

    /** An absolute path without empty, "." or ".." segments; ".." never climbs above the root. */
    private function normalize(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }

        return '/'.implode('/', $segments);
    }

    /**
     * @return array{entries: list<array{path: string, name: string, is_file: bool, size: int}>, complete: bool}
     *
     * @throws DaemonConnectionException
     */
    private function walk(Server $server, string $start): array
    {
        $queue = [['path' => $start, 'depth' => 0]];
        $next = 0;
        $entries = [];
        $complete = true;

        while (isset($queue[$next])) {
            if ($next >= self::MAX_DIRECTORIES) {
                $complete = false;

                break;
            }

            ['path' => $directory, 'depth' => $depth] = $queue[$next];
            $next++;

            try {
                $listing = $this->lister->list($server, $directory);
            } catch (DaemonConnectionException $daemonConnectionException) {
                // The starting directory must exist; a folder further down that cannot be listed is skipped.
                throw_if($directory === $start, $daemonConnectionException);

                continue;
            }

            foreach ($listing as $object) {
                $name = $object['name'] ?? '';
                if ($name === '') {
                    continue;
                }

                if (count($entries) >= self::MAX_ENTRIES) {
                    return ['entries' => $entries, 'complete' => false];
                }

                $isFile = $object['file'] ?? true;
                $path = ($directory === '/' ? '' : $directory).'/'.$name;
                $entries[] = ['path' => $path, 'name' => $name, 'is_file' => $isFile, 'size' => $isFile ? ($object['size'] ?? 0) : 0];

                // Symbolic links are not followed, so a link back up the tree cannot loop.
                $descend = ! $isFile && ! ($object['symlink'] ?? false) && ! in_array(mb_strtolower($name), self::SKIPPED, true);
                if ($descend && $depth + 1 < self::MAX_DEPTH) {
                    $queue[] = ['path' => $path, 'depth' => $depth + 1];
                } elseif ($descend) {
                    $complete = false;
                }
            }
        }

        return ['entries' => $entries, 'complete' => $complete];
    }
}
