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
use Pterodactyl\Http\Requests\Api\Admin\Tunnels\ImportTunnelsRequest;
use Pterodactyl\Http\Requests\Api\Admin\Tunnels\StoreTunnelRequest;
use Pterodactyl\Http\Requests\Api\Admin\Tunnels\UpdateTunnelRequest;
use Pterodactyl\Http\Resources\Api\Admin\ServerTunnelResource;
use Pterodactyl\Http\Resources\Api\ApiResourceCollection;
use Pterodactyl\Models\Billing\ServerTunnel;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Tunnels\TunnelImportService;
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
            'imported_at' => null,
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

    private const array IMPORT_EXAMPLE = [
        'imported' => 1,
        'skipped' => 2,
        'errors' => [
            ['node_id' => 2, 'node_name' => 'node-2', 'message' => 'This node has no playit.gg agent set up.'],
        ],
        'results' => [
            [
                'node_id' => 1,
                'node_name' => 'node-1',
                'playit_tunnel_id' => '0c8f1f4e-6f7b-4a2e-9a1d-2f6e3c1b7a90',
                'name' => 'survival',
                'local_ip' => '127.0.0.1',
                'local_port' => 25565,
                'address' => 'xyz.joinmc.link',
                'result' => 'imported',
                'reason' => 'Linked to Survival world.',
                'server_ids' => [12],
            ],
            [
                'node_id' => 1,
                'node_name' => 'node-1',
                'playit_tunnel_id' => '7d2c6b44-3c1f-4e8a-9a51-1f0e5b9d1b0e',
                'name' => null,
                'local_ip' => '127.0.0.1',
                'local_port' => 25600,
                'address' => 'abc.ply.gg:40123',
                'result' => 'no_match',
                'reason' => 'No server on node-1 uses port 25600.',
                'server_ids' => [],
            ],
            [
                'node_id' => 1,
                'node_name' => 'node-1',
                'playit_tunnel_id' => '9a1d2f6e-6f7b-4a2e-0c8f-1f4e3c1b7a90',
                'name' => 'lobby',
                'local_ip' => '127.0.0.1',
                'local_port' => 25577,
                'address' => 'def.ply.gg:41000',
                'result' => 'ambiguous',
                'reason' => '2 servers on node-1 use port 25577, so the panel cannot tell which one the tunnel is for.',
                'server_ids' => [14, 15],
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
    #[Endpoint('Recreate tunnel', "Deletes the tunnel on playit.gg, an imported one included, and creates a new one for the server's current primary port. The server gets a new address.")]
    #[ScribeResponse(self::TUNNEL_EXAMPLE, description: 'Tunnel recreated or queued.')]
    public function recreate(UpdateTunnelRequest $request, ServerTunnel $tunnel, TunnelService $tunnels): ServerTunnelResource
    {
        // The administrator asked for it, so an imported tunnel is deleted on playit too.
        return ServerTunnelResource::make($this->checkNow($tunnels, $tunnels->recreate($tunnel, deleteImported: true)));
    }

    /**
     * Import tunnels made by hand on playit.gg.
     */
    #[Endpoint('Import existing tunnels', "Reads the tunnels of each node's playit.gg agent and links every tunnel whose local port (and local IP, when it is a real address) matches the primary allocation of a server without a tunnel. Linked tunnels are marked imported: the panel never changes or deletes them on playit, except when their server is deleted or an administrator recreates them. Nothing is created on playit, and running it again links nothing twice. `result` is one of `imported`, `already_linked`, `server_has_tunnel`, `no_match`, `ambiguous` (several servers use the port) or `unsupported`; `errors` lists nodes whose agent could not be read.")]
    #[QueryParam('node', 'integer', 'Only import the tunnels of this node.', required: false, example: 1)]
    #[ScribeResponse(self::IMPORT_EXAMPLE, description: 'Import summary returned.')]
    public function import(ImportTunnelsRequest $request, TunnelImportService $importer): JsonResponse
    {
        $node = $request->filled('node') ? Node::query()->findOrFail($request->integer('node')) : null;

        return new JsonResponse($importer->import($node));
    }

    /** The administrator is waiting, so move the tunnel on now instead of on the queue. */
    private function checkNow(TunnelService $tunnels, ServerTunnel $tunnel): ServerTunnel
    {
        return $tunnel->status === TunnelStatus::Failed && $tunnel->last_error === TunnelService::NOT_CONFIGURED
            ? $tunnel
            : $tunnels->sync($tunnel);
    }
}
