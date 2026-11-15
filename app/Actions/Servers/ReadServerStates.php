<?php

declare(strict_types=1);

namespace Pterodactyl\Actions\Servers;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Contracts\Servers\ReadsServerStates;
use Pterodactyl\Data\ServerState;
use Pterodactyl\Data\ServerStateUnavailable;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Facades\Daemon;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Daemon\ServerClient;
use Throwable;

/**
 * Reads the live state of many servers at once, for the dashboard's server cards.
 *
 * Readings share ReadServerState's cache entries, so the batch and the single resources
 * endpoint answer from the same Wings call. Servers without a fresh reading are asked for
 * all at once in one HTTP pool, one connection setup per node, with a short timeout, so an
 * unreachable node delays the batch by seconds rather than failing it. A failed reading is
 * remembered briefly, so many open dashboards do not keep waiting on a node that is down.
 */
final readonly class ReadServerStates implements ReadsServerStates
{
    /** How long a failed reading is reused before Wings is asked again. */
    public const int FAILURE_CACHE_SECONDS = 10;

    public const int TIMEOUT_SECONDS = 3;

    public const int CONNECT_TIMEOUT_SECONDS = 2;

    public static function failureCacheKey(Server $server): string
    {
        return "resources:failed:$server->uuid";
    }

    /**
     * @param  iterable<Server>  $servers  servers with their node loaded
     * @return array<string, ServerState|ServerStateUnavailable> keyed by server uuid
     */
    public function read(iterable $servers): array
    {
        /** @var array<string, Server> $byUuid */
        $byUuid = [];
        foreach ($servers as $server) {
            $byUuid[$server->uuid] = $server;
        }

        if ($byUuid === []) {
            return [];
        }

        $results = [];
        $pending = $this->fromCache($byUuid, $results);

        /** @var array<string, ServerClient> $clients */
        $clients = [];
        $nodes = [];
        foreach ($pending as $uuid => $server) {
            try {
                $nodes[$server->node_id] ??= Daemon::node($server->node);
                $clients[$uuid] = $nodes[$server->node_id]->server($server);
            } catch (Throwable $exception) {
                // An undecryptable token or a malformed address makes the node unreadable, not the batch.
                $results[$uuid] = ServerStateUnavailable::fromDaemon($this->daemonException($exception));
            }
        }

        if ($clients === []) {
            return $results;
        }

        $responses = Http::pool(function (Pool $pool) use ($clients): void {
            foreach ($clients as $uuid => $client) {
                $client->poolDetails($pool, $uuid, self::TIMEOUT_SECONDS, self::CONNECT_TIMEOUT_SECONDS);
            }
        });

        $readings = [];
        $failures = [];
        $reported = [];
        foreach ($clients as $uuid => $client) {
            try {
                $readings[ReadServerState::cacheKey($byUuid[$uuid])] = $stats = $client->detailsFromPool($responses[$uuid] ?? null);
                $results[$uuid] = ServerState::fromDaemon($stats);
            } catch (DaemonConnectionException $exception) {
                // One log entry per unreachable node is enough to explain all of its servers.
                if (! isset($reported[$byUuid[$uuid]->node_id])) {
                    $reported[$byUuid[$uuid]->node_id] = true;
                    report($exception);
                }

                $results[$uuid] = $failure = ServerStateUnavailable::fromDaemon($exception);
                $failures[self::failureCacheKey($byUuid[$uuid])] = $failure->toArray();
            }
        }

        if ($readings !== []) {
            Cache::putMany($readings, now()->addSeconds(ReadServerState::CACHE_SECONDS));
        }

        if ($failures !== []) {
            Cache::putMany($failures, now()->addSeconds(self::FAILURE_CACHE_SECONDS));
        }

        return $results;
    }

    /**
     * Fills $results with every reading still cached and returns the servers that have none.
     *
     * @param  array<string, Server>  $servers
     * @param  array<string, ServerState|ServerStateUnavailable>  $results
     * @return array<string, Server>
     */
    private function fromCache(array $servers, array &$results): array
    {
        $keys = [];
        foreach ($servers as $uuid => $server) {
            $keys[$uuid] = [ReadServerState::cacheKey($server), self::failureCacheKey($server)];
        }

        $cached = Cache::many(array_merge(...array_values($keys)));

        $pending = [];
        foreach ($servers as $uuid => $server) {
            [$readingKey, $failureKey] = $keys[$uuid];
            $reading = $cached[$readingKey] ?? null;
            $failure = $cached[$failureKey] ?? null;

            if (is_array($reading)) {
                /** @var DaemonStats $reading */
                $results[$uuid] = ServerState::fromDaemon($reading);
            } elseif ($this->isFailure($failure)) {
                /** @var array{code: string, status: int, detail: string} $failure */
                $results[$uuid] = ServerStateUnavailable::fromArray($failure);
            } else {
                $pending[$uuid] = $server;
            }
        }

        return $pending;
    }

    private function isFailure(mixed $value): bool
    {
        return is_array($value)
            && is_string($value['code'] ?? null)
            && is_int($value['status'] ?? null)
            && is_string($value['detail'] ?? null);
    }

    private function daemonException(Throwable $exception): DaemonConnectionException
    {
        return $exception instanceof DaemonConnectionException ? $exception : new DaemonConnectionException($exception, false);
    }
}
