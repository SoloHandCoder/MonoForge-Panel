<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Entrance;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Exceptions\Tunnels\PlayitException;
use Pterodactyl\Jobs\Tunnels\DeletePlayitTunnelJob;
use Pterodactyl\Models\Node;
use Pterodactyl\Services\Tunnels\PlayitClient;

/**
 * The ONE playit.gg tunnel of a node whose shared Minecraft entrance is exposed through playit:
 * a plain TCP tunnel to the Gate proxy on 127.0.0.1:<proxy port>. A plain tunnel (not playit's
 * Minecraft type) is used because players reach it through the owner's own wildcard DNS name,
 * which only needs playit's host and port. Never throws: an error is kept on the node and the
 * scheduler tries again.
 */
class EntranceTunnelService
{
    public function __construct(private readonly PlayitClient $client) {}

    /**
     * Create the tunnel when the node needs one and has none, then read back its address.
     * A node that no longer needs it gets it removed.
     */
    public function ensure(Node $node): void
    {
        if (! $this->wanted($node)) {
            $this->release($node);

            return;
        }

        try {
            Cache::lock('mc-entrance-tunnel:'.$node->id, 60)->block(20, fn () => $this->step($node->refresh()));
        } catch (LockTimeoutException) {
            // Another worker is on it.
        }
    }

    /** Remove the tunnel on playit (queued) and forget it. */
    public function release(Node $node): void
    {
        $id = $node->mc_entrance_tunnel_id;
        if (is_string($id) && $id !== '') {
            dispatch(new DeletePlayitTunnelJob($node->id, $id))->afterCommit();
        }

        if ($id !== null || $node->mc_entrance_address !== null || $node->mc_entrance_error !== null) {
            $node->forceFill(['mc_entrance_tunnel_id' => null, 'mc_entrance_address' => null, 'mc_entrance_error' => null])->saveQuietly();
        }
    }

    public function wanted(Node $node): bool
    {
        return $node->hasMinecraftEntrance() && $node->mc_entrance_mode === Node::ENTRANCE_PLAYIT && ! $node->isPlayitReadOnly();
    }

    private function step(Node $node): void
    {
        $secret = (string) $node->playit_secret;
        $agent = (string) $node->playit_agent_id;

        try {
            if (! is_string($node->mc_entrance_tunnel_id) || $node->mc_entrance_tunnel_id === '') {
                $id = $this->client->createTunnel($secret, $agent, [
                    'name' => mb_substr('mc-entrance-'.$node->uuid, 0, 40),
                    'tunnel_type' => null,
                    'port_type' => 'tcp',
                    'local_ip' => MinecraftEntrance::BACKEND_HOST,
                    'local_port' => (int) $node->mc_entrance_proxy_port,
                ]);
                $node->forceFill(['mc_entrance_tunnel_id' => $id, 'mc_entrance_address' => null])->saveQuietly();
            }

            $remote = $this->client->findTunnel($secret, (string) $node->mc_entrance_tunnel_id, $agent);
        } catch (PlayitException $playitException) {
            $node->forceFill(['mc_entrance_error' => mb_substr($playitException->adminMessage(), 0, 1000)])->saveQuietly();

            return;
        }

        if ($remote === null) {
            // Removed on playit: the next run makes a new one.
            $node->forceFill(['mc_entrance_tunnel_id' => null, 'mc_entrance_address' => null, 'mc_entrance_error' => 'The entrance tunnel no longer exists on playit.gg; it will be created again.'])->saveQuietly();

            return;
        }

        $node->forceFill(['mc_entrance_address' => PlayitClient::addressOf($remote), 'mc_entrance_error' => null])->saveQuietly();
    }
}
