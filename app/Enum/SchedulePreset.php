<?php

declare(strict_types=1);

namespace Pterodactyl\Enum;

/**
 * The Quick setup presets on a server's Schedules page. Each one makes an ordinary schedule with
 * ordinary tasks, tagged with the preset so the page can show which are set up.
 */
enum SchedulePreset: string
{
    case DailyRestart = 'daily_restart';
    case BackupRotation = 'backup_rotation';
    case RestartWhenEmpty = 'restart_when_empty';
    case WeeklyCleanup = 'weekly_cleanup';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $preset): string => $preset->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::DailyRestart => 'Daily restart',
            self::BackupRotation => 'Backup rotation',
            self::RestartWhenEmpty => 'Restart when empty',
            self::WeeklyCleanup => 'Weekly cleanup',
        };
    }
}
