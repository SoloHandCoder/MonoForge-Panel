<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Resources\Api\Admin;

use Illuminate\Http\Request;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseField;
use Pterodactyl\Models\Billing\ServerTunnel;

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
#[ResponseField('imported_at', description: 'When the tunnel, made by hand on playit.gg, was linked by an import. The panel leaves imported tunnels alone on playit.', example: null, nullable: true)]
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
            'created_at' => $this->formatTimestamp($tunnel->created_at),
            'updated_at' => $this->formatTimestamp($tunnel->updated_at),
        ];
    }
}
