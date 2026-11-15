<?php

declare(strict_types=1);

namespace Pterodactyl\Enum;

/**
 * A catalog that one-click plugins, mods and modpacks are installed from.
 */
enum MarketplaceSource: string
{
    case Modrinth = 'modrinth';
    case Hangar = 'hangar';
    case CurseForge = 'curseforge';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $source): string => $source->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Modrinth => 'Modrinth',
            self::Hangar => 'Hangar',
            self::CurseForge => 'CurseForge',
        };
    }
}
