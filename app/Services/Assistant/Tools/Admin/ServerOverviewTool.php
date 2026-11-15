<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools\Admin;

use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Billing\ServerSubscription;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolResult;
use Pterodactyl\Services\Tunnels\TunnelService;

/**
 * One server in detail: owner, node, egg, limits, live state, subscription and counts.
 * Allocations are given as ports only.
 */
final class ServerOverviewTool extends AdminTool
{
    public function __construct(
        private readonly ReadsServerState $state,
        private readonly TunnelService $tunnels,
    ) {}

    public function name(): string
    {
        return 'server_overview';
    }

    public function description(): string
    {
        return 'Get the details of one server: owner, node, egg, limits, live state, ports, subscription, and counts of subusers, backups, databases and schedules.';
    }

    public function parameters(): array
    {
        return $this->schema([
            'server' => ['type' => 'string', 'description' => 'The server id, UUID or short UUID (from list_servers).'],
        ], ['server']);
    }

    public function describeCall(array $arguments): string
    {
        return 'Look up server '.$this->labelArgument($arguments, 'server');
    }

    protected function permissions(): array
    {
        return [Permissions::AdminServersRead];
    }

    protected function run(array $arguments, ToolContext $context): ToolResult
    {
        $server = $this->findServer($arguments);
        $server->loadMissing(['user', 'node', 'egg', 'allocations']);

        try {
            $live = $this->state->read($server);
            $state = ['state' => $live->state, 'memory_mib' => round($live->memoryBytes / 1_048_576, 1), 'cpu_percent' => round((float) $live->cpuAbsolute, 1), 'disk_mib' => round($live->diskBytes / 1_048_576, 1)];
        } catch (DaemonConnectionException) {
            $state = ['state' => 'unknown (the node could not be reached)'];
        }

        $subscription = ServerSubscription::query()->where('server_id', $server->id)->latest('id')->first();

        $data = [
            ...ListServersTool::summary($server),
            'uuid' => $server->uuid,
            'description' => $server->description,
            'owner_email' => $server->user->email,
            'egg' => $server->egg?->name,
            'docker_image' => $server->image,
            'limits' => [
                'swap_mib' => $server->swap,
                'io' => $server->io,
                'databases' => $server->database_limit,
                'backups' => $server->backup_limit,
                'allocations' => $server->allocation_limit,
            ],
            'live' => $state,
            'ports' => $server->allocations->map(fn (Allocation $allocation): array => ['port' => $allocation->port, 'primary' => $allocation->id === $server->allocation_id])->values()->all(),
            'public_address' => $this->tunnels->displayAddress($server),
            'counts' => [
                'subusers' => $server->subusers()->count(),
                'backups' => $server->backups()->count(),
                'databases' => $server->databases()->count(),
                'schedules' => $server->schedules()->count(),
            ],
            'subscription' => $subscription instanceof ServerSubscription ? [
                'status' => $subscription->status,
                'price_tokens' => $subscription->price_tokens,
                'period_days' => $subscription->period_days,
                'auto_renew' => $subscription->auto_renew,
                'next_renewal_at' => $subscription->next_renewal_at->toAtomString(),
            ] : null,
            'created_at' => $server->created_at?->toAtomString(),
            'installed_at' => $server->installed_at?->toAtomString(),
        ];

        return new ToolResult($this->json($data), ['id' => $server->id, 'name' => $server->name]);
    }
}
