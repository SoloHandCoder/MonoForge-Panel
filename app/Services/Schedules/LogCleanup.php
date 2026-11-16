<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Schedules;

use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Facades\Daemon;
use Pterodactyl\Models\Server;
use Throwable;

/**
 * The "weekly cleanup" task: deletes the old log files in the server's `logs/` folder. Only plain
 * files named like logs (`*.log`, `*.log.gz`) are touched, never `latest.log`, folders or links.
 */
final readonly class LogCleanup
{
    public const string DIRECTORY = '/logs';

    /** The most files one run deletes; the next run carries on. */
    public const int MAX_FILES = 500;

    public static function isLogName(string $name): bool
    {
        return $name !== 'latest.log' && preg_match('/^[\w.-]+\.log(\.gz)?$/i', $name) === 1;
    }

    /**
     * Deletes the logs older than the given days and returns how many were deleted.
     *
     * @throws DaemonConnectionException
     */
    public function run(Server $server, int $days): int
    {
        if ($days < 1) {
            return 0;
        }

        try {
            $entries = Daemon::server($server)->files()->getDirectory(self::DIRECTORY);
        } catch (DaemonConnectionException $daemonConnectionException) {
            // No logs folder yet: nothing to clean.
            throw_if($daemonConnectionException->getStatusCode() !== Response::HTTP_NOT_FOUND, $daemonConnectionException);

            return 0;
        }

        $cutoff = CarbonImmutable::now()->subDays($days);
        $names = [];
        foreach ($entries as $entry) {
            $name = $entry['name'] ?? '';
            if (($entry['file'] ?? false) !== true || ($entry['symlink'] ?? false) === true || ! self::isLogName($name)) {
                continue;
            }

            try {
                $modified = CarbonImmutable::parse($entry['modified'] ?? '');
            } catch (Throwable) {
                continue;
            }

            if ($modified->lessThan($cutoff)) {
                $names[] = $name;
            }

            if (count($names) >= self::MAX_FILES) {
                break;
            }
        }

        if ($names !== []) {
            Daemon::server($server)->files()->deleteFiles(self::DIRECTORY, $names);
        }

        return count($names);
    }
}
