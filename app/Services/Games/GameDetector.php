<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Games;

/**
 * The panel's built-in guess of which game an egg runs, from its name and tag slugs. This is
 * the detection the panel always used (the playit tunnel profile's patterns); it now only
 * decides for eggs that no game in Admin > Games claims, and pre-fills the built-in games.
 *
 * Bedrock is checked first so "Minecraft: Bedrock" eggs (which also carry the `minecraft`
 * umbrella tag) are not taken for Minecraft Java.
 */
final class GameDetector
{
    public const string MINECRAFT = 'minecraft';

    public const string TERRARIA = 'terraria';

    /** Patterns that rule an egg out of every built-in game. */
    private const string BEDROCK = '/\b(bedrock|pocketmine|nukkit|geyser-standalone)\b/';

    /** @var array<string, string> built-in game slug => pattern matched against the egg name and tags */
    private const array GAMES = [
        self::MINECRAFT => '/\b(minecraft|paper|purpur|spigot|bukkit|bungeecord|waterfall|velocity|forge|neoforge|fabric|quilt|sponge|folia|mohist|magma)\b/',
        self::TERRARIA => '/\b(terraria|tmodloader|tshock)\b/',
    ];

    /**
     * The built-in game slug an egg looks like, or null.
     *
     * @param  list<string>  $tagSlugs
     */
    public static function detect(string $eggName, array $tagSlugs = []): ?string
    {
        $haystack = mb_strtolower(implode(' ', [$eggName, ...$tagSlugs]));

        if (preg_match(self::BEDROCK, $haystack) === 1) {
            return null;
        }

        foreach (self::GAMES as $slug => $pattern) {
            if (preg_match($pattern, $haystack) === 1) {
                return $slug;
            }
        }

        return null;
    }
}
