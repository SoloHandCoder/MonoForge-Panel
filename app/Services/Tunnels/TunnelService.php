<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Tunnels;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Pterodactyl\Enum\TunnelStatus;
use Pterodactyl\Exceptions\Tunnels\PlayitException;
use Pterodactyl\Jobs\Tunnels\DeletePlayitTunnelJob;
use Pterodactyl\Jobs\Tunnels\ProvisionTunnelJob;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Billing\ServerTunnel;
use Pterodactyl\Models\Game;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Entrance\MinecraftEntrance;
use Pterodactyl\Services\Games\ExtraPortPlanner;
use Pterodactyl\Services\Games\GameResolver;
use Pterodactyl\Services\Servers\ServerAddress;
use Throwable;

/**
 * Gives servers on a node with a playit.gg agent their playit tunnels, so players see an address
 * such as `xyz.joinmc.link` instead of the node's `0.0.0.0:25565`.
 *
 * Automatic tunnels only go to servers whose egg belongs to a game in Admin > Games with
 * "Auto-create playit tunnels" on: a main tunnel to the primary allocation, and one per extra
 * port the game defines (such as Simple Voice Chat) that matches one of the server's other
 * allocations. An administrator can still create a main tunnel by hand for any server.
 *
 * playit is an outside, undocumented API, so nothing here ever fails the caller: creating a
 * server or a purchase carries on, the error is kept on the tunnel, the panel keeps showing the
 * node's own address, and the scheduler retries failed tunnels every ten minutes.
 */
class TunnelService
{
    public const string NOT_CONFIGURED = 'This node has no playit.gg agent configured.';

    /** Shown on tunnels of a node whose agent key playit only lets read. */
    public const string READ_ONLY = 'This agent key is read-only: create tunnels in the playit dashboard; the panel links them automatically.';

    /** Shown while playit refuses to create tunnels because no agent program runs with the key yet. */
    public const string AGENT_NOT_RUNNING = 'The playit agent on this node is not running with the new key yet. Run the switch script on the node; the panel retries every ten minutes.';

    /** Shown on a tunnel the scheduler leaves alone because its server's egg is not a game that wants tunnels. */
    public const string NOT_A_GAME = "Not a game: this server's egg belongs to no game in Admin > Games that creates playit.gg tunnels, so it gets no automatic tunnel. Retry it by hand to create one anyway.";

    /** Shown while the playit account has no room for another tunnel. */
    public const string TUNNEL_LIMIT = 'Your playit.gg account reached its tunnel limit. Delete unused tunnels in the playit.gg dashboard or upgrade your playit plan; the panel tries again in six hours.';

    /** Why a server on a node in game gateway mode gets no playit.gg tunnel. */
    public const string GATEWAY = 'This node uses the game gateway: players join through the gateway VPS, so its servers get no playit.gg tunnels.';

    /** Why a Minecraft server joined through its node's shared entrance gets no main tunnel. */
    public const string ENTRANCE = "This server is joined through its node's shared Minecraft entrance (<name>.<domain>), so it gets no playit.gg tunnel of its own for the game port.";

    /** How long the scheduler waits before retrying a tunnel that hit the account's limit. */
    public const int TUNNEL_LIMIT_BACKOFF_HOURS = 6;

    public function __construct(private readonly PlayitClient $client, private readonly ServerAddress $addresses) {}

    /**
     * Whether a server's tunnel row may be taken over by an imported tunnel: it was deleted, or
     * it failed before the panel made anything on playit.
     */
    public static function isReplaceable(ServerTunnel $tunnel): bool
    {
        return $tunnel->status === TunnelStatus::Deleted
            || ($tunnel->status === TunnelStatus::Failed && $tunnel->playit_tunnel_id === null);
    }

    /**
     * The local IP a tunnel to an allocation on `$ip` uses: the agent runs on the node itself, so
     * a wildcard bind (or anything that is not an IP) is reached on loopback.
     */
    public static function localIpFor(?string $ip): string
    {
        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false && ! in_array($ip, ['0.0.0.0', '::'], true) ? $ip : '127.0.0.1';
    }

