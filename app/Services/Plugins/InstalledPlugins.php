<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Plugins;

use Illuminate\Support\Facades\Cache;
use Pterodactyl\Data\Plugins\MarketplaceVersion;
use Pterodactyl\Enum\MarketplaceSource;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Exceptions\Http\Server\FileSizeTooLargeException;
use Pterodactyl\Exceptions\Plugins\MarketplaceException;
use Pterodactyl\Facades\Daemon;
use Pterodactyl\Jobs\Plugins\ScanInstalledPluginsJob;
use Pterodactyl\Models\Server;

/**
 * The jars in a server's plugins/ or mods/ folder, matched to Modrinth projects by their sha1
 * (Modrinth's version-file endpoint), with the newest version for the server's platform when
 * there is a newer one.
 *
 * Hashing a jar means streaming it back from Wings, so it never happens in a web request: a
 * queued scan (ScanInstalledPluginsJob, on the "installs" queue) hashes at most MAX_HASHED jars
 * of at most MAX_HASH_BYTES each and stores one entry per server and folder, rebuilt (and so
 * pruned) on every scan. A request lists the folder, uses the stored hashes, and queues a rescan
 * only when the files changed, at most once a minute per server.
 *
 * @phpstan-type InstalledJar array{file_name: string, size: int, modified_at: string|null, sha1: string|null, note: string|null, project: array{source: string, id: string, title: string, slug: string, icon_url: string|null, url: string}|null, version: array{id: string, name: string, version_number: string}|null, update: array{id: string, name: string, version_number: string}|null}
 * @phpstan-type ScannedFile array{size: int, modified: string, sha1: string|null, note: string|null}
 * @phpstan-type Scan array{signature: string, files: array<string, ScannedFile>}
 */
