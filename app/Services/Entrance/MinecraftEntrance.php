<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Entrance;

use Carbon\CarbonImmutable;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Gateway\GatewayHost;
use Pterodactyl\Services\Tunnels\TunnelProfile;

/**
 * The shared Minecraft entrance of a node (Minehut-style routing): every Minecraft Java server
 * on it is joined as `<name>.<domain>` through ONE public entrance, a playit.gg tunnel or the
 * game gateway, to a Gate Lite proxy on the node (scripts/monoforge/mc-router.sh). Gate reads
 * the host name from the Minecraft handshake and forwards the player to the server's local
 * allocation; the routes come from the panel ({@see routes()}).
 *
 * DNS: a wildcard record `*.<domain>` points at the entrance. A vanilla client sends the name it
 * was given in the handshake when it connects through an A/CNAME record; through an SRV record
 * it may send the SRV target instead (Mojang bug MC-278651), so every SRV record the panel
 * suggests targets the same `<name>.<domain>` and the host Gate sees is right either way.
 *
 * @phpstan-type GateRoute array{host: string, backend: string, proxyProtocol?: bool, fallback: array{motd: string, version: array{name: string, protocol: int}}}
 * @phpstan-type DnsRecord array{type: string, name: string, value: string, note: string}
 */
class MinecraftEntrance
{
    /** Gate forwards players to the servers on the node itself. */
    public const string BACKEND_HOST = '127.0.0.1';

    /** A Minecraft Java address without a port means this port. */
    public const int MINECRAFT_PORT = 25565;

    /** The most per-server SRV records listed in the admin card. */
    public const int MAX_LISTED_RECORDS = 100;

    /** Whether the server is joined through its node's entrance: Minecraft Java (not Bedrock) there. */
    public static function isRouted(Server $server): bool
    {
        $server->loadMissing(['node', 'egg']);

        return $server->node->hasMinecraftEntrance()
            && TunnelProfile::forEgg($server->egg)['tunnel_type'] === 'minecraft-java';
    }

    /** The port players reach the entrance on, or null while the playit tunnel has no address. */
    public static function publicPort(Node $node): ?int
    {
        if ($node->mc_entrance_mode === Node::ENTRANCE_GATEWAY) {
            return (int) $node->mc_entrance_port;
        }

        $address = $node->mc_entrance_address;
        if (! is_string($address) || $address === '') {
            return null;
        }

        return preg_match('/:(\d{1,5})$/', $address, $matches) === 1 ? (int) $matches[1] : self::MINECRAFT_PORT;
    }

    /** What the wildcard record points at: the gateway host, or the playit tunnel's host. */
    public static function target(Node $node): ?string
    {
        if ($node->mc_entrance_mode === Node::ENTRANCE_GATEWAY) {
            return is_string($node->gateway_host) && $node->gateway_host !== '' ? $node->gateway_host : null;
        }

        $address = $node->mc_entrance_address;

        return is_string($address) && $address !== '' ? (string) preg_replace('/:\d{1,5}$/', '', $address) : null;
    }

    /** Whether players can leave the port out: the entrance is on 25565, or SRV records carry it. */
    public static function portless(Node $node): bool
    {
        return self::publicPort($node) === self::MINECRAFT_PORT
            || ($node->mc_entrance_mode === Node::ENTRANCE_PLAYIT && $node->mc_entrance_srv === true);
    }

    /** `<name>.<domain>` without a port. */
    public static function hostFor(Server $server): string
    {
        return EntranceHostnames::for($server).'.'.$server->node->mc_entrance_domain;
    }

    /** The address players join a routed server with, or null while the entrance has none. */
    public static function address(Server $server): ?string
    {
        $port = self::publicPort($server->node);
        if ($port === null) {
            return null;
        }

        $host = self::hostFor($server);

        return self::portless($server->node) ? $host : $host.':'.$port;
    }

    /** The port Gate listens on: the entrance port itself behind the gateway, else the proxy port. */
    public static function listenPort(Node $node): int
    {
        return $node->mc_entrance_mode === Node::ENTRANCE_GATEWAY ? (int) $node->mc_entrance_port : (int) $node->mc_entrance_proxy_port;
    }