    /**
     * Make sure the server has its main tunnel and queue the work to create it, whatever its
     * game: this is the admin "Create tunnel". Calling it again for a server that already has a
     * live or pending tunnel changes nothing.
     */
    public function provision(Server $server): ServerTunnel
    {
        $server->loadMissing('node');

        $tunnel = ServerTunnel::query()->firstOrCreate(
            ['server_id' => $server->id, 'port_key' => ServerTunnel::MAIN],
            ['allocation_id' => $server->allocation_id, 'status' => TunnelStatus::Pending],
        );

        return $this->provisionRow($server, $tunnel, $server->allocation_id);
    }

    /**
     * The automatic tunnels of a server, as the shop, server creation and the scheduler make
     * them: only for a server whose egg belongs to a game with "Auto-create playit tunnels" on,
     * then its main tunnel and one per extra port of the game that matches an allocation.
     * Extra tunnels whose allocation went away are released. Returns the main tunnel, or null
     * when the server gets no automatic tunnel (an existing tunnel is left alone).
     */
    public function provisionAutomatic(Server $server): ?ServerTunnel
    {
        $server->loadMissing(['node', 'egg']);
        if ($server->node->hasGateway()) {
            // Players join through the gateway; existing tunnels are left as they are.
            return null;
        }

        $game = GameResolver::forServer($server);

        if (! $game instanceof Game || ! $game->auto_tunnels) {
            $this->holdNotAGame($server);

            return null;
        }

        // A server routed by the node's shared Minecraft entrance only gets its extra ports.
        $main = MinecraftEntrance::isRouted($server) ? null : $this->provision($server);
        $planned = $this->plannedExtras($server, $game);

        foreach ($planned as $key => $extra) {
            $tunnel = ServerTunnel::query()->firstOrCreate(
                ['server_id' => $server->id, 'port_key' => $key],
                ['allocation_id' => $extra['allocation_id'], 'label' => $extra['name'], 'protocol' => $extra['protocol'], 'status' => TunnelStatus::Pending],
            );

            if (! $tunnel->wasRecentlyCreated && $tunnel->status !== TunnelStatus::Deleted && $tunnel->allocation_id !== $extra['allocation_id']) {
                // The port moved to another allocation: throw the old tunnel away first.
                $this->releaseRow($server, $tunnel, false);
                $tunnel->refresh();
            }

            $tunnel->fill(['label' => $extra['name'], 'protocol' => $extra['protocol']]);
            $this->provisionRow($server, $tunnel, $extra['allocation_id']);
        }

        ServerTunnel::query()
            ->where('server_id', $server->id)
            ->where('port_key', '!=', ServerTunnel::MAIN)
            ->whereNotIn('port_key', array_keys($planned))
            ->where('status', '!=', TunnelStatus::Deleted)
            ->get()
            ->each(fn (ServerTunnel $stale) => $this->releaseRow($server, $stale, false));

        return $main;
    }

    /**
     * The extra tunnels a server's game asks for, by port key ({@see ExtraPortPlanner}).
     *
     * @return array<string, array{name: string, protocol: string, allocation_id: int, port: int}>
     */
    public function plannedExtras(Server $server, Game $game): array
    {
        return ExtraPortPlanner::plan($server, $game);
    }

    /**
     * Park the failed tunnels of a server that is not a game wanting tunnels, so the scheduler
     * stops retrying them. Nothing exists on playit for them, so nothing is deleted there.
     */
    public function holdNotAGame(Server $server): void
    {
        ServerTunnel::query()
            ->where('server_id', $server->id)
            ->where('status', TunnelStatus::Failed)
            ->whereNull('playit_tunnel_id')
            ->whereNull('imported_at')
            ->where(fn (Builder $query) => $query->whereNull('hold_reason')->orWhere('hold_reason', '!=', ServerTunnel::HOLD_NOT_A_GAME))
            ->update(['hold_reason' => ServerTunnel::HOLD_NOT_A_GAME, 'retry_after' => null, 'last_error' => self::NOT_A_GAME]);
    }

