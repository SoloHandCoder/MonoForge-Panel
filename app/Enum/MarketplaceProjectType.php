<?php

declare(strict_types=1);

namespace Pterodactyl\Enum;

/**
 * What a catalog project is: a server plugin (Paper, Spigot, Velocity...), a mod (Fabric,
 * Forge...) or a modpack. Only Modrinth modpacks can be installed.
 */
enum MarketplaceProjectType: string
{
    case Plugin = 'plugin';
    case Mod = 'mod';
    case Modpack = 'modpack';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }
}
