<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Tunnels;

use Illuminate\Database\Eloquent\Collection;
use Pterodactyl\Enum\TunnelStatus;
use Pterodactyl\Exceptions\Tunnels\PlayitException;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Billing\ServerTunnel;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;

/**
 * Links tunnels made by hand in the playit.gg dashboard, before the panel managed them, to the
 * servers they point at. A tunnel matches a server when its local port (and its local IP, when
 * that is a real address) is the server's primary allocation on the node the agent runs on.
 *
 * Nothing is created, changed or deleted on playit; the import only reads the agent's tunnel
 * list. Running it again links nothing twice.
 *
 * @phpstan-type ImportResult array{
 *     node_id: int,
 *     node_name: string,
 *     playit_tunnel_id: string|null,
 *     name: string|null,
 *     local_ip: string|null,
 *     local_port: int|null,
 *     address: string|null,
 *     result: string,
 *     reason: string,
 *     server_ids: list<int>,
 * }
 * @phpstan-type UnlinkedServer array{
 *     server_id: int,
 *     server_name: string,
 *     server_identifier: string,
 *     node_id: int,
 *     node_name: string,
 *     local_ip: string,
 *     local_port: int,
 *     tunnel_status: string|null,
 * }
 * @phpstan-type UnlinkedSummary array{
 *     nodes: list<array{id: int, name: string, read_only: bool}>,
 *     servers: list<UnlinkedServer>,
 * }
 * @phpstan-type ImportSummary array{
 *     imported: int,
 *     skipped: int,
 *     errors: list<array{node_id: int, node_name: string, message: string}>,
 *     results: list<ImportResult>,
 * }
 */
class TunnelImportService
{
    public const string IMPORTED = 'imported';

    public const string ALREADY_LINKED = 'already_linked';

    public const string SERVER_HAS_TUNNEL = 'server_has_tunnel';

    public const string NO_MATCH = 'no_match';

    public const string AMBIGUOUS = 'ambiguous';

    public const string UNSUPPORTED = 'unsupported';

    /** Local IPs that mean "this machine" and so match an allocation on any IP. */
    private const array ANY_ADDRESS = ['0.0.0.0', '::', '127.0.0.1', '::1', 'localhost'];

    public function __construct(private readonly PlayitClient $client, private readonly TunnelService $tunnels) {}

    /**
     * Import the tunnels of every node with a playit agent, or of one node.
     *
     * @return ImportSummary
     */
    public function import(?Node $only = null): array
    {
        $nodes = $only instanceof Node
            ? new Collection([$only])
            : Node::query()->whereNotNull('playit_agent_id')->orderBy('id')->get();

        $summary = ['imported' => 0, 'skipped' => 0, 'errors' => [], 'results' => []];

        foreach ($nodes as $node) {
            if (! $node->hasPlayitAgent()) {
                $summary['errors'][] = ['node_id' => $node->id, 'node_name' => $node->name, 'message' => TunnelService::NOT_CONFIGURED];

                continue;
            }

            try {
                $remote = $this->client->agentTunnels((string) $node->playit_secret, (string) $node->playit_agent_id);
            } catch (PlayitException $playitException) {
                $summary['errors'][] = ['node_id' => $node->id, 'node_name' => $node->name, 'message' => $playitException->adminMessage()];

                continue;
            }

            foreach ($this->importNode($node, $remote) as $result) {
                $summary['results'][] = $result;
                $result['result'] === self::IMPORTED ? $summary['imported']++ : $summary['skipped']++;
            }
        }

        return $summary;
    }

    /**
     * The servers on nodes with a playit agent that have no tunnel yet, with the local port (and
     * IP) a tunnel for them needs, so the owner knows what to create in the playit dashboard.
     * Only reads the database.
     *
     * @return UnlinkedSummary
     */
    public function unlinked(): array
    {
        $nodes = Node::query()->whereNotNull('playit_agent_id')->whereNotNull('playit_secret')->orderBy('id')->get()
            ->filter(fn (Node $node): bool => $node->hasPlayitAgent())
            ->values();

        $servers = Server::query()
            ->whereIn('node_id', $nodes->modelKeys())
            ->with(['allocation', 'tunnel', 'node'])
            ->orderBy('node_id')
            ->orderBy('id')
            ->get()
            ->filter(fn (Server $server): bool => $server->allocation instanceof Allocation
                && (! $server->tunnel instanceof ServerTunnel || TunnelService::isReplaceable($server->tunnel)));

        return [
            'nodes' => array_values($nodes->map(fn (Node $node): array => ['id' => $node->id, 'name' => $node->name, 'read_only' => $node->isPlayitReadOnly()])->all()),
            'servers' => array_values($servers->map(fn (Server $server): array => [
                'server_id' => $server->id,
                'server_name' => $server->name,
                'server_identifier' => $server->uuidShort,
                'node_id' => $server->node_id,
                'node_name' => $server->node->name,
                'local_ip' => TunnelService::localIpFor($server->allocation?->ip),
                'local_port' => (int) $server->allocation?->port,
                'tunnel_status' => $server->tunnel?->status->value,
            ])->all()),
        ];
    }

    /**
     * @param  array{node_id: int, node_name: string, playit_tunnel_id: string|null, name: string|null, local_ip: string|null, local_port: int|null, address: string|null}  $base
     * @param  array<int>  $serverIds
     * @return ImportResult
     */
    private function outcome(array $base, string $result, string $reason, array $serverIds = []): array
    {
        return [...$base, 'result' => $result, 'reason' => $reason, 'server_ids' => array_values($serverIds)];
    }

