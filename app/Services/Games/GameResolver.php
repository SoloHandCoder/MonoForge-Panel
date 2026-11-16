<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Games;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Game;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Tag;

/**
 * Which game a server runs: the game in Admin > Games that claims its egg, or, for an egg no
 * game claims, the built-in game the panel's detection ({@see GameDetector}) recognises.
 * Every "is this Minecraft?" question in the panel goes through here.
 */
final class GameResolver
{
    public static function forServer(?Server $server): ?Game
    {
        if (! $server instanceof Server) {
            return null;
        }

        $server->loadMissing('egg');

        return self::forEgg($server->egg);
    }

    public static function forEgg(?Egg $egg): ?Game
    {
        if (! $egg instanceof Egg) {
            return null;
        }

        if ($egg->game_id !== null) {
            $egg->loadMissing('game');
            if ($egg->game instanceof Game) {
                return $egg->game;
            }
        }

        $egg->loadMissing('tags');
        $detected = GameDetector::detect($egg->name, array_values($egg->tags->map(fn (Tag $tag): string => $tag->slug)->all()));

        return $detected === null ? null : self::builtIn($detected);
    }

    /** The slug of the server's game, or null. */
    public static function slugFor(?Server $server): ?string
    {
        return self::forServer($server)?->slug;
    }

    public static function isMinecraft(?Server $server): bool
    {
        return self::slugFor($server) === GameDetector::MINECRAFT;
    }

    /**
     * The built-in game, read once per request (or test) so a list of servers on unclaimed
     * eggs runs one query per game rather than one per server. Saving a game forgets it.
     */
    private static function builtIn(string $slug): ?Game
    {
        return once(fn (): ?Game => Game::query()->where('slug', $slug)->where('built_in', true)->first());
    }
}