    /**
     * Give every server on the node the automatic tunnels it is missing, such as servers created
     * before the node had an agent the panel can create tunnels with, or a voice chat port added
     * later. Servers whose egg is not a game wanting tunnels are skipped. With `$extrasOnly`,
     * only servers that already have a main tunnel are completed. Returns how many servers were
     * queued.
     */
    public function provisionMissing(Node $node, int $limit = 50, bool $extrasOnly = false): int
    {
        if (! $node->hasPlayitAgent() || $node->isPlayitReadOnly() || $node->hasGateway()) {
            return 0;
        }

        $queued = 0;
        foreach (Server::query()->where('node_id', $node->id)->with(['egg', 'tunnels'])->lazyById(200) as $server) {
            if ($queued >= $limit) {
                break;
            }

            $game = GameResolver::forServer($server);
            if (! $game instanceof Game || ! $game->auto_tunnels) {
                continue;
            }

            $server->setRelation('node', $node);
            $routed = MinecraftEntrance::isRouted($server);
            $keys = $server->tunnels->map(fn (ServerTunnel $tunnel): string => $tunnel->port_key)->all();
            if ($extrasOnly && ! $routed && ! in_array(ServerTunnel::MAIN, $keys, true)) {
                continue;
            }

            $wanted = [...($routed ? [] : [ServerTunnel::MAIN]), ...array_keys($this->plannedExtras($server, $game))];
            if (array_diff($wanted, $keys) === []) {
                continue;
            }

            $server->setRelation('node', $node);
            $this->provisionAutomatic($server);
            $queued++;
        }

        return $queued;
    }

    /**
     * Delete every tunnel of the server on playit. The server keeps working on its node address.
     *
     * A tunnel made by hand and imported is only unlinked here, and left on playit, unless
     * `$deleteImported` says it should go too (the server itself is being deleted).
     */
    public function release(Server $server, bool $deleteImported = false): void
    {
        foreach (ServerTunnel::query()->where('server_id', $server->id)->where('status', '!=', TunnelStatus::Deleted)->get() as $tunnel) {
            $this->releaseRow($server, $tunnel, $deleteImported);
        }
    }

    /**
     * Link a tunnel made by hand on playit to the server, as "Import existing tunnels" does. It
     * becomes active with playit's address right away when playit has allocated one, and stays
     * pending (for the scheduler to read back) otherwise. Nothing is created or changed on playit.
     *
     * Returns null when the server got a live tunnel of its own in the meantime.
     *
     * @param  array<mixed>  $remote  the tunnel as playit's `/tunnels/list` returns it
     */
    public function adopt(Server $server, int $allocationId, array $remote): ?ServerTunnel
    {
        $playitTunnelId = $remote['id'] ?? null;
        if (! is_string($playitTunnelId) || $playitTunnelId === '') {
            return null;
        }

        $tunnel = ServerTunnel::query()->firstOrNew(['server_id' => $server->id, 'port_key' => ServerTunnel::MAIN]);
        $adopted = null;

        $adopt = function () use ($tunnel, $allocationId, $remote, $playitTunnelId, &$adopted): void {
            if ($tunnel->exists) {
                $tunnel->refresh();
            }

            if ($tunnel->exists && ! self::isReplaceable($tunnel)) {
                return;
            }

            $address = PlayitClient::addressOf($remote);
            $tunnel->fill([
                'allocation_id' => $allocationId,
                'playit_tunnel_id' => $playitTunnelId,
                'address' => $address,
                'subdomain' => null,
                'status' => $address === null ? TunnelStatus::Pending : TunnelStatus::Active,
                'last_error' => null,
                'imported_at' => CarbonImmutable::now(),
                'hold_reason' => null,
                'retry_after' => null,
            ])->save();

            $adopted = $tunnel;
        };

        try {
            $this->locked($tunnel, $adopt);
        } catch (LockTimeoutException) {
            return null;
        }

        return $adopted;
    }

    /**
     * The address players connect to, or null when there is none to show: the game gateway's
     * on a gateway node, otherwise the main playit tunnel's. {@see ServerAddress} decides.
     */
    public function displayAddress(Server $server): ?string
    {
        return $this->addresses->main($server);
    }

    /**
     * The extra addresses of a server (voice chat and the like), for its own pages. The public
     * page only ever shows the main address.
     *
     * @return list<array{key: string, label: string, address: string, protocol: string}>
     */
    public function extraAddresses(Server $server): array
    {
        return $this->addresses->extras($server);
    }