    /**
     * @param  list<array<mixed>>  $remote
     * @return list<ImportResult>
     */
    private function importNode(Node $node, array $remote): array
    {
        $allocations = Allocation::query()
            ->where('node_id', $node->id)
            ->whereNotNull('server_id')
            ->with('server')
            ->get();

        $results = [];
        foreach ($remote as $tunnel) {
            $results[] = $this->importTunnel($node, $tunnel, $allocations);
        }

        return $results;
    }

    /**
     * @param  array<mixed>  $tunnel
     * @param  Collection<int, Allocation>  $allocations
     * @return ImportResult
     */
    private function importTunnel(Node $node, array $tunnel, Collection $allocations): array
    {
        $id = is_string($tunnel['id'] ?? null) ? $tunnel['id'] : null;
        $localIp = PlayitClient::localIp($tunnel);
        $localPort = PlayitClient::localPort($tunnel);
        $agentId = PlayitClient::agentIdOf($tunnel);

        $base = [
            'node_id' => $node->id,
            'node_name' => $node->name,
            'playit_tunnel_id' => $id,
            'name' => is_string($tunnel['name'] ?? null) ? $tunnel['name'] : null,
            'local_ip' => $localIp,
            'local_port' => $localPort,
            'address' => PlayitClient::addressOf($tunnel),
        ];

        if ($id === null || $id === '') {
            return $this->outcome($base, self::UNSUPPORTED, 'playit.gg returned this tunnel without an id.');
        }

        $linked = ServerTunnel::query()->where('playit_tunnel_id', $id)->first();
        if ($linked instanceof ServerTunnel) {
            return $this->outcome($base, self::ALREADY_LINKED, sprintf('Already linked to server #%d.', $linked->server_id), [$linked->server_id]);
        }

        if (! PlayitClient::isAgentTunnel($tunnel) || $localPort === null) {
            return $this->outcome($base, self::UNSUPPORTED, 'The tunnel has no local port to match (for example a managed tunnel), so it cannot be matched to a server.');
        }

        if ($agentId !== null && $agentId !== mb_strtolower((string) $node->playit_agent_id)) {
            return $this->outcome($base, self::UNSUPPORTED, "The tunnel points at another playit agent, not this node's.");
        }

        $matches = $this->matchingAllocations($allocations, $localIp, $localPort);
        if ($matches->isEmpty()) {
            return $this->outcome($base, self::NO_MATCH, sprintf('No server on %s uses port %d%s.', $node->name, $localPort, $localIp !== null && ! in_array($localIp, self::ANY_ADDRESS, true) ? ' on '.$localIp : ''));
        }

        // The tunnel becomes the server's public address, so only a primary port is linked.
        $primary = $matches->filter(fn (Allocation $allocation): bool => $allocation->server?->allocation_id === $allocation->id)->values();
        if ($primary->isEmpty()) {
            $names = $matches->map(fn (Allocation $allocation): string => (string) $allocation->server?->name)->unique()->implode(', ');

            return $this->outcome($base, self::NO_MATCH, sprintf("Port %d is an additional port of %s, not a server's primary port.", $localPort, $names), $matches->map(fn (Allocation $allocation): int => (int) $allocation->server_id)->unique()->values()->all());
        }

        if ($primary->count() > 1) {
            return $this->outcome($base, self::AMBIGUOUS, sprintf('%d servers on %s use port %d, so the panel cannot tell which one the tunnel is for.', $primary->count(), $node->name, $localPort), $primary->map(fn (Allocation $allocation): int => (int) $allocation->server_id)->all());
        }

        $allocation = $primary->sole();
        $server = $allocation->server;
        if (! $server instanceof Server) {
            return $this->outcome($base, self::NO_MATCH, sprintf('No server on %s uses port %d.', $node->name, $localPort));
        }

        $existing = ServerTunnel::query()->where('server_id', $server->id)->first();
        if ($existing instanceof ServerTunnel && ! TunnelService::isReplaceable($existing)) {
            return $this->outcome($base, self::SERVER_HAS_TUNNEL, sprintf('%s already has a %s tunnel.', $server->name, $existing->status === TunnelStatus::Active ? 'live' : $existing->status->value), [$server->id]);
        }

        $adopted = $this->tunnels->adopt($server, $allocation->id, $tunnel);
        if (! $adopted instanceof ServerTunnel) {
            return $this->outcome($base, self::SERVER_HAS_TUNNEL, sprintf('%s got a tunnel of its own while importing.', $server->name), [$server->id]);
        }

        return $this->outcome($base, self::IMPORTED, sprintf('Linked to %s.', $server->name), [$server->id]);
    }

    /**
     * The allocations a tunnel to `$localIp:$localPort` reaches. A loopback or wildcard local IP
     * reaches every IP of the node; a real one reaches allocations on that IP, or else those
     * bound to every IP.
     *
     * @param  Collection<int, Allocation>  $allocations
     * @return Collection<int, Allocation>
     */
    private function matchingAllocations(Collection $allocations, ?string $localIp, int $localPort): Collection
    {
        $onPort = $allocations->filter(fn (Allocation $allocation): bool => $allocation->port === $localPort && $allocation->server instanceof Server);

        if ($localIp === null || in_array($localIp, self::ANY_ADDRESS, true)) {
            return $onPort->values();
        }

        $exact = $onPort->filter(fn (Allocation $allocation): bool => in_array($localIp, [mb_strtolower((string) $allocation->ip), mb_strtolower((string) $allocation->ip_alias)], true));

        return ($exact->isNotEmpty() ? $exact : $onPort->filter(fn (Allocation $allocation): bool => in_array($allocation->ip, ['0.0.0.0', '::'], true)))->values();
    }
}
