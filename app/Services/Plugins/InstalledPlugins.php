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
use Pterodactyl\Models\Server;

/**
 * The jars in a server's plugins/ or mods/ folder, matched to Modrinth projects by their sha1
 * (Modrinth's version-file endpoint), with the newest version for the server's platform when
 * there is a newer one. Each jar is hashed once: hashes are cached by path, size and time.
 *
 * @phpstan-type InstalledJar array{file_name: string, size: int, modified_at: string|null, sha1: string|null, project: array{source: string, id: string, title: string, slug: string, icon_url: string|null, url: string}|null, version: array{id: string, name: string, version_number: string}|null, update: array{id: string, name: string, version_number: string}|null}
 */
final readonly class InstalledPlugins
{
    /** Jars beyond this many are listed without being hashed or matched. */
    public const int MAX_HASHED = 300;

    private const int HASH_CACHE_SECONDS = 30 * 24 * 3600;

    public function __construct(
        private PluginInstaller $installer,
        private MarketplaceCatalogs $catalogs,
        private MarketplaceHttp $http,
    ) {}

    /**
     * @return list<InstalledJar>
     *
     * @throws DaemonConnectionException
     * @throws MarketplaceException
     */
    public function list(Server $server, ServerPlatform $platform): array
    {
        $directory = $platform->directory();
        if ($directory === null) {
            return [];
        }

        $jars = $this->installer->jars($server, $directory);

        $hashes = [];
        foreach (array_slice($jars, 0, self::MAX_HASHED) as $jar) {
            $hash = $this->sha1($server, $directory.'/'.$jar['name'], $jar['size'], $jar['modified']);
            if ($hash !== null) {
                $hashes[$jar['name']] = $hash;
            }
        }

        $unique = array_values(array_unique(array_values($hashes)));
        sort($unique);
        $modrinth = $this->catalogs->modrinth();

        /** @var array{current: array<string, MarketplaceVersion>, latest: array<string, MarketplaceVersion>, projects: array<string, array{title: string, slug: string, icon_url: string|null, project_type: string}>} $matches */
        $matches = $this->http->remember(MarketplaceSource::Modrinth, 'installed', [$unique, $platform->loader, $platform->gameVersion], function () use ($modrinth, $unique, $platform): array {
            $current = $modrinth->versionsByHash($unique);
            $latest = $current === [] ? [] : $modrinth->latestByHash(array_keys($current), $platform);
            $projects = $modrinth->projects(array_values(array_unique(array_map(fn (MarketplaceVersion $version): string => $version->projectId, $current))));

            return ['current' => $current, 'latest' => $latest, 'projects' => $projects];
        });

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
        foreach ($this->list($server, $platform) as $jar) {
            if ($jar['file_name'] === $name) {
                return $jar;
            }
        }

        return null;
    }

    private function sha1(Server $server, string $path, int $size, string $modified): ?string
    {
        $key = 'plugins:sha1:'.$server->uuid.':'.hash('sha256', $path.'|'.$size.'|'.$modified);

        $cached = Cache::get($key);
        if (is_string($cached)) {
            return $cached;
        }

        try {
            $hash = Daemon::server($server)->files()->hashes($path, ['sha1'], VerifiedDownload::MAX_BYTES)['sha1'] ?? null;
        } catch (DaemonConnectionException|FileSizeTooLargeException) {
            return null;
        }

        if ($hash !== null) {
            Cache::put($key, $hash, self::HASH_CACHE_SECONDS);
        }

        return $hash;
    }
}
