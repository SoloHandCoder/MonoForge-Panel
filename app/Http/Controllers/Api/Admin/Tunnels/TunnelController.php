<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Tunnels;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\QueryParam;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Enum\TunnelStatus;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Tunnels\GetTunnelsRequest;
use Pterodactyl\Http\Requests\Api\Admin\Tunnels\StoreTunnelRequest;
use Pterodactyl\Http\Requests\Api\Admin\Tunnels\UpdateTunnelRequest;
use Pterodactyl\Http\Resources\Api\Admin\ServerTunnelResource;
use Pterodactyl\Http\Resources\Api\ApiResourceCollection;
use Pterodactyl\Models\Billing\ServerTunnel;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Tunnels\TunnelService;

#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Tunnels', 'The playit.gg tunnels that give servers their public address.')]
class TunnelController extends AdminApiController
{
    private const array TUNNEL_EXAMPLE = [
        'object' => 'server_tunnel',
        'attributes' => [
            'id' => 1,
            'server_id' => 12,
            'server_name' => 'Survival world',
            'server_identifier' => '1a2b3c4d',
            'node_id' => 1,
            'node_name' => 'node-1',
            'playit_tunnel_id' => '0c8f1f4e-6f7b-4a2e-9a1d-2f6e3c1b7a90',
            'address' => 'xyz.joinmc.link',
            'subdomain' => null,
            'status' => 'active',
            'last_error' => null,
            'created_at' => '2026-11-16T10:00:00+00:00',
            'updated_at' => '2026-11-16T10:00:20+00:00',
        ],
    ];

    private const array LIST_EXAMPLE = [
        'object' => 'list',
        'data' => [self::TUNNEL_EXAMPLE],
        'meta' => [
            'pagination' => [
                'total' => 1,
                'count' => 1,
                'per_page' => 50,
                'current_page' => 1,
                'total_pages' => 1,
                'links' => [],
            ],
        ],
    ];

    /**
     * List tunnels.
     */
    #[Endpoint('List tunnels', "Returns every server's playit.gg tunnel with its status and last error, newest first.")]
    #[QueryParam('status', 'string', 'Only tunnels with this status.', required: false, example: 'failed', enum: ['pending', 'active', 'failed', 'deleted'])]
    #[QueryParam('page', 'integer', 'Page number to return.', required: false, example: 1)]
    #[QueryParam('per_page', 'integer', 'Results to return per page.', required: false, example: 50)]
    #[ScribeResponse(self::LIST_EXAMPLE, description: 'Tunnels returned.')]
    public function index(GetTunnelsRequest $request): ApiResourceCollection
    {
        $tunnels = ServerTunnel::query()
            ->with('server.node')
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')->toString()))
            ->latest('id')
            ->paginate($request->perPage());

        return ServerTunnelResource::collection($tunnels);
    }

    /**
     * Create a tunnel for a server.
     */
    #[Endpoint('Create tunnel', 'Creates a playit.gg tunnel for a server that has none, such as one created before its node had an agent. A server that already has one keeps it.')]
    #[ScribeResponse(self::TUNNEL_EXAMPLE, status: 201, description: 'Tunnel created or queued.')]
    public function store(StoreTunnelRequest $request, TunnelService $tunnels): JsonResponse
    {
        $server = Server::query()->findOrFail($request->integer('server_id'));
        $tunnel = $tunnels->provision($server);

        return ServerTunnelResource::make($this->checkNow($tunnels, $tunnel))->respond(Response::HTTP_CREATED);
    }

    /**
     * Retry a tunnel now.
     */
    #[Endpoint('Retry tunnel', 'Creates the tunnel on playit.gg if it has not been yet, then reads back its address, without waiting for the scheduler.')]
    #[ScribeResponse(self::TUNNEL_EXAMPLE, description: 'Tunnel checked.')]
    public function retry(UpdateTunnelRequest $request, ServerTunnel $tunnel, TunnelService $tunnels): ServerTunnelResource
    {
        $tunnel->load('server');
        if ($tunnel->status === TunnelStatus::Deleted && $tunnel->server instanceof Server) {
            $tunnel = $tunnels->provision($tunnel->server);
        }

        return ServerTunnelResource::make($this->checkNow($tunnels, $tunnel));
    }

    /**
     * Recreate a tunnel.
     */
    #[Endpoint('Recreate tunnel', "Deletes the tunnel on playit.gg and creates a new one for the server's current primary port. The server gets a new address.")]
    #[ScribeResponse(self::TUNNEL_EXAMPLE, description: 'Tunnel recreated or queued.')]
    public function recreate(UpdateTunnelRequest $request, ServerTunnel $tunnel, TunnelService $tunnels): ServerTunnelResource
    {
        return ServerTunnelResource::make($this->checkNow($tunnels, $tunnels->recreate($tunnel)));
    }

    /** The administrator is waiting, so move the tunnel on now instead of on the queue. */
    private function checkNow(TunnelService $tunnels, ServerTunnel $tunnel): ServerTunnel
    {
        return $tunnel->status === TunnelStatus::Failed && $tunnel->last_error === TunnelService::NOT_CONFIGURED
            ? $tunnel
            : $tunnels->sync($tunnel);
    }
}
