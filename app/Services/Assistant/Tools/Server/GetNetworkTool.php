<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools\Server;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolResult;
use Pterodactyl\Services\Tunnels\TunnelService;

/**
 * The server's ports. Node addresses are never handed out: when the server has a live playit
 * tunnel its public address is given instead.
 */
final class GetNetworkTool extends ServerTool
{
    public function __construct(private readonly TunnelService $tunnels) {}

    public function name(): string
    {
        return 'get_network';
    }

    public function description(): string
    {
        return "Get the server's network ports (which one is primary, and their notes) and the public address players connect to, when the server has one.";
    }

    public function parameters(): array
    {
        return $this->schema();
    }

    public function describeCall(array $arguments): string
    {
        return 'Read the network settings';
    }

    protected function permissions(): array
    {
        return [Permissions::AllocationRead];
    }

    protected function run(array $arguments, ToolContext $context): ToolResult
    {
        $server = $this->server($context);
        $address = $this->tunnels->displayAddress($server);

        $allocations = $server->allocations()->orderBy('port')->get()->map(fn (Allocation $allocation): array => [
            'port' => $allocation->port,
            'primary' => $allocation->id === $server->allocation_id,
            'notes' => $allocation->notes,
        ])->all();

        $data = [
            'public_address' => $address,
            'note' => $address === null
                ? 'The connection address is shown on the server page; it is not given to the assistant.'
                : 'Players connect through the public address.',
            'allocation_limit' => $server->allocation_limit,
            'allocations' => $allocations,
        ];

        return new ToolResult($this->json($data), ['public_address' => $address, 'count' => count($allocations)]);
    }
}