final readonly class InstalledPlugins
{
    /** Jars beyond this many are listed without being hashed or matched. */
    public const int MAX_HASHED = 300;

    /** Jars larger than this are not hashed (and so not matched). */
    public const int MAX_HASH_BYTES = 100 * 1024 * 1024;

    /** A server's folder is scanned again at most this often. */
    public const int RESCAN_SECONDS = 60;

    public const string TOO_LARGE_NOTE = 'Not checked: larger than 100 MB.';

    public const string TOO_MANY_NOTE = 'Not checked: only the first 300 jars are checked.';

    private const int SCAN_CACHE_SECONDS = 30 * 24 * 3600;

    public function __construct(
        private PluginInstaller $installer,
        private MarketplaceCatalogs $catalogs,
        private MarketplaceHttp $http,
    ) {}

    /**
     * The installed jars with what the last scan knows, and whether a scan is still to come.
     *
     * @return array{list: list<InstalledJar>, scanning: bool}
     *
     * @throws DaemonConnectionException
     * @throws MarketplaceException
     */
    public function current(Server $server, ServerPlatform $platform): array
    {
        $directory = $platform->directory();
        if ($directory === null) {
            return ['list' => [], 'scanning' => false];
        }

        $jars = $this->installer->jars($server, $directory);
        $scan = $this->stored($server, $directory);
        $scanning = $scan === null || $scan['signature'] !== self::signature($jars);
        if ($scanning) {
            $this->queueScan($server, $directory);
        }

        $known = $scan['files'] ?? [];
        $hashes = [];
        $notes = [];
        foreach ($jars as $jar) {
            $entry = $known[$jar['name']] ?? null;
            if ($entry !== null && $entry['size'] === $jar['size'] && $entry['modified'] === $jar['modified']) {
                if ($entry['sha1'] !== null) {
                    $hashes[$jar['name']] = $entry['sha1'];
                }

                $notes[$jar['name']] = $entry['note'];
            }
        }

        return ['list' => $this->describe($jars, $hashes, $notes, $platform), 'scanning' => $scanning];
    }

    /**
     * One installed jar by file name, or null when it is not in the folder.
     *
     * @return InstalledJar|null
     *
     * @throws DaemonConnectionException
     * @throws MarketplaceException
     */
    public function find(Server $server, ServerPlatform $platform, string $name): ?array
    {
        foreach ($this->current($server, $platform)['list'] as $jar) {
            if ($jar['file_name'] === $name) {
                return $jar;
            }
        }

        return null;
    }

    /**
     * Hashes the folder's jars (the queued scan) and stores the result, replacing the last one.
     *
     * @throws DaemonConnectionException
     */
    public function scan(Server $server, string $directory): void
    {
        $jars = $this->installer->jars($server, $directory);
        $previous = $this->stored($server, $directory)['files'] ?? [];

        $files = [];
        foreach ($jars as $position => $jar) {
            $name = $jar['name'];
            $old = $previous[$name] ?? null;
            if ($position >= self::MAX_HASHED) {
                $files[$name] = ['size' => $jar['size'], 'modified' => $jar['modified'], 'sha1' => null, 'note' => self::TOO_MANY_NOTE];
            } elseif ($jar['size'] > self::MAX_HASH_BYTES) {
                $files[$name] = ['size' => $jar['size'], 'modified' => $jar['modified'], 'sha1' => null, 'note' => self::TOO_LARGE_NOTE];
            } elseif ($old !== null && $old['sha1'] !== null && $old['size'] === $jar['size'] && $old['modified'] === $jar['modified']) {
                $files[$name] = $old;
            } else {
                $files[$name] = ['size' => $jar['size'], 'modified' => $jar['modified'], 'sha1' => $this->sha1($server, $directory.'/'.$name), 'note' => null];
            }
        }

        Cache::put($this->scanKey($server, $directory), ['signature' => self::signature($jars), 'files' => $files], self::SCAN_CACHE_SECONDS);
    }

    /** Lets the next look at the folder queue a scan at once (after an install changed it). */
    public function forget(Server $server): void
    {
        foreach (['plugins', 'mods'] as $directory) {
            Cache::forget($this->lockKey($server, $directory));
        }
    }

    /** @param  list<array{name: string, size: int, modified: string}>  $jars */
    private static function signature(array $jars): string
    {
        return hash('sha256', json_encode($jars, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  list<array{name: string, size: int, modified: string}>  $jars
     * @param  array<string, string>  $hashes  file name => sha1
     * @param  array<string, string|null>  $notes
     * @return list<InstalledJar>
     *
     * @throws MarketplaceException
     */
    private function describe(array $jars, array $hashes, array $notes, ServerPlatform $platform): array
    {
        $unique = array_values(array_unique(array_values($hashes)));
        sort($unique);

        $matches = ['current' => [], 'latest' => [], 'projects' => []];
        if ($unique !== []) {
            $modrinth = $this->catalogs->modrinth();
            /** @var array{current: array<string, MarketplaceVersion>, latest: array<string, MarketplaceVersion>, projects: array<string, array{title: string, slug: string, icon_url: string|null, project_type: string}>} $matches */
            $matches = $this->http->remember(MarketplaceSource::Modrinth, 'installed', [$unique, $platform->loader, $platform->gameVersion], function () use ($modrinth, $unique, $platform): array {
                $current = $modrinth->versionsByHash($unique);
                $latest = $current === [] ? [] : $modrinth->latestByHash(array_keys($current), $platform);
                $projects = $modrinth->projects(array_values(array_unique(array_map(fn (MarketplaceVersion $version): string => $version->projectId, $current))));

                return ['current' => $current, 'latest' => $latest, 'projects' => $projects];
            });
        }

        $list = [];
        foreach ($jars as $jar) {
            $hash = $hashes[$jar['name']] ?? null;
            $current = $hash === null ? null : ($matches['current'][$hash] ?? null);
            $latest = $hash === null ? null : ($matches['latest'][$hash] ?? null);
            $project = $current === null ? null : ($matches['projects'][$current->projectId] ?? null);

            $list[] = [
                'file_name' => $jar['name'],
                'size' => $jar['size'],
                'modified_at' => $jar['modified'] === '' ? null : $jar['modified'],
                'sha1' => $hash,
                'note' => $notes[$jar['name']] ?? null,
                'project' => $current === null ? null : [
                    'source' => MarketplaceSource::Modrinth->value,
                    'id' => $current->projectId,
                    'title' => $project['title'] ?? $jar['name'],
                    'slug' => $project['slug'] ?? $current->projectId,
                    'icon_url' => $project['icon_url'] ?? null,
                    'url' => 'https://modrinth.com/'.rawurlencode($project['project_type'] ?? 'mod').'/'.rawurlencode($project['slug'] ?? $current->projectId),
                ],
                'version' => $current === null ? null : ['id' => $current->id, 'name' => $current->name, 'version_number' => $current->versionNumber],
                'update' => $current !== null && $latest !== null && $latest->id !== $current->id && $latest->file !== null
                    ? ['id' => $latest->id, 'name' => $latest->name, 'version_number' => $latest->versionNumber]
                    : null,
            ];
        }

        return $list;
    }

    /** Queues a scan unless one was queued for the folder in the last minute. */
    private function queueScan(Server $server, string $directory): void
    {
        if (Cache::add($this->lockKey($server, $directory), 1, self::RESCAN_SECONDS)) {
            dispatch(new ScanInstalledPluginsJob($server->id, $directory));
        }
    }

    /** @return Scan|null */
    private function stored(Server $server, string $directory): ?array
    {
        $value = Cache::get($this->scanKey($server, $directory));

        /** @var Scan|null $value */
        return is_array($value) ? $value : null;
    }

    private function sha1(Server $server, string $path): ?string
    {
        try {
            return Daemon::server($server)->files()->hashes($path, ['sha1'], self::MAX_HASH_BYTES)['sha1'] ?? null;
        } catch (DaemonConnectionException|FileSizeTooLargeException) {
            return null;
        }
    }

    private function scanKey(Server $server, string $directory): string
    {
        return 'plugins:installed:'.$server->uuid.':'.$directory;
    }

    private function lockKey(Server $server, string $directory): string
    {
        return 'plugins:installed-scan:'.$server->uuid.':'.$directory;
    }
}