    /**
     * Throw the tunnel away on playit and queue a new one, such as after the server moved to
     * another port.
     *
     * An imported tunnel (made by hand on playit) is left on playit unless `$deleteImported`
     * is set, which the admin "Recreate" button does; the server still gets a new tunnel.
     */
    public function recreate(ServerTunnel $tunnel, bool $deleteImported = false): ServerTunnel
    {
        $owner = $tunnel->server()->with(['node', 'egg'])->first();
        if ($owner?->node->hasGateway() === true || ($owner instanceof Server && $tunnel->isMain() && MinecraftEntrance::isRouted($owner))) {
            // Left alone while the node uses the gateway or the shared entrance; turning it off resumes playit.
            return $tunnel;
        }

        try {
            $this->locked($tunnel, function () use ($tunnel, $deleteImported): void {
                $tunnel->refresh()->load('server');
                if ($tunnel->server instanceof Server) {
                    if ($deleteImported || ! $tunnel->isImported()) {
                        $this->queueRemoteDelete($tunnel->server->node_id, $tunnel->playit_tunnel_id);
                    }

                    if ($tunnel->isMain()) {
                        $tunnel->allocation_id = $tunnel->server->allocation_id;
                    }
                }

                $tunnel->fill(['status' => TunnelStatus::Pending, 'playit_tunnel_id' => null, 'address' => null, 'last_error' => null, 'imported_at' => null, 'hold_reason' => null, 'retry_after' => null])->save();
            });
        } catch (LockTimeoutException) {
            return $tunnel;
        }

        dispatch(new ProvisionTunnelJob($tunnel->id))->afterCommit();

        return $tunnel;
    }

    /**
     * Move the tunnel one step on: create it on playit when it has no id yet, then read whether
     * playit assigned an address. Used by the queued job, the scheduler and the admin "Retry".
     * Never throws; a failure is stored on the tunnel.
     */
    public function sync(ServerTunnel $tunnel): ServerTunnel
    {
        try {
            $this->locked($tunnel, fn () => $this->step($tunnel->refresh()));
        } catch (LockTimeoutException) {
            // Another worker is moving this tunnel on right now.
        } catch (Throwable $throwable) {
            report($throwable);
            $this->fail($tunnel, 'Unexpected error while talking to playit.gg.');
        }

        return $tunnel;
    }

    /**
     * Queue the creation of one tunnel row pointing at an allocation, unless it is live or
     * already pending.
     */
    private function provisionRow(Server $server, ServerTunnel $tunnel, ?int $allocationId): ServerTunnel
    {
        if (! $tunnel->wasRecentlyCreated && $tunnel->status !== TunnelStatus::Deleted && $tunnel->status !== TunnelStatus::Failed) {
            if ($tunnel->isDirty()) {
                $tunnel->save();
            }

            return $tunnel;
        }

        if ($server->node->hasGateway()) {
            // Nothing is made on playit while the node uses the game gateway. A row an
            // administrator just asked for says why; an existing one is left as it is.
            return $tunnel->wasRecentlyCreated ? $this->fail($tunnel, self::GATEWAY) : $tunnel;
        }

        if ($tunnel->isMain() && MinecraftEntrance::isRouted($server)) {
            return $tunnel->wasRecentlyCreated ? $this->fail($tunnel, self::ENTRANCE) : $tunnel;
        }

        $tunnel->fill(['hold_reason' => null, 'retry_after' => null]);

        if (! $server->node->hasPlayitAgent()) {
            return $this->fail($tunnel, self::NOT_CONFIGURED);
        }

        if ($server->node->isPlayitReadOnly() && ($tunnel->status === TunnelStatus::Deleted || $tunnel->playit_tunnel_id === null)) {
            // The panel cannot create it; the scheduler links the one made in the playit dashboard.
            $tunnel->fill(['playit_tunnel_id' => null, 'address' => null, 'allocation_id' => $allocationId, 'imported_at' => null]);

            return $this->waitForDashboard($tunnel);
        }

        if ($tunnel->status === TunnelStatus::Deleted) {
            $tunnel->fill(['playit_tunnel_id' => null, 'address' => null, 'allocation_id' => $allocationId, 'imported_at' => null]);
        }

        $tunnel->fill(['status' => TunnelStatus::Pending, 'last_error' => null])->save();

        dispatch(new ProvisionTunnelJob($tunnel->id))->afterCommit();

        return $tunnel;
    }

