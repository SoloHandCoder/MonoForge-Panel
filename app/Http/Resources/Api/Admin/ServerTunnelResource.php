<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Resources\Api\Admin;

use Illuminate\Http\Request;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseField;
use Pterodactyl\Models\Billing\ServerTunnel;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Entrance\MinecraftEntrance;

/**
 * @extends AdminResource<ServerTunnel>
 */
#[ResponseField('server_name', example: 'Survival world', nullable: true)]
#[ResponseField('server_identifier', example: '1a2b3c4d', nullable: true)]
#[ResponseField('node_id', 'integer', example: 1, nullable: true)]
#[ResponseField('node_name', example: 'node-1', nullable: true)]
#[ResponseField('playit_tunnel_id', example: '0c8f1f4e-6f7b-4a2e-9a1d-2f6e3c1b7a90', nullable: true)]
#[ResponseField('address', example: 'xyz.joinmc.link', nullable: true)]
#[ResponseField('subdomain', example: 'survival', nullable: true)]
#[ResponseField('status', example: 'active', enum: ['pending', 'active', 'failed', 'deleted'])]
#[ResponseField('last_error', example: null, nullable: true)]
#[ResponseField('port_key', description: 'Which tunnel of the server this is: `game` for the main one, otherwise the key of an extra port of its game.', example: 'game')]
#[ResponseField('label', description: 'The name of the extra port, such as "Voice chat"; null for the main tunnel.', example: null, nullable: true)]
#[ResponseField('protocol', description: 'tcp, udp or both for an extra port; null for the main tunnel, whose protocol follows the game.', example: null, nullable: true)]
#[ResponseField('hold_reason', description: 'Why the scheduler leaves this tunnel alone: `not_a_game` (its egg belongs to no game that creates tunnels) or `tunnel_limit` (the playit account is full; retried after `retry_after`).', example: null, enum: ['not_a_game', 'tunnel_limit', null], nullable: true)]
#[ResponseField('retry_after', description: 'When a held tunnel is tried again, or null.', example: null, nullable: true)]
#[ResponseField('imported_at', description: 'When the tunnel, made by hand on playit.gg, was linked by an import. The panel leaves imported tunnels alone on playit.', example: null, nullable: true)]
#[ResponseField('replaced_by_gateway', 'boolean', description: "True while the server's node is in game gateway mode: the tunnel is left on playit.gg but inactive, players join through the gateway, and nothing is synced or retried until the gateway is turned off.", example: false)]
#[ResponseField('replaced_by_entrance', 'boolean', description: "True for the main tunnel of a Minecraft server joined through its node's shared Minecraft entrance: it is left on playit.gg but inactive, and nothing is synced or retried while the entrance is on.", example: false)]
class ServerTunnelResource extends AdminResource
{
    public function resourceName(): string
    {
        return ServerTunnel::RESOURCE_NAME;
    }

    public function toArray(Request $request): array
    {
        $tunnel = $this->resource;
        $tunnel->loadMissing('server.node');

        $server = $tunnel->server;

        return [
            'id' => $tunnel->id,
            'server_id' => $tunnel->server_id,
            'server_name' => $server?->name,
            'server_identifier' => $server?->uuidShort,
            'node_id' => $server?->node_id,
            'node_name' => $server?->node->name,
            'playit_tunnel_id' => $tunnel->playit_tunnel_id,
            'address' => $tunnel->address,
            'subdomain' => $tunnel->subdomain,
            'status' => $tunnel->status->value,
            'last_error' => $tunnel->last_error,
            'imported_at' => $tunnel->imported_at === null ? null : $this->formatTimestamp($tunnel->imported_at),
            'port_key' => $tunnel->port_key,
            'label' => $tunnel->label,
            'protocol' => $tunnel->protocol,
            'hold_reason' => $tunnel->hold_reason,
            'retry_after' => $tunnel->retry_after === null ? null : $this->formatTimestamp($tunnel->retry_after),
            'replaced_by_gateway' => $server?->node->hasGateway() ?? false,
            'replaced_by_entrance' => $server instanceof Server && $tunnel->isMain() && MinecraftEntrance::isRouted($server),
            'created_at' => $this->formatTimestamp($tunnel->created_at),
            'updated_at' => $this->formatTimestamp($tunnel->updated_at),
        ];
    }
}
