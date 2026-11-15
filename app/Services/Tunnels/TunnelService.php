<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Tunnels;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Pterodactyl\Enum\TunnelStatus;
use Pterodactyl\Exceptions\Tunnels\PlayitException;
use Pterodactyl\Jobs\Tunnels\DeletePlayitTunnelJob;
use Pterodactyl\Jobs\Tunnels\ProvisionTunnelJob;
use Pterodactyl\Models\Billing\ServerTunnel;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Throwable;

/**
 * Gives every server on a node with a playit.gg agent a playit tunnel, so players see an address
 * such as `xyz.joinmc.link` instead of the node's `0.0.0.0:25565`.
 *
 * playit is an outside, undocumented API, so nothing here ever fails the caller: creating a
 * server or a purchase carries on, the error is kept on the tunnel, the panel keeps showing the
 * node's own address, and the scheduler retries failed tunnels every ten minutes.
 */
class TunnelService
{
    public const string NOT_CONFIGURED = 'This node has no playit.gg agent configured.';

    public function __construct(private readonly PlayitClient $client) {}

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
     * Make sure the server has a tunnel and queue the work to create it. Calling it again for a
     * server that already has a live or pending tunnel changes nothing.
     */
    public function provision(Server $server): ServerTunnel
    {
        $server->loadMissing('node');

        $tunnel = ServerTunnel::query()->firstOrCreate(
            ['server_id' => $server->id],
            ['allocation_id' => $server->allocation_id, 'status' => TunnelStatus::Pending],
        );

        if (! $tunnel->wasRecentlyCreated && $tunnel->status !== TunnelStatus::Deleted && $tunnel->status !== TunnelStatus::Failed) {
            return $tunnel;
        }

        if (! $server->node->hasPlayitAgent()) {
            return $this->fail($tunnel, self::NOT_CONFIGURED);
        }

        if ($tunnel->status === TunnelStatus::Deleted) {
            $tunnel->fill(['playit_tunnel_id' => null, 'address' => null, 'allocation_id' => $server->allocation_id, 'imported_at' => null]);
        }

        $tunnel->fill(['status' => TunnelStatus::Pending, 'last_error' => null])->save();

        dispatch(new ProvisionTunnelJob($tunnel->id))->afterCommit();

        return $tunnel;
    }

