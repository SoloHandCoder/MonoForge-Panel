<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Plugins;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Pterodactyl\Models\Server;

/**
 * Where a server's modpack install is, kept in the cache for a day so the Modpacks tab can
 * poll it. One install runs per server at a time: claim() takes the slot, release() frees it.
 *
 * @phpstan-type Progress array{status: string, step: string, message: string|null, project_title: string, version_name: string, files_done: int, files_total: int, backup: string|null, started_at: string, finished_at: string|null}
 */
final class ModpackProgress
{
    public const string QUEUED = 'queued';

    public const string RUNNING = 'running';

    public const string COMPLETED = 'completed';

    public const string FAILED = 'failed';

    /**
     * The longest an install may hold the slot, if a worker dies without releasing it: above the
     * wait in the queue, the backup wait (10 minutes) and the job's timeout together.
     */
    public const int CLAIM_SECONDS = 7200;

    private const int KEEP_SECONDS = 86400;

    /** Takes the server's install slot and returns its owner token; null while another install holds it. */
    public static function claim(Server $server): ?string
    {
        $token = (string) Str::uuid();

        return Cache::add(self::slotKey($server), $token, self::CLAIM_SECONDS) ? $token : null;
    }

    /**
     * At the start of a job run: true when the slot is this install's (renewed for another
     * CLAIM_SECONDS), or was free and is taken again; false when another install holds it.
     */
    public static function acquire(Server $server, string $token): bool
    {
        $holder = Cache::get(self::slotKey($server));
        if ($holder === null && Cache::add(self::slotKey($server), $token, self::CLAIM_SECONDS)) {
            return true;
        }

        if ($holder !== $token && Cache::get(self::slotKey($server)) !== $token) {
            return false;
        }

        Cache::put(self::slotKey($server), $token, self::CLAIM_SECONDS);

        return true;
    }

    /** Frees the slot; with a token, only when that install still holds it. */
    public static function release(Server $server, ?string $token = null): void
    {
        if ($token === null || Cache::get(self::slotKey($server)) === $token) {
            Cache::forget(self::slotKey($server));
        }
    }

    /** @return Progress|null */
    public static function get(Server $server): ?array
    {
        $value = Cache::get(self::key($server));

        /** @var Progress|null $value */
        return is_array($value) ? $value : null;
    }

    public static function start(Server $server, string $projectTitle, string $versionName): void
    {
        Cache::put(self::key($server), [
            'status' => self::QUEUED,
            'step' => 'queued',
            'message' => null,
            'project_title' => $projectTitle,
            'version_name' => $versionName,
            'files_done' => 0,
            'files_total' => 0,
            'backup' => null,
            'started_at' => now()->toAtomString(),
            'finished_at' => null,
        ], self::KEEP_SECONDS);
    }

    /** @param  array{status?: string, step?: string, message?: string|null, files_done?: int, files_total?: int, backup?: string|null}  $changes */
    public static function update(Server $server, array $changes): void
    {
        $current = self::get($server);
        if ($current === null) {
            return;
        }

        $next = [...$current, ...$changes];
        if (in_array($next['status'], [self::COMPLETED, self::FAILED], true)) {
            $next['finished_at'] = now()->toAtomString();
        }

        Cache::put(self::key($server), $next, self::KEEP_SECONDS);
    }

    private static function key(Server $server): string
    {
        return 'plugins:modpack:'.$server->uuid;
    }

    private static function slotKey(Server $server): string
    {
        return 'plugins:modpack-slot:'.$server->uuid;
    }
}
