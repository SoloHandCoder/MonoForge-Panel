<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Schedules;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Facades\Daemon;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Task;
use Pterodactyl\Services\Assistant\CrashDoctor\CrashRecorder;
use Pterodactyl\Services\Query\QueryStatus;
use Pterodactyl\Services\Query\ServerQuery;

/**
 * The "restart when empty" task: each run asks the game who is online (one cached ping) and
 * restarts the server once nobody has been on for the task's hours. A game that does not answer
 * resets the count, so a server is never restarted on a guess, and so does a gap in the checks
 * (the server was off, or the schedule was disabled), so stale emptiness never counts.
 */
final readonly class RestartWhenEmpty
{
    /** A check further apart than this from the last one starts the count again. */
    public const int MAX_GAP_SECONDS = 3600;

    public function __construct(private ServerQuery $query) {}

    public static function key(Task $task): string
    {
        return "schedule:empty-since:{$task->id}";
    }

    /** Whether the server was restarted. */
    public function run(Task $task, Server $server): bool
    {
        $hours = (int) mb_trim($task->payload);
        if ($hours < 1 || $hours > Task::MAX_EMPTY_HOURS) {
            return false;
        }

        // A game whose pings keep timing out is left alone for a while, as auto-sleep does.
        $status = $this->query->isUnresponsive($server) ? null : $this->query->status($server);
        if (! $status instanceof QueryStatus || $status->online > 0) {
            Cache::forget(self::key($task));

            return false;
        }

        $now = CarbonImmutable::now()->getTimestamp();
        $state = Cache::get(self::key($task));
        $since = is_array($state) && is_int($state['since'] ?? null) && is_int($state['checked'] ?? null) && $now - $state['checked'] <= self::MAX_GAP_SECONDS
            ? $state['since']
            : $now;

        if ($now - $since < $hours * 3600) {
            Cache::put(self::key($task), ['since' => $since, 'checked' => $now], self::MAX_GAP_SECONDS * 2);

            return false;
        }

        Cache::forget(self::key($task));
        CrashRecorder::expectPowerChange($server, 'restart');
        Daemon::server($server)->power('restart');

        return true;
    }
}
