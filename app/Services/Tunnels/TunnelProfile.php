<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Tunnels;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Game;
use Pterodactyl\Models\Tag;
use Pterodactyl\Services\Games\GameDetector;

/**
 * Picks the playit.gg tunnel type and protocol for a server: from the built-in game in
 * Admin > Games that claims its egg, otherwise from the egg's name and tags.
 * Games playit knows get their own type (which gives Minecraft Java a port-less address);
 * everything else gets a plain tunnel for both TCP and UDP.
 */
final class TunnelProfile
{
    /**
     * playit tunnel type => [the protocol it carries, patterns matched against the egg name and tags].
     * Bedrock comes before Java so "Minecraft Bedrock" is not taken for Java.
     *
     * @var array<string, array{string, string}>
     */
    private const array GAMES = [
        'minecraft-bedrock' => ['udp', '/\b(bedrock|pocketmine|nukkit|geyser-standalone)\b/'],
        'minecraft-java' => ['tcp', '/\b(minecraft|paper|purpur|spigot|bukkit|bungeecord|waterfall|velocity|forge|neoforge|fabric|quilt|sponge|folia|mohist|magma)\b/'],
        'terraria' => ['tcp', '/\b(terraria|tmodloader|tshock)\b/'],
        'valheim' => ['udp', '/\bvalheim\b/'],
        'rust' => ['udp', '/\brust\b/'],
    ];

    /**
     * @return array{tunnel_type: string|null, port_type: string}
     */
    public static function forEgg(?Egg $egg): array
    {
        if (! $egg instanceof Egg) {
            return ['tunnel_type' => null, 'port_type' => 'both'];
        }

        // A claimed built-in game decides, so an egg the admin filed under Minecraft gets the
        // Minecraft tunnel whatever it is called.
        if ($egg->game_id !== null) {
            $egg->loadMissing('game');
            $claimed = $egg->game instanceof Game && $egg->game->built_in ? $egg->game->slug : null;
            if ($claimed === GameDetector::MINECRAFT) {
                return ['tunnel_type' => 'minecraft-java', 'port_type' => 'tcp'];
            }

            if ($claimed === GameDetector::TERRARIA) {
                return ['tunnel_type' => 'terraria', 'port_type' => 'tcp'];
            }
        }

        $egg->loadMissing('tags');
        $haystack = mb_strtolower(implode(' ', [$egg->name, ...$egg->tags->map(fn (Tag $tag): string => $tag->slug)->all()]));

        foreach (self::GAMES as $type => [$protocol, $pattern]) {
            if (preg_match($pattern, $haystack) === 1) {
                return ['tunnel_type' => $type, 'port_type' => $protocol];
            }
        }

        return ['tunnel_type' => null, 'port_type' => 'both'];
    }
}
