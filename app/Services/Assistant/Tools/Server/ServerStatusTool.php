<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools\Server;

use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolResult;

final class ServerStatusTool extends ServerTool
{
    private const int MEBIBYTE = 1_048_576;

    public function __construct(private readonly ReadsServerState $state) {}

    public function name(): string
    {
        return 'server_status';
    }

    public function description(): string
    {
        return "Get the server's current state (running, offline...), live resource use (memory, CPU, disk, uptime) and its limits.";
    }

    public function parameters(): array
    {
        return $this->schema();
    }

    public function describeCall(array $arguments): string
    {
        return 'Check the server status';
    }

    protected function permissions(): array
    {
        return [];
    }

    protected function availableWhileUnavailable(): bool
    {
        return true;
    }

    protected function run(array $arguments, ToolContext $context): ToolResult
    {
        $server = $this->server($context);
        $state = $this->state->read($server);

        $data = [
            'name' => $server->name,
            'state' => $state->state,
            'status' => $server->status ?? 'installed',
            'suspended' => $server->isSuspended(),
            'resources' => [
                'memory_mib' => round($state->memoryBytes / self::MEBIBYTE, 1),
                'cpu_percent' => round((float) $state->cpuAbsolute, 1),
                'disk_mib' => round($state->diskBytes / self::MEBIBYTE, 1),
                'network_rx_bytes' => $state->networkRxBytes,
                'network_tx_bytes' => $state->networkTxBytes,
                'uptime_seconds' => intdiv($state->uptime, 1000),
            ],
            'limits' => [
                'memory_mib' => $server->memory > 0 ? $server->memory : 'unlimited',
                'swap_mib' => $server->swap,
                'disk_mib' => $server->disk > 0 ? $server->disk : 'unlimited',
                'cpu_percent' => $server->cpu > 0 ? $server->cpu : 'unlimited',
                'databases' => $server->database_limit,
                'backups' => $server->backup_limit,
                'allocations' => $server->allocation_limit,
            ],
        ];

        return new ToolResult($this->json($data), ['state' => $state->state, 'suspended' => $server->isSuspended()]);
    }
}