    /**
     * Gate's whole config.yml for the node, as JSON (which YAML reads as it is). Behind the
     * gateway Gate listens on all addresses (the VPS forwards over WireGuard); behind playit only
     * on loopback, where the playit agent on the node reaches it.
     *
     * Only servers that are installed and not suspended get a route, always to 127.0.0.1. When a
     * server does not answer (stopped, asleep) Gate shows the route's fallback status in the
     * server list; unknown names land on a catch-all with a "no such server" status.
     *
     * @return array{config: array{bind: string, onlineMode: bool, lite: array{enabled: bool, routes: list<GateRoute>}}}
     */
    public function gateConfig(Node $node): array
    {
        $bind = ($node->mc_entrance_mode === Node::ENTRANCE_GATEWAY ? '0.0.0.0' : self::BACKEND_HOST).':'.self::listenPort($node);
        $routes = [];

        foreach ($this->routedServers($node) as $server) {
            $allocation = $server->allocation;
            if (! $allocation instanceof Allocation) {
                continue;
            }

            $route = [
                'host' => self::hostFor($server),
                'backend' => self::BACKEND_HOST.':'.(int) $allocation->port,
                'fallback' => [
                    'motd' => sprintf("§e%s is offline or asleep.\n§7Start it in the panel: %s", $server->name, $this->panelLink($server)),
                    'version' => ['name' => '§eOffline', 'protocol' => -1],
                ],
            ];

            // Only once the server reads PROXY protocol; sent to one that does not, joins fail.
            if ($node->mc_entrance_proxy_protocol === true && $server->mc_proxy_protocol_at !== null) {
                $route['proxyProtocol'] = true;
            }

            $routes[] = $route;
        }

        $routes[] = [
            'host' => '*',
            // Nothing listens on port 1: players get the fallback status and a refused join.
            'backend' => self::BACKEND_HOST.':1',
            'fallback' => [
                'motd' => '§cThere is no server with this address.',
                'version' => ['name' => '§cNot found', 'protocol' => -1],
            ],
        ];

        return ['config' => ['bind' => $bind, 'onlineMode' => true, 'lite' => ['enabled' => true, 'routes' => $routes]]];
    }

    /**
     * @return list<Server>
     */
    public function routedServers(Node $node): array
    {
        if (! $node->hasMinecraftEntrance()) {
            return [];
        }

        $servers = [];
        foreach (Server::query()->where('node_id', $node->id)->with(['allocation', 'egg.tags', 'egg.game'])->orderBy('id')->lazyById(200) as $server) {
            $server->setRelation('node', $node);
            if ($server->isInstalled() && ! $server->isSuspended() && self::isRouted($server)) {
                $servers[] = $server;
            }
        }

        return $servers;
    }

    /**
     * The DNS records the owner of the domain must create.
     *
     * @return list<DnsRecord>
     */
    public function dnsRecords(Node $node): array
    {
        $domain = (string) $node->mc_entrance_domain;
        $target = self::target($node);
        if ($domain === '' || $target === null) {
            return [];
        }

        $wildcard = GatewayHost::isIpv4($target)
            ? ['type' => 'A', 'name' => '*.'.$domain, 'value' => $target, 'note' => 'DNS only (grey cloud on Cloudflare).']
            : ['type' => 'CNAME', 'name' => '*.'.$domain, 'value' => $target, 'note' => 'DNS only (grey cloud on Cloudflare).'];
        $records = [$wildcard];

        $port = self::publicPort($node);
        if ($node->mc_entrance_mode !== Node::ENTRANCE_PLAYIT || $port === null || $port === self::MINECRAFT_PORT) {
            return $records;
        }

        // A wildcard cannot carry an SRV record, so each server needs its own to drop the port.
        foreach (array_slice($this->routedServers($node), 0, self::MAX_LISTED_RECORDS) as $server) {
            $host = self::hostFor($server);
            $records[] = ['type' => 'SRV', 'name' => '_minecraft._tcp.'.$host, 'value' => sprintf('0 5 %d %s', $port, $host), 'note' => 'Lets players leave out the port; the target is the same name.'];
        }

        return $records;
    }

    /** Remembers that the node's router fetched its routes, for the admin card. */
    public function touch(Node $node): void
    {
        $node->forceFill(['mc_entrance_synced_at' => CarbonImmutable::now()])->saveQuietly();
    }

    private function panelLink(Server $server): string
    {
        return mb_rtrim(config()->string('app.url'), '/').'/server/'.$server->uuidShort;
    }
}
