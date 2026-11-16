<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Plugins;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Pterodactyl\Models\Server;

/**
 * Where a queued plugin install or update is (InstallPluginJob), kept in the cache for a day so
 * the page can poll it by its ID.
 *
 * @phpstan-type InstallProgress array{id: string, status: string, message: string|null, file_name: string|null, directory: string, version_number: string}
 */
final class PluginInstallProgress
{
    public const string QUEUED = 'queued';

    public const string RUNNING = 'running';

    public const string COMPLETED = 'completed';

    public const string FAILED = 'failed';

    private const int KEEP_SECONDS = 86400;

    /** @return InstallProgress */
    public static function start(Server $server, string $directory, string $versionNumber): array
    {
        $progress = [
            'id' => (string) Str::uuid(),
            'status' => self::QUEUED,
            'message' => null,
            'file_name' => null,
            'directory' => $directory,
            'version_number' => $versionNumber,
        ];
        Cache::put(self::key($server, $progress['id']), $progress, self::KEEP_SECONDS);

        return $progress;
    }

    /** @return InstallProgress|null */
    public static function get(Server $server, string $id): ?array
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        $value = Cache::get(self::key($server, $id));

        /** @var InstallProgress|null $value */
        return is_array($value) ? $value : null;
    }

    /** @param  array{status?: string, message?: string|null, file_name?: string|null}  $changes */
    public static function update(Server $server, string $id, array $changes): void
    {
        $current = self::get($server, $id);
        if ($current !== null) {
            Cache::put(self::key($server, $id), [...$current, ...$changes], self::KEEP_SECONDS);
        }
    }

    private static function key(Server $server, string $id): string
    {
        return 'plugins:install:'.$server->uuid.':'.$id;
    }
}
