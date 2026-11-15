<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Query;

use Illuminate\Support\Facades\Cache;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Tunnels\TunnelProfile;

/**
 * Asks a game server who is online, the way its own players' clients do. Shared by auto-sleep,
 * the public server page and anything else that needs a player count.
 *
 * Only Minecraft Java is supported for now (Server List Ping to the server's main allocation,
 * from the panel host, 2 s timeout). Answers, including "no answer", are cached for 30 seconds
 * per server, so a busy page never pings a server more than twice a minute.
 *
 * `ServerQuery::status($server)` works statically and on an injected instance.
 */
class ServerQuery
{
    public const int CACHE_SECONDS = 30;

    public function __construct(private readonly MinecraftJavaPing $minecraftJava) {}

    /** The server's status, or null when the game does not answer or is not supported. */
    public static function status(Server $server): ?QueryStatus
    {
        return resolve(self::class)->query($server);
    }

    /** Whether the server's game can be asked for its players. */
    public static function supports(Server $server): bool
    {
        return TunnelProfile::forEgg($server->egg)['tunnel_type'] === 'minecraft-java';
    }

    public static function cacheKey(Server $server): string
    {
        return "server-query:{$server->uuid}";
    }

    public function query(Server $server): ?QueryStatus
    {
        if (! self::supports($server)) {
            return null;
        }

        /** @var array{status: array{online: int, max: int, motd: string|null, version: string|null, players: list<string>}|null} $cached */
        $cached = Cache::remember(self::cacheKey($server), self::CACHE_SECONDS, function () use ($server): array {
            $status = $this->ask($server);

            return ['status' => $status instanceof QueryStatus ? [
                'online' => $status->online,
                'max' => $status->max,
                'motd' => $status->motd,
                'version' => $status->version,
                'players' => $status->players,
            ] : null];
        });

        $status = $cached['status'];

        return $status === null ? null : new QueryStatus($status['online'], $status['max'], $status['motd'], $status['version'], $status['players']);
    }

    private function ask(Server $server): ?QueryStatus
    {
        $allocation = $server->allocation;
        if (! $allocation instanceof Allocation) {
            return null;
        }

        return $this->minecraftJava->ping($this->host($server, $allocation), $allocation->port);
    }

    /**
     * The allocation's address, or the node's address when the allocation listens on every
     * interface (0.0.0.0 or ::), which is not an address one can connect to.
     */
    private function host(Server $server, Allocation $allocation): string
    {
        $ip = mb_trim((string) $allocation->ip, '[] ');
        if ($ip === '' || in_array($ip, ['0.0.0.0', '::'], true)) {
            return $server->node->fqdn;
        }

        return $ip;
    }
}
