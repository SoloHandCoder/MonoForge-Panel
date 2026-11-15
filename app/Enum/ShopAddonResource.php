<?php

declare(strict_types=1);

namespace Pterodactyl\Enum;

/**
 * The server resource a shop add-on raises; memory and disk are in MB, cpu in percent of one thread.
 */
enum ShopAddonResource: string
{
    case Backups = 'backups';
    case Databases = 'databases';
    case Allocations = 'allocations';
    case Memory = 'memory';
    case Disk = 'disk';
    case Cpu = 'cpu';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $resource): string => $resource->value, self::cases());
    }
}
