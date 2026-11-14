<?php

declare(strict_types=1);

namespace Pterodactyl\Enum;

/**
 * How serious an announcement or a user notice is; the panel colours it by level.
 */
enum NoticeLevel: string
{
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Danger = 'danger';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $level): string => $level->value, self::cases());
    }
}
