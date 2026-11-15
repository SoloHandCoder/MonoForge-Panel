<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\QueryParam;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Contracts\Servers\ReadsServerStates;
use Pterodactyl\Data\ServerState;
use Pterodactyl\Data\ServerStateUnavailable;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseField;
use Pterodactyl\Http\Requests\Api\Client\GetServerResourcesRequest;
use Pterodactyl\Http\Resources\Api\Client\StatsResource;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use stdClass;

#[Group('Client API', 'Endpoints authenticated as a panel user using a client API token.')]
#[Subgroup('Server Overview', 'Inspect and control an accessible server.')]
class ServerResourcesController extends ClientApiController
{
    public const string RESOURCE_NAME = 'server_resources';

    private const array EXAMPLE = [
        'object' => self::RESOURCE_NAME,
        'data' => [
            '1a7ce997' => [
                'object' => 'stats',
                'attributes' => [
                    'current_state' => 'running',
                    'is_suspended' => false,
                    'resources' => [
                        'memory_bytes' => 536870912,
                        'cpu_absolute' => 12.5,
                        'disk_bytes' => 2147483648,
                        'network_rx_bytes' => 102400,
                        'network_tx_bytes' => 204800,
                        'uptime' => 3600000,
                    ],
                ],
            ],
            '5c9b1e2f' => [
                'object' => 'error',
                'attributes' => [
                    'code' => ServerStateUnavailable::DAEMON_ERROR,
                    'status' => 504,
                    'detail' => 'Could not establish a connection to the machine running this server. Please try again.',
                ],
            ],
        ],
    ];

    /**
     * Return the resource utilization of many servers at once, for the dashboard's server
     * cards. Readings are shared with the single server resources endpoint and cached for up
     * to 20 seconds; a failed Wings call is remembered for 10 seconds.
     */
    #[Endpoint('List server resources', 'Returns the latest resource utilization of up to 50 accessible servers in one request, keyed by the identifier each was requested with. A server the user cannot access is left out. A server whose state cannot be read (suspended, under maintenance, installing, restoring a backup, being transferred, or its node not answering) gets an error entry instead, without failing the others.')]
    #[QueryParam('servers', 'string', 'Comma-separated server identifiers: full UUIDs, short UUIDs or identifiers. At most 50 servers.', required: true, example: '1a7ce997,5c9b1e2f')]
    #[ScribeResponse(self::EXAMPLE, description: 'Resource utilization returned for every accessible server requested.')]
    #[ResponseField('object', 'string', 'Always `server_resources`.', example: self::RESOURCE_NAME)]
    #[ResponseField('data', 'object', 'One entry per accessible server, keyed by the identifier it was requested with: its `stats`, or an `error` explaining why its utilization could not be read.')]
    public function __invoke(GetServerResourcesRequest $request, ReadsServerStates $states): JsonResponse
    {
        $identifiers = $request->identifiers();
        $servers = $this->accessibleServers($request->user(), $identifiers);

        $lookup = [];
        foreach ($servers as $server) {
            $lookup[$server->uuid] = $server;
            $lookup[$server->uuidShort] = $server;
            $lookup[$server->identifier] = $server;
        }

        $unavailable = [];
        $readable = [];
        foreach ($servers as $server) {
            $state = ServerStateUnavailable::forServer($server);
            if ($state instanceof ServerStateUnavailable) {
                $unavailable[$server->uuid] = $state;
            } else {
                $readable[] = $server;
            }
        }

        $results = [...$unavailable, ...$states->read($readable)];

        $data = [];
        foreach ($identifiers as $identifier) {
            $server = $lookup[$identifier] ?? null;
            $result = $server instanceof Server ? ($results[$server->uuid] ?? null) : null;

            $data[$identifier] = match (true) {
                $result instanceof ServerState => StatsResource::make($result)->resolve($request),
                $result instanceof ServerStateUnavailable => ['object' => 'error', 'attributes' => $result->toArray()],
                default => null,
            };
        }

        $data = array_filter($data, fn (?array $entry): bool => $entry !== null);

        return new JsonResponse(['object' => self::RESOURCE_NAME, 'data' => $data === [] ? new stdClass : $data]);
    }

    /**
     * The requested servers the user may read the resources of: as the single resources
     * endpoint allows, its owner, a subuser of it with any permissions, or a root admin.
     *
     * @param  list<string>  $identifiers
     * @return Collection<int, Server>
     */
    private function accessibleServers(User $user, array $identifiers): Collection
    {
        $uuids = [];
        $shorts = [];
        $realtime = [];
        foreach ($identifiers as $identifier) {
            match (true) {
                str_starts_with($identifier, 'serv_') => $realtime[] = $identifier,
                mb_strlen($identifier) === 8 => $shorts[] = $identifier,
                default => $uuids[] = $identifier,
            };
        }

        return Server::query()
            ->without('allocation')
            ->with(['node', 'transfer'])
            ->where(function (Builder $query) use ($uuids, $shorts, $realtime): void {
                $query->whereIn('uuid', $uuids)->orWhereIn('uuidShort', $shorts);
                foreach ($realtime as $identifier) {
                    $query->orWhere(fn (Builder $inner) => $inner->whereIdentifier($identifier));
                }
            })
            ->unless($user->root_admin, fn (Builder $query) => $query->where(
                fn (Builder $inner) => $inner->where('owner_id', $user->id)
                    ->orWhereHas('subusers', fn (Builder $subusers) => $subusers->where('user_id', $user->id)),
            ))
            ->get();
    }
}