    /**
     * Delete the server's tunnel on playit. The server keeps working on its node address.
     *
     * A tunnel made by hand and imported is only unlinked here, and left on playit, unless
     * `$deleteImported` says it should go too (the server itself is being deleted).
     */
    public function release(Server $server, bool $deleteImported = false): void
    {
        $tunnel = ServerTunnel::query()->where('server_id', $server->id)->first();
        if (! $tunnel instanceof ServerTunnel || $tunnel->status === TunnelStatus::Deleted) {
            return;
        }

        $release = function () use ($tunnel, $server, $deleteImported): void {
            $tunnel->refresh();
            if ($deleteImported || ! $tunnel->isImported()) {
                $this->queueRemoteDelete($server->node_id, $tunnel->playit_tunnel_id);
            }

            // The custom subdomain is released with the tunnel, so the name can be picked again.
            $tunnel->fill(['status' => TunnelStatus::Deleted, 'playit_tunnel_id' => null, 'address' => null, 'subdomain' => null, 'last_error' => null, 'imported_at' => null])->save();
        };

        try {
            $this->locked($tunnel, $release);
        } catch (LockTimeoutException) {
            // A stuck worker must not keep the server from being deleted.
            $release();
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

        $tunnel = ServerTunnel::query()->firstOrNew(['server_id' => $server->id]);
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
     * The playit address players connect to, or null when the server has no live tunnel and the
     * node's own address should be shown.
     */
    public function displayAddress(Server $server): ?string
    {
        $tunnel = $server->relationLoaded('tunnel')
            ? $server->getRelation('tunnel')
            : ServerTunnel::query()->where('server_id', $server->id)->first();

        if (! $tunnel instanceof ServerTunnel || $tunnel->status !== TunnelStatus::Active) {
            return null;
        }

        return $tunnel->address;
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
        try {
            $this->locked($tunnel, function () use ($tunnel, $deleteImported): void {
                $tunnel->refresh()->load('server');
                if ($tunnel->server instanceof Server) {
                    if ($deleteImported || ! $tunnel->isImported()) {
                        $this->queueRemoteDelete($tunnel->server->node_id, $tunnel->playit_tunnel_id);
                    }

                    $tunnel->allocation_id = $tunnel->server->allocation_id;
                }

                $tunnel->fill(['status' => TunnelStatus::Pending, 'playit_tunnel_id' => null, 'address' => null, 'last_error' => null, 'imported_at' => null])->save();
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
        if (! $node->hasPlayitAgent()) {
            $this->fail($tunnel, self::NOT_CONFIGURED);

            return;
        }

        $secret = (string) $node->playit_secret;

        try {
            if ($tunnel->playit_tunnel_id === null) {
                $tunnel->fill([
                    'playit_tunnel_id' => $this->create($server, $node, $secret),
                    'allocation_id' => $server->allocation_id,
                    'status' => TunnelStatus::Pending,
                    'last_error' => null,
                    // A tunnel the panel made itself, even if it replaces an imported one.
                    'imported_at' => null,
                ])->save();
            }

            $remote = $this->client->findTunnel($secret, (string) $tunnel->playit_tunnel_id);
        } catch (PlayitException $playitException) {
            $this->fail($tunnel, $playitException->getMessage());

            return;
        }

        if ($remote === null) {
            // Removed on playit's side: forget it so the next retry creates a new one.
            $tunnel->fill(['playit_tunnel_id' => null, 'address' => null]);
            $this->fail($tunnel, 'The tunnel no longer exists on playit.gg; it will be created again.');

            return;
        }

        $alloc = is_array($remote['alloc'] ?? null) ? $remote['alloc'] : [];
        $address = PlayitClient::addressOf($remote);

        if ($address !== null) {
            $tunnel->fill(['status' => TunnelStatus::Active, 'address' => $address, 'last_error' => null])->save();

            return;
        }

        if (($alloc['status'] ?? null) === 'disabled') {
            $reason = is_array($alloc['data'] ?? null) && is_string($alloc['data']['reason'] ?? null) ? $alloc['data']['reason'] : 'unknown';
            $this->fail($tunnel, 'playit.gg disabled the tunnel: '.$reason.'.');

            return;
        }

        // Still pending: playit has not assigned an address yet.
        $tunnel->fill(['status' => TunnelStatus::Pending, 'last_error' => null])->save();
    }

    /**
     * @throws PlayitException
     */
    private function create(Server $server, Node $node, string $secret): string
    {
        $profile = TunnelProfile::forEgg($server->egg);
        $ip = $server->allocation?->ip;
        $tunnel = [
            'name' => $this->tunnelName($server),
            'tunnel_type' => $profile['tunnel_type'],
            'port_type' => $profile['port_type'],
            // The agent runs on the node itself; a wildcard bind is reached on loopback.
            'local_ip' => is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false && ! in_array($ip, ['0.0.0.0', '::'], true) ? $ip : '127.0.0.1',
            'local_port' => (int) $server->allocation?->port,
        ];

        try {
            return $this->client->createTunnel($secret, (string) $node->playit_agent_id, $tunnel);
        } catch (PlayitException $playitException) {
            // playit's naming rules are not documented; an unnamed tunnel is as good.
            throw_if($playitException->reason !== 'InvalidTunnelName', $playitException);

            return $this->client->createTunnel($secret, (string) $node->playit_agent_id, [...$tunnel, 'name' => null]);
        }
    }

    /** "survival-world-1a2b3c4d": the server's name, recognisable in the playit dashboard. */
    private function tunnelName(Server $server): string
    {
        $slug = mb_substr(Str::slug($server->name), 0, 40);

        return mb_trim($slug.'-'.$server->uuidShort, '-');
    }

    private function queueRemoteDelete(int $nodeId, ?string $playitTunnelId): void
    {
        if ($playitTunnelId === null || $playitTunnelId === '') {
            return;
        }

        dispatch(new DeletePlayitTunnelJob($nodeId, $playitTunnelId))->afterCommit();
    }

    private function fail(ServerTunnel $tunnel, string $error): ServerTunnel
    {
        $tunnel->fill(['status' => TunnelStatus::Failed, 'last_error' => mb_substr($error, 0, 1000)])->save();

        Log::info(sprintf('playit.gg tunnel for server #%d failed: %s', $tunnel->server_id, $error));

        return $tunnel;
    }

    /**
     * One worker at a time per tunnel, so a job and the scheduler never create two playit tunnels.
     *
     * @throws LockTimeoutException
     */
    private function locked(ServerTunnel $tunnel, callable $callback): void
    {
        Cache::lock('playit-tunnel:'.$tunnel->server_id, 60)->block(20, $callback);
    }
}
