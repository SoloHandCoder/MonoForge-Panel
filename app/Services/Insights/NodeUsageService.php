<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Insights;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Throwable;

/**
 * Live resource use of every node and its servers, read from Wings. Nodes are named by
 * id and name only: connection addresses never leave this service.
 *
 * Every node is asked for its system information at once, then every reachable node's
 * servers at once, each round with a short timeout, so one refresh takes at most about
 * six seconds however many nodes and servers there are. A server Wings did not answer for
 * in time has no reading; its node stays online.
 *
 * @phpstan-type ServerUsage array{id: int, name: string, state: string, cpu: float|null, cpu_limit: int|null, memory_bytes: int|null, memory_limit_bytes: int|null, disk_bytes: int|null, disk_limit_bytes: int|null}
 * @phpstan-type NodeUsage array{id: int, name: string, status: 'online'|'offline', cpu: array{used: float, capacity: int|null}, memory: array{used_bytes: int|null, allocated_bytes: int, capacity_bytes: int}, disk: array{used_bytes: int|null, allocated_bytes: int, capacity_bytes: int}, servers_online: int, servers_total: int, servers: list<ServerUsage>}
 * @phpstan-type ServerReading array{state: string, cpu: float|null, memory_bytes: int|null, disk_bytes: int|null}
 * @phpstan-type LiveReading array{online: bool, cpu_count: int|null, servers: array<string, ServerReading>}
 */
