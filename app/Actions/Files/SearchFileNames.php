<?php

declare(strict_types=1);

namespace Pterodactyl\Actions\Files;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Pterodactyl\Contracts\Files\ListsDirectories;
use Pterodactyl\Contracts\Files\SearchesFileNames;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;

/**
 * Finds files (never folders) by path for the editor's quick open. The directory tree is walked breadth first,
 * bounded in depth, in directories listed and in entries kept, so one walk costs a fixed number
 * of Wings calls, and bounded in time: each listing waits a few seconds at most and the whole walk
 * stops after its budget, keeping what it found so far. The walk is cached briefly, so typing a query
 * never lists a directory twice, and concurrent identical searches wait for one walk under a lock.
 */
final readonly class SearchFileNames implements SearchesFileNames
{
    public const int MAX_DEPTH = 6;

    public const int MAX_DIRECTORIES = 40;

    public const int MAX_ENTRIES = 5000;

    public const int MAX_RESULTS = 50;

    public const int CACHE_SECONDS = 60;

    /** The whole walk stops after this long and reports itself incomplete. */
    public const int WALK_BUDGET_SECONDS = 8;

    /** Each directory listing waits this long for Wings at most (less when the budget runs out sooner). */
    public const int LIST_TIMEOUT_SECONDS = 3;

    /** How long a search waits for another request's walk of the same tree before walking itself. */
    private const int LOCK_WAIT_SECONDS = 10;

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

        $index = $this->index($server, $start);

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

    /**
     * The cached walk of the tree below $start. Only one request walks it: the others wait on the
     * lock and then read what that walk cached.
     *
     * @return array{entries: list<array{path: string, name: string, is_file: bool, size: int}>, complete: bool}
     *
     * @throws DaemonConnectionException
     */
    private function index(Server $server, string $start): array
    {
        $key = sprintf('server:%s:file-index:%s', $server->uuid, hash('sha256', $start));
        $walk = function () use ($key, $server, $start): array {
            /** @var array{entries: list<array{path: string, name: string, is_file: bool, size: int}>, complete: bool} $index */
            $index = $this->cache->remember($key, self::CACHE_SECONDS, fn (): array => $this->walk($server, $start));

            return $index;
        };

        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return $walk();
        }

        $store = $this->cache->getStore();
        if (! $store instanceof LockProvider) {
            return $walk();
        }

        try {
            /** @var array{entries: list<array{path: string, name: string, is_file: bool, size: int}>, complete: bool} */
            return $store->lock($key.':lock', self::WALK_BUDGET_SECONDS + self::LIST_TIMEOUT_SECONDS)->block(self::LOCK_WAIT_SECONDS, $walk);
        } catch (LockTimeoutException) {
            // The other walk is stuck: this one is bounded by its own budget too.
            return $walk();
        }
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
        $deadline = CarbonImmutable::now()->addSeconds(self::WALK_BUDGET_SECONDS);

        while (isset($queue[$next])) {
            $remaining = (int) ceil(CarbonImmutable::now()->diffInSeconds($deadline, false));
            if ($next >= self::MAX_DIRECTORIES || ($next > 0 && $remaining <= 0)) {
                $complete = false;

                break;
            }

            ['path' => $directory, 'depth' => $depth] = $queue[$next];
            $next++;

            try {
                $listing = $this->lister->list($server, $directory, max(1, min(self::LIST_TIMEOUT_SECONDS, $remaining)));
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
