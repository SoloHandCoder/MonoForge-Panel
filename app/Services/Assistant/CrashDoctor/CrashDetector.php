<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\CrashDoctor;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Contracts\Servers\ReadsServerStates;
use Pterodactyl\Data\ServerState;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\ServerCrash;
use Pterodactyl\Services\Assistant\AssistantSettings;

/**
 * Once a minute, compares each server's state with the one seen the minute before (readings
 * come from the shared resource cache, one Wings pool per batch). A crash is:
 *
 * - running or starting before, offline now ("crashed", or "start_failed" when it was starting);
 * - running before and running again, but up for less time than has passed (it went down and
 *   Wings brought it back);
 * - offline before and still offline a while after a start was asked for through the panel
 *   ("start_failed").
 *
 * A suspect is only recorded GRACE_SECONDS later, once Wings has reported the console's power
 * actions as activity, and only when nobody asked for a stop, restart or kill meanwhile. When
 * most servers of a node go down at once the node restarted, so none of them counts.
 */
class CrashDetector
{
    /** How long a suspect waits for the activity Wings sends about once a minute. */
    public const int GRACE_SECONDS = 150;

    /** A start that has not brought the server up after this long failed. */
    public const int START_TIMEOUT_SECONDS = 45;

    /** Readings are remembered this long; an older one is no baseline. */
    public const int STATE_MINUTES = 5;

    /** Servers read from Wings at once. */
    public const int BATCH = 100;

    private const array UP = ['running', 'starting'];

    private const array DOWN = ['offline', 'stopped'];

    public function __construct(
        private readonly ReadsServerStates $states,
        private readonly CrashRecorder $recorder,
    ) {}

    public static function stateKey(Server $server): string
    {
        return "crash-doctor:state:{$server->uuid}";
    }

    public static function suspectKey(Server $server): string
    {
        return "crash-doctor:suspect:{$server->uuid}";
    }

    /**
     * Compares the states and records the crashes whose grace has passed.
     *
     * @return list<ServerCrash>
     */
    public function run(): array
    {
        if (! AssistantSettings::crashDoctorAvailable()) {
            return [];
        }

        $crashes = [];
        $this->servers()->chunkById(self::BATCH, function (Collection $servers) use (&$crashes): void {
            /** @var Collection<int, Server> $servers */
            $this->compare($servers);

            foreach ($servers as $server) {
                $crash = $this->decide($server);
                if ($crash instanceof ServerCrash) {
                    $crashes[] = $crash;
                }
            }
        });

        return $crashes;
    }

    /**
     * Servers that can run: installed, not suspended, not being transferred, on a node that is
     * not under maintenance.
     *
     * @return Builder<Server>
     */
    private function servers(): Builder
    {
        return Server::query()
            ->whereNull('status')
            ->whereDoesntHave('transfer')
            ->whereHas('node', fn (Builder $query): Builder => $query->where('maintenance_mode', false))
            ->with(['node', 'egg']);
    }

    /**
     * @param  Collection<int, Server>  $servers
     */
    private function compare(Collection $servers): void
    {
        $now = CarbonImmutable::now();
        $readings = $this->states->read($servers);
        $suspects = [];

        foreach ($servers as $server) {
            $key = self::stateKey($server);
            $reading = $readings[$server->uuid] ?? null;

            // Without a reading (the node is down) there is no baseline for the next comparison.
            if (! $reading instanceof ServerState) {
                Cache::forget($key);

                continue;
            }

            $previous = $this->previous($key);
            Cache::put($key, ['state' => $reading->state, 'uptime' => $reading->uptime, 'at' => $now->getTimestamp()], now()->addMinutes(self::STATE_MINUTES));

            $kind = $this->suspect($server, $previous, $reading, $now);
            if ($kind !== null) {
                $suspects[$server->node_id][] = [$server, $kind, $previous['at'] ?? $now->getTimestamp()];
            }
        }

        foreach ($suspects as $nodeId => $nodeSuspects) {
            if ($this->nodeRestarted($servers, $nodeId, count($nodeSuspects))) {
                continue;
            }

            foreach ($nodeSuspects as [$server, $kind, $since]) {
                Cache::add(self::suspectKey($server), ['kind' => $kind, 'since' => $since, 'seen' => $now->getTimestamp()], now()->addMinutes(15));
            }
        }
    }

    /**
     * @param  array{state: string, uptime: int, at: int}|null  $previous
     */
    private function suspect(Server $server, ?array $previous, ServerState $current, CarbonImmutable $now): ?string
    {
        $down = in_array($current->state, self::DOWN, true);

        if ($previous !== null && in_array($previous['state'], self::UP, true) && $down) {
            return $previous['state'] === 'starting' ? ServerCrash::KIND_START_FAILED : ServerCrash::KIND_CRASHED;
        }

        // Up for less time than passed since a running reading: it went down in between.
        $elapsedMs = ($now->getTimestamp() - ($previous['at'] ?? 0)) * 1000;
        if ($previous !== null && $previous['state'] === 'running' && in_array($current->state, self::UP, true) && $current->uptime > 0 && $current->uptime + 5000 < $elapsedMs) {
            return ServerCrash::KIND_CRASHED;
        }

        $start = $this->recorder->startRequestedAt($server);
        if ($start instanceof CarbonImmutable && ! $down) {
            // The start worked.
            Cache::forget(CrashRecorder::powerKey($server));

            return null;
        }

        if ($down && $start instanceof CarbonImmutable && $start->getTimestamp() <= $now->getTimestamp() - self::START_TIMEOUT_SECONDS && ($previous === null || in_array($previous['state'], self::DOWN, true))) {
            Cache::forget(CrashRecorder::powerKey($server));

            return ServerCrash::KIND_START_FAILED;
        }

        return null;
    }

    /**
     * Most of a node's servers going down at once is Wings or the machine restarting.
     *
     * @param  Collection<int, Server>  $servers
     */
    private function nodeRestarted(Collection $servers, int $nodeId, int $suspects): bool
    {
        $total = $servers->where('node_id', $nodeId)->count();

        return $suspects >= 3 && $suspects * 2 >= $total;
    }

    /** Records the server's suspect once its grace has passed, unless a stop was asked for. */
    private function decide(Server $server): ?ServerCrash
    {
        $suspect = Cache::get(self::suspectKey($server));
        if (! is_array($suspect) || ! is_string($suspect['kind'] ?? null) || ! is_int($suspect['since'] ?? null) || ! is_int($suspect['seen'] ?? null)) {
            return null;
        }

        if ($suspect['seen'] > CarbonImmutable::now()->getTimestamp() - self::GRACE_SECONDS) {
            return null;
        }

        Cache::forget(self::suspectKey($server));

        // A little before the last reading: the stop may have been sent just before it.
        if ($this->recorder->stopRequestedSince($server, CarbonImmutable::createFromTimestamp($suspect['since'])->subSeconds(30))) {
            return null;
        }

        return $this->recorder->record($server, $suspect['kind'], ServerCrash::SOURCE_SCHEDULER);
    }

    /**
     * @return array{state: string, uptime: int, at: int}|null
     */
    private function previous(string $key): ?array
    {
        $value = Cache::get($key);

        return is_array($value) && is_string($value['state'] ?? null) && is_int($value['uptime'] ?? null) && is_int($value['at'] ?? null)
            ? ['state' => $value['state'], 'uptime' => $value['uptime'], 'at' => $value['at']]
            : null;
    }
}
