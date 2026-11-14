<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Insights;

use Illuminate\Support\Facades\Cache;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Facades\Daemon;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;

/**
 * Live resource use of every node and its servers, read from Wings. Nodes are named by
 * id and name only: connection addresses never leave this service.
 *
 * @phpstan-type ServerUsage array{id: int, name: string, state: string, cpu: float, cpu_limit: int|null, memory_bytes: int, memory_limit_bytes: int|null, disk_bytes: int|null, disk_limit_bytes: int|null}
 * @phpstan-type NodeUsage array{id: int, name: string, status: 'online'|'offline', cpu: array{used: float, capacity: int|null}, memory: array{used_bytes: int, allocated_bytes: int, capacity_bytes: int}, disk: array{used_bytes: int|null, allocated_bytes: int, capacity_bytes: int}, servers_online: int, servers_total: int, servers: list<ServerUsage>}
 * @phpstan-type LiveReading array{online: bool, cpu_count: int|null, servers: array<string, array{state: string, cpu: float, memory_bytes: int, disk_bytes: int}|null>}
 */
final readonly class NodeUsageService
{
    private const int MEBIBYTE = 1_048_576;

    /** How long a node's live reading is reused, so polling admins share one round of Wings calls. */
    private const int ONLINE_CACHE_SECONDS = 5;

    /** An unreachable node is retried less often, so its timeouts do not hold up every refresh. */
    private const int OFFLINE_CACHE_SECONDS = 20;

    /** Wings calls made here give up quickly: a slow node shows as offline rather than stalling the page. */
    private const int TIMEOUT_SECONDS = 3;

    private const int CONNECT_TIMEOUT_SECONDS = 2;

    /** @return list<NodeUsage> */
    public function nodes(): array
    {
        $servers = Server::query()
            ->without('allocation')
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'node_id', 'memory', 'disk', 'cpu'])
            ->groupBy('node_id');

        return array_values(Node::query()->orderBy('name')->get()
            ->map(fn (Node $node): array => $this->node($node, array_values($servers->get($node->id)?->all() ?? [])))
            ->all());
    }

    /**
     * @param  list<Server>  $servers
     * @return NodeUsage
     */
    private function node(Node $node, array $servers): array
    {
        $live = $this->reading($node, $servers);

        $rows = array_map(function (Server $server) use ($live): array {
            $reading = $live['servers'][$server->uuid] ?? null;

            return [
                'id' => $server->id,
                'name' => $server->name,
                'state' => $reading['state'] ?? ($live['online'] ? 'unknown' : 'offline'),
                'cpu' => $reading['cpu'] ?? 0.0,
                'cpu_limit' => $server->cpu > 0 ? $server->cpu : null,
                'memory_bytes' => $reading['memory_bytes'] ?? 0,
                'memory_limit_bytes' => $server->memory > 0 ? $server->memory * self::MEBIBYTE : null,
                'disk_bytes' => $reading['disk_bytes'] ?? null,
                'disk_limit_bytes' => $server->disk > 0 ? $server->disk * self::MEBIBYTE : null,
            ];
        }, $servers);

        $diskReadings = array_filter(array_column($rows, 'disk_bytes'), fn (?int $bytes): bool => $bytes !== null);

        return [
            'id' => $node->id,
            'name' => $node->name,
            'status' => $live['online'] ? 'online' : 'offline',
            'cpu' => [
                'used' => round(array_sum(array_column($rows, 'cpu')), 1),
                'capacity' => $live['cpu_count'] === null ? null : $live['cpu_count'] * 100,
            ],
            'memory' => [
                'used_bytes' => array_sum(array_column($rows, 'memory_bytes')),
                'allocated_bytes' => array_sum(array_map(fn (Server $server): int => $server->memory, $servers)) * self::MEBIBYTE,
                'capacity_bytes' => $node->memory * self::MEBIBYTE,
            ],
            'disk' => [
                'used_bytes' => $live['online'] ? array_sum($diskReadings) : null,
                'allocated_bytes' => array_sum(array_map(fn (Server $server): int => $server->disk, $servers)) * self::MEBIBYTE,
                'capacity_bytes' => $node->disk * self::MEBIBYTE,
            ],
            'servers_online' => count(array_filter($rows, fn (array $row): bool => $row['state'] === 'running')),
            'servers_total' => count($rows),
            'servers' => $rows,
        ];
    }

    /**
     * The node's live reading, from cache when one was taken in the last few seconds.
     *
     * @param  list<Server>  $servers
     * @return LiveReading
     */
    private function reading(Node $node, array $servers): array
    {
        $key = "insights:node:{$node->id}";

        /** @var LiveReading|null $cached */
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $reading = $this->withShortTimeouts(fn (): array => $this->read($node, $servers));
        Cache::put($key, $reading, now()->addSeconds($reading['online'] ? self::ONLINE_CACHE_SECONDS : self::OFFLINE_CACHE_SECONDS));

        return $reading;
    }

    /**
     * @param  list<Server>  $servers
     * @return LiveReading
     */
    private function read(Node $node, array $servers): array
    {
        try {
            $system = Daemon::node($node)->systemInformation();
        } catch (DaemonConnectionException) {
            return ['online' => false, 'cpu_count' => null, 'servers' => []];
        }

        $readings = [];
        foreach ($servers as $server) {
            try {
                $details = Daemon::server($server, $node)->details();
            } catch (DaemonConnectionException) {
                $readings[$server->uuid] = null;

                continue;
            }

            $utilization = $details['utilization'] ?? [];
            $readings[$server->uuid] = [
                'state' => $details['state'] ?? 'unknown',
                'cpu' => round((float) ($utilization['cpu_absolute'] ?? 0), 1),
                'memory_bytes' => $utilization['memory_bytes'] ?? 0,
                'disk_bytes' => $utilization['disk_bytes'] ?? 0,
            ];
        }

        return ['online' => true, 'cpu_count' => $this->cpuCount($system), 'servers' => $readings];
    }

    /**
     * Wings reports `cpu_count` at the top level (v1, and Calagopus Wings) or `system.cpu_threads` (v2).
     *
     * @param  array<mixed>  $system
     */
    private function cpuCount(array $system): ?int
    {
        $count = $system['cpu_count'] ?? (is_array($system['system'] ?? null) ? ($system['system']['cpu_threads'] ?? null) : null);

        return is_int($count) && $count > 0 ? $count : null;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withShortTimeouts(callable $callback): mixed
    {
        $timeout = config('pterodactyl.guzzle.timeout');
        $connectTimeout = config('pterodactyl.guzzle.connect_timeout');

        config([
            'pterodactyl.guzzle.timeout' => self::TIMEOUT_SECONDS,
            'pterodactyl.guzzle.connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
        ]);

        try {
            return $callback();
        } finally {
            config(['pterodactyl.guzzle.timeout' => $timeout, 'pterodactyl.guzzle.connect_timeout' => $connectTimeout]);
        }
    }
}