    private function releaseRow(Server $server, ServerTunnel $tunnel, bool $deleteImported): void
    {
        $release = function () use ($tunnel, $server, $deleteImported): void {
            $tunnel->refresh();
            if ($deleteImported || ! $tunnel->isImported()) {
                $this->queueRemoteDelete($server->node_id, $tunnel->playit_tunnel_id);
            }

            // The custom subdomain is released with the tunnel, so the name can be picked again.
            $tunnel->fill(['status' => TunnelStatus::Deleted, 'playit_tunnel_id' => null, 'address' => null, 'subdomain' => null, 'last_error' => null, 'imported_at' => null, 'hold_reason' => null, 'retry_after' => null])->save();
        };

        try {
            $this->locked($tunnel, $release);
        } catch (LockTimeoutException) {
            // A stuck worker must not keep the server from being deleted.
            $release();
        }
    }

    private function step(ServerTunnel $tunnel): void
    {
        if ($tunnel->status === TunnelStatus::Deleted) {
            return;
        }

        $server = $tunnel->server()->with(['node', 'allocation', 'egg.tags'])->first();
        if (! $server instanceof Server) {
            return;
        }

        $node = $server->node;
        if ($node->hasGateway() || ($tunnel->isMain() && MinecraftEntrance::isRouted($server))) {
            // Nothing is created, synced or retried on playit while the node uses the gateway, or
            // for the game port of a server joined through the shared Minecraft entrance.
            return;
        }

        if (! $node->hasPlayitAgent()) {
            $this->fail($tunnel, self::NOT_CONFIGURED);

            return;
        }

        $secret = (string) $node->playit_secret;

        if ($tunnel->playit_tunnel_id === null && $node->isPlayitReadOnly()) {
            $this->waitForDashboard($tunnel);

            return;
        }

        try {
            if ($tunnel->playit_tunnel_id === null) {
                $allocation = $tunnel->isMain()
                    ? $server->allocation
                    : Allocation::query()->where('server_id', $server->id)->find($tunnel->allocation_id);
                if (! $allocation instanceof Allocation) {
                    $this->fail($tunnel, 'The allocation this tunnel pointed at was removed from the server.');

                    return;
                }

                $tunnel->fill([
                    'playit_tunnel_id' => $this->create($server, $tunnel, $allocation, $node, $secret),
                    'allocation_id' => $allocation->id,
                    'status' => TunnelStatus::Pending,
                    'last_error' => null,
                    // A tunnel the panel made itself, even if it replaces an imported one.
                    'imported_at' => null,
                ])->save();
            }

            $remote = $this->client->findTunnel($secret, (string) $tunnel->playit_tunnel_id, (string) $node->playit_agent_id);
        } catch (PlayitException $playitException) {
            if ($playitException->reason === PlayitClient::READ_ONLY) {
                // Stop trying to create tunnels on this node until its agent key changes.
                $node->forceFill(['playit_mode' => Node::PLAYIT_READ_ONLY])->save();
                Log::info(sprintf('playit.gg agent key of node #%d is read-only; tunnels there are now linked from the playit dashboard.', $node->id));
                $this->waitForDashboard($tunnel);

                return;
            }

            if ($playitException->reason === PlayitClient::TUNNEL_LIMIT || str_contains($playitException->getMessage(), PlayitClient::TUNNEL_LIMIT)) {
                $tunnel->fill(['hold_reason' => ServerTunnel::HOLD_TUNNEL_LIMIT, 'retry_after' => CarbonImmutable::now()->addHours(self::TUNNEL_LIMIT_BACKOFF_HOURS)]);
                $this->fail($tunnel, self::TUNNEL_LIMIT);

                return;
            }

            $this->fail($tunnel, $playitException->reason === PlayitClient::AGENT_VERSION_TOO_OLD ? self::AGENT_NOT_RUNNING : $playitException->getMessage());

            return;
        }

        if ($remote === null) {
            // Removed on playit's side: forget it so the next retry creates a new one.
            $tunnel->fill(['playit_tunnel_id' => null, 'address' => null]);
            $this->fail($tunnel, 'The tunnel no longer exists on playit.gg; it will be created again.');

            return;
        }

        $address = PlayitClient::addressOf($remote);

        if ($address !== null) {
            $tunnel->fill(['status' => TunnelStatus::Active, 'address' => $address, 'last_error' => null, 'hold_reason' => null, 'retry_after' => null])->save();

            return;
        }

        $disabled = PlayitClient::disabledReason($remote);
        if ($disabled !== null) {
            $this->fail($tunnel, 'playit.gg disabled the tunnel: '.$disabled.'.');

            return;
        }

        // Still pending: playit has not assigned an address yet.
        $tunnel->fill(['status' => TunnelStatus::Pending, 'last_error' => null])->save();
    }

