<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Servers;

use Pterodactyl\Enum\TunnelStatus;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Billing\ServerTunnel;
use Pterodactyl\Models\Game;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Games\ExtraPortPlanner;
use Pterodactyl\Services\Games\GameResolver;
use Pterodactyl\Services\Tunnels\TunnelProfile;

/**
 * The one place that decides the address players join a server with. Every player-facing
 * address (the server pages, the dashboard, the purchase result, the public page, discovery and
 * the assistant) comes from here, through {@see \Pterodactyl\Services\Tunnels\TunnelService}.
 *
 * - On a node in game gateway mode: `<gateway host>:<allocation port>` when the gateway forwards
 *   the port (a Minecraft Java server on 25565 leaves the port out), otherwise nothing. playit
 *   tunnels of such a node are never shown.
 * - Otherwise: the address of the server's live playit.gg tunnel, or nothing.
 *
 * Nothing here ever returns the node's own FQDN or IP.
 */
class ServerAddress
{
    public const string SOURCE_GATEWAY = 'gateway';

    public const string SOURCE_PLAYIT = 'playit';

    /** The port Minecraft Java clients use when an address has none. */
    public const int MINECRAFT_DEFAULT_PORT = 25565;

    /** `host:port`, or the host alone for Minecraft Java on its default port. */
    public static function join(string $host, int $port, bool $minecraftJava): string
    {
        return $minecraftJava && $port === self::MINECRAFT_DEFAULT_PORT ? $host : $host.':'.$port;
    }

    /**
     * The main address players connect to, or null when there is none to show.
     */
    public function main(Server $server): ?string
    {
        $node = $this->node($server);
        if ($node->hasGateway()) {
            $allocation = $server->relationLoaded('allocation') ? $server->getRelation('allocation') : $server->allocation()->first();
            if (! $allocation instanceof Allocation || ! $node->gatewayForwards((int) $allocation->port)) {
                return null;
            }

            return self::join((string) $node->gateway_host, (int) $allocation->port, $this->isMinecraftJava($server));
        }

        $tunnel = $server->relationLoaded('tunnel')
            ? $server->getRelation('tunnel')
            : ServerTunnel::query()->where('server_id', $server->id)->where('port_key', ServerTunnel::MAIN)->first();

        if (! $tunnel instanceof ServerTunnel || $tunnel->status !== TunnelStatus::Active) {
            return null;
        }

        return $tunnel->address;
    }

    /**
     * The extra addresses of a server (voice chat and the like): on a gateway node, the
     * extra ports of its game in Admin > Games matched to its other allocations the gateway
     * forwards; otherwise its live extra playit tunnels.
     *
     * @return list<array{key: string, label: string, address: string, protocol: string}>
     */
    public function extras(Server $server): array
    {
        $node = $this->node($server);
        if ($node->hasGateway()) {
            $game = GameResolver::forServer($server);
            if (! $game instanceof Game) {
                return [];
            }

            $addresses = [];
            foreach (ExtraPortPlanner::plan($server, $game) as $key => $extra) {
                if ($node->gatewayForwards($extra['port'])) {
                    $addresses[] = ['key' => $key, 'label' => $extra['name'], 'address' => self::join((string) $node->gateway_host, $extra['port'], false), 'protocol' => $extra['protocol']];
                }
            }

            return $addresses;
        }

        $tunnels = $server->relationLoaded('tunnels')
            ? $server->tunnels->sortBy('id')
            : ServerTunnel::query()->where('server_id', $server->id)->orderBy('id')->get();

        $addresses = [];
        foreach ($tunnels as $tunnel) {
            if ($tunnel->isMain() || $tunnel->status !== TunnelStatus::Active || $tunnel->address === null) {
                continue;
            }

            $addresses[] = ['key' => $tunnel->port_key, 'label' => $tunnel->label ?? $tunnel->port_key, 'address' => $tunnel->address, 'protocol' => $tunnel->protocol ?? 'both'];
        }

        return $addresses;
    }

    /**
     * Where the main address comes from (`gateway` or `playit`), or null when there is none.
     */
    public function source(Server $server): ?string
    {
        if ($this->main($server) === null) {
            return null;
        }

        return $this->node($server)->hasGateway() ? self::SOURCE_GATEWAY : self::SOURCE_PLAYIT;
    }

    /**
     * Whether players should see "Address pending" instead of the node allocation, which is not
     * reachable from outside: the server is on a node with playit.gg or the game gateway but has
     * no address yet (no live tunnel, or a port the gateway does not forward).
     */
    public function pending(Server $server): bool
    {
        $node = $this->node($server);

        return $this->main($server) === null && ($node->hasGateway() || $node->hasPlayitAgent());
    }

    private function node(Server $server): Node
    {
        return $server->loadMissing('node')->node;
    }

    private function isMinecraftJava(Server $server): bool
    {
        return TunnelProfile::forEgg($server->loadMissing('egg')->egg)['tunnel_type'] === 'minecraft-java';
    }
}
