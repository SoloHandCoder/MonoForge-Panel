<?php

declare(strict_types=1);

namespace Pterodactyl\Enum;

/**
 * Where a server's playit.gg tunnel stands: being created or waiting for playit to assign an
 * address, live, failed (retried by the scheduler), or removed.
 */
enum TunnelStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Failed = 'failed';
    case Deleted = 'deleted';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }
}
