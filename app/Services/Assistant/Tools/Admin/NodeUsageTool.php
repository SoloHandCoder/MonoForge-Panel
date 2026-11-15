<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools\Admin;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolResult;
use Pterodactyl\Services\Insights\NodeUsageService;

/**
 * Live use of every node, from the same service as the Insights page. Nodes are named by id
 * and name only, never by address.
 */
final class NodeUsageTool extends AdminTool
{
    private const int MEBIBYTE = 1_048_576;

    /** The busiest servers listed per node. */
    private const int TOP_SERVERS = 10;

    public function __construct(private readonly NodeUsageService $usage) {}

    public function name(): string
    {
        return 'node_usage';
    }

    public function description(): string
    {
        return 'Get the live CPU, memory and disk use of every node against its capacity, how many servers run on it, and its busiest servers.';
    }

    public function parameters(): array
    {
        return $this->schema();
    }

    public function describeCall(array $arguments): string
    {
        return 'Check node usage';
    }

    protected function permissions(): array
    {
        return [Permissions::AdminNodesRead];
    }

    protected function run(array $arguments, ToolContext $context): ToolResult
    {
        $nodes = [];
        foreach ($this->usage->nodes() as $node) {
            $servers = $node['servers'];
            usort($servers, fn (array $a, array $b): int => ($b['memory_bytes'] ?? 0) <=> ($a['memory_bytes'] ?? 0));

            $nodes[] = [
                'id' => $node['id'],
                'name' => $node['name'],
                'status' => $node['status'],
                'cpu_percent' => ['used' => $node['cpu']['used'], 'capacity' => $node['cpu']['capacity']],
                'memory_mib' => [
                    'used' => $this->mib($node['memory']['used_bytes']),
                    'allocated' => $this->mib($node['memory']['allocated_bytes']),
                    'capacity' => $this->mib($node['memory']['capacity_bytes']),
                ],
                'disk_mib' => [
                    'used' => $this->mib($node['disk']['used_bytes']),
                    'allocated' => $this->mib($node['disk']['allocated_bytes']),
                    'capacity' => $this->mib($node['disk']['capacity_bytes']),
                ],
                'servers_online' => $node['servers_online'],
                'servers_total' => $node['servers_total'],
                'busiest_servers' => array_map(fn (array $server): array => [
                    'id' => $server['id'],
                    'name' => $server['name'],
                    'state' => $server['state'],
                    'cpu_percent' => $server['cpu'],
                    'memory_mib' => $this->mib($server['memory_bytes']),
                    'disk_mib' => $this->mib($server['disk_bytes']),
                ], array_slice($servers, 0, self::TOP_SERVERS)),
            ];
        }

        return new ToolResult($this->json(['nodes' => $nodes]), ['nodes' => count($nodes)]);
    }

    private function mib(?int $bytes): ?int
    {
        return $bytes === null ? null : intdiv($bytes, self::MEBIBYTE);
    }
}
