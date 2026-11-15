<?php

declare(strict_types=1);

namespace Pterodactyl\Enum;

/**
 * What a shop price multiplier applies to; a node's own multiplier wins over its location's.
 */
enum ShopMultiplierScope: string
{
    case Location = 'location';
    case Node = 'node';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $scope): string => $scope->value, self::cases());
    }
}