final readonly class NodeUsageService
{
    private const int MEBIBYTE = 1_048_576;

    /** How long a node's live reading is reused, so polling admins share one round of Wings calls. */
    private const int ONLINE_CACHE_SECONDS = 5;

    /** An unreachable node is retried less often, so its timeouts do not hold up every refresh. */
    private const int OFFLINE_CACHE_SECONDS = 20;

    /** Each round of Wings calls gives up after this long; two rounds bound a refresh. */
    private const int TIMEOUT_SECONDS = 3;

    private const int CONNECT_TIMEOUT_SECONDS = 2;

    /** Only one request refreshes stale readings; the others wait for it, then read its results. */
    private const string LOCK = 'insights:refresh';

    private const int LOCK_SECONDS = 10;

    private const int LOCK_WAIT_SECONDS = 7;

    /** @return list<NodeUsage> */
    public function nodes(): array
    {
        $servers = Server::query()
            ->without('allocation')
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'node_id', 'memory', 'disk', 'cpu'])
            ->groupBy('node_id');

        $nodes = array_values(Node::query()->orderBy('name')->get()->all());

        /** @var array<int, list<Server>> $byNode */
        $byNode = [];
        foreach ($nodes as $node) {
            $byNode[$node->id] = array_values($servers->get($node->id)?->all() ?? []);
        }

        $readings = $this->readings($nodes, $byNode);

        return array_map(fn (Node $node): array => $this->node($node, $byNode[$node->id], $readings[$node->id]), $nodes);
    }

    /**
     * @param  list<Server>  $servers
     * @param  LiveReading  $live
     * @return NodeUsage
     */
    private function node(Node $node, array $servers, array $live): array
    {
        $rows = array_map(function (Server $server) use ($live): array {
            $reading = $live['servers'][$server->uuid] ?? null;

            return [
                'id' => $server->id,
                'name' => $server->name,
                'state' => $reading['state'] ?? ($live['online'] ? 'unknown' : 'offline'),
                'cpu' => $reading['cpu'] ?? null,
                'cpu_limit' => $server->cpu > 0 ? $server->cpu : null,
                'memory_bytes' => $reading['memory_bytes'] ?? null,
                'memory_limit_bytes' => $server->memory > 0 ? $server->memory * self::MEBIBYTE : null,
                'disk_bytes' => $reading['disk_bytes'] ?? null,
                'disk_limit_bytes' => $server->disk > 0 ? $server->disk * self::MEBIBYTE : null,
            ];
        }, $servers);

        return [
            'id' => $node->id,
            'name' => $node->name,
            'status' => $live['online'] ? 'online' : 'offline',
            'cpu' => [
                'used' => round(array_sum($this->known(array_column($rows, 'cpu'))), 1),
                'capacity' => $live['cpu_count'] === null ? null : $live['cpu_count'] * 100,
            ],
            'memory' => [
                'used_bytes' => $this->total($live['online'], $rows === [], array_column($rows, 'memory_bytes')),
                'allocated_bytes' => array_sum(array_map(fn (Server $server): int => $server->memory, $servers)) * self::MEBIBYTE,
                'capacity_bytes' => $node->memory * self::MEBIBYTE,
            ],
            'disk' => [
                'used_bytes' => $this->total($live['online'], $rows === [], array_column($rows, 'disk_bytes')),
                'allocated_bytes' => array_sum(array_map(fn (Server $server): int => $server->disk, $servers)) * self::MEBIBYTE,
                'capacity_bytes' => $node->disk * self::MEBIBYTE,
            ],
            'servers_online' => count(array_filter($rows, fn (array $row): bool => $row['state'] === 'running')),
            'servers_total' => count($rows),
            'servers' => $rows,
        ];
    }

    /**
     * @template T of int|float
     *
     * @param  array<int, T|null>  $values
     * @return list<T>
     */
    private function known(array $values): array
    {
        return array_values(array_filter($values, fn (int|float|null $value): bool => $value !== null));
    }

    /**
     * The sum of the readings there are; null when the node is offline or none of its servers has one.
     *
     * @param  array<int, int|null>  $values
     */
    private function total(bool $online, bool $empty, array $values): ?int
    {
        $known = $this->known($values);
        if (! $online || (! $empty && $known === [])) {
            return null;
        }

        return array_sum($known);
    }

    /**
     * Each node's live reading, from cache when it is fresh; stale ones are refreshed in one round.
     *
     * @param  list<Node>  $nodes
     * @param  array<int, list<Server>>  $servers
     * @return array<int, LiveReading>
     */
    private function readings(array $nodes, array $servers): array
    {
        $readings = $this->cached($nodes);
        if (count($readings) === count($nodes)) {
            return $readings;
        }

        $refresh = function () use ($nodes, $servers): array {
            // Another request may have refreshed them while this one waited for the lock.
            $readings = $this->cached($nodes);
            $stale = array_values(array_filter($nodes, fn (Node $node): bool => ! isset($readings[$node->id])));

            foreach ($this->read($stale, $servers) as $id => $reading) {
                Cache::put("insights:node:{$id}", $reading, now()->addSeconds($reading['online'] ? self::ONLINE_CACHE_SECONDS : self::OFFLINE_CACHE_SECONDS));
                $readings[$id] = $reading;
            }

            return $readings;
        };

        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);

        try {
            $lock->block(self::LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            // The refresh holding the lock is taking too long; read for this request rather than wait longer.
        }

        try {
            return $refresh();
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  list<Node>  $nodes
     * @return array<int, LiveReading>
     */
    private function cached(array $nodes): array
    {
        $readings = [];
        foreach ($nodes as $node) {
            /** @var LiveReading|null $reading */
            $reading = Cache::get("insights:node:{$node->id}");
            if (is_array($reading)) {
                $readings[$node->id] = $reading;
            }
        }

        return $readings;
    }

    /**
     * @param  list<Node>  $nodes
     * @param  array<int, list<Server>>  $servers
     * @return array<int, LiveReading>
     */
    private function read(array $nodes, array $servers): array
    {
        $offline = ['online' => false, 'cpu_count' => null, 'servers' => []];

        /** @var array<int, array{url: string, token: string}> $clients */
        $clients = [];
        /** @var array<int, LiveReading> $readings */
        $readings = [];
        foreach ($nodes as $node) {
            try {
                $clients[$node->id] = ['url' => $node->getConnectionAddress(), 'token' => $node->getDecryptedKey()];
            } catch (Throwable) {
                // An undecryptable token or a malformed address leaves the node unreadable, not the page broken.
                $readings[$node->id] = $offline;
            }
        }

        $systems = $this->pool(array_map(fn (array $client): array => [$client, '/api/system'], $clients));

        $requests = [];
        foreach ($clients as $id => $client) {
            if (! is_array($systems[$id] ?? null)) {
                $readings[$id] = $offline;

                continue;
            }

            $readings[$id] = ['online' => true, 'cpu_count' => $this->cpuCount($systems[$id]), 'servers' => []];
            foreach ($servers[$id] ?? [] as $server) {
                $requests["{$id}:{$server->uuid}"] = [$client, "/api/servers/{$server->uuid}"];
            }
        }

        $answered = [];
        foreach ($this->pool($requests) as $key => $details) {
            [$id, $uuid] = explode(':', $key, 2);
            $reading = is_array($details) ? $this->serverReading($details) : null;
            if ($reading !== null) {
                $answered[(int) $id][$uuid] = $reading;
            }
        }

        foreach ($answered as $id => $found) {
            if (isset($readings[$id])) {
                $readings[$id]['servers'] = $found;
            }
        }

        return $readings;
    }

    /**
     * Sends every request at once and decodes the JSON objects that came back; failures decode to null.
     *
     * @template K of array-key
     *
     * @param  array<K, array{array{url: string, token: string}, string}>  $requests
     * @return array<K, array<mixed>|null>
     */
    private function pool(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        $results = Http::pool(function (Pool $pool) use ($requests): array {
            $sent = [];
            foreach ($requests as $key => [$client, $path]) {
                $sent[] = $pool->as((string) $key)
                    ->baseUrl($client['url'])
                    ->withToken($client['token'])
                    ->acceptJson()
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                    ->withOptions(['verify' => App::isProduction()])
                    ->get($path);
            }

            return $sent;
        });

        $decoded = [];
        foreach (array_keys($requests) as $key) {
            $response = $results[(string) $key] ?? null;
            $json = $response instanceof Response && $response->successful() ? $response->json() : null;
            $decoded[$key] = is_array($json) ? $json : null;
        }

        return $decoded;
    }

    /**
     * Wings omits what it has not measured; a missing value stays unknown rather than reading as zero.
     *
     * @param  array<mixed>  $details
     * @return ServerReading|null
     */
    private function serverReading(array $details): ?array
    {
        $state = $details['state'] ?? null;
        if (! is_string($state)) {
            return null;
        }

        $utilization = is_array($details['utilization'] ?? null) ? $details['utilization'] : [];
        $cpu = $utilization['cpu_absolute'] ?? null;
        $memory = $utilization['memory_bytes'] ?? null;
        $disk = $utilization['disk_bytes'] ?? null;

        return [
            'state' => $state,
            'cpu' => is_int($cpu) || is_float($cpu) ? round((float) $cpu, 1) : null,
            'memory_bytes' => is_int($memory) ? $memory : null,
            'disk_bytes' => is_int($disk) ? $disk : null,
        ];
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
}