    /**
     * @throws PlayitException
     */
    private function create(Server $server, ServerTunnel $row, Allocation $allocation, Node $node, string $secret): string
    {
        // The main tunnel gets the game's playit tunnel type; an extra port is a plain port.
        $profile = $row->isMain()
            ? TunnelProfile::forEgg($server->egg)
            : ['tunnel_type' => null, 'port_type' => in_array($row->protocol, ['tcp', 'udp', 'both'], true) ? (string) $row->protocol : 'both'];
        $tunnel = [
            'name' => $this->tunnelName($server, $row),
            'tunnel_type' => $profile['tunnel_type'],
            'port_type' => $profile['port_type'],
            'local_ip' => self::localIpFor($allocation->ip),
            'local_port' => (int) $allocation->port,
        ];

        try {
            return $this->client->createTunnel($secret, (string) $node->playit_agent_id, $tunnel);
        } catch (PlayitException $playitException) {
            // playit's naming rules are not documented; an unnamed tunnel is as good.
            throw_if($playitException->reason !== 'InvalidTunnelName', $playitException);

            return $this->client->createTunnel($secret, (string) $node->playit_agent_id, [...$tunnel, 'name' => null]);
        }
    }

    /** "survival-world-1a2b3c4d", or "survival-world-1a2b3c4d-voice" for an extra port. */
    private function tunnelName(Server $server, ServerTunnel $row): string
    {
        $slug = mb_substr(Str::slug($server->name), 0, 40);
        $suffix = $row->isMain() ? '' : '-'.mb_substr($row->port_key, 0, 16);

        return mb_trim($slug.'-'.$server->uuidShort.$suffix, '-');
    }

    private function queueRemoteDelete(int $nodeId, ?string $playitTunnelId): void
    {
        if ($playitTunnelId === null || $playitTunnelId === '') {
            return;
        }

        dispatch(new DeletePlayitTunnelJob($nodeId, $playitTunnelId))->afterCommit();
    }

    /**
     * Park a tunnel the panel cannot create because the node's agent key is read-only. It stays
     * failed without a playit id, so the scheduler links the tunnel the owner makes in the playit
     * dashboard to it, and nothing is logged on every run.
     */
    private function waitForDashboard(ServerTunnel $tunnel): ServerTunnel
    {
        $tunnel->fill(['status' => TunnelStatus::Failed, 'playit_tunnel_id' => null, 'last_error' => self::READ_ONLY])->save();

        return $tunnel;
    }

    private function fail(ServerTunnel $tunnel, string $error): ServerTunnel
    {
        $tunnel->fill(['status' => TunnelStatus::Failed, 'last_error' => mb_substr($error, 0, 1000)])->save();

        Log::info(sprintf('playit.gg tunnel %s for server #%d failed: %s', $tunnel->port_key, $tunnel->server_id, $error));

        return $tunnel;
    }

    /**
     * One worker at a time per tunnel, so a job and the scheduler never create two playit tunnels.
     *
     * @throws LockTimeoutException
     */
    private function locked(ServerTunnel $tunnel, callable $callback): void
    {
        $key = 'playit-tunnel:'.$tunnel->server_id.($tunnel->isMain() ? '' : ':'.$tunnel->port_key);

        Cache::lock($key, 60)->block(20, $callback);
    }
}
