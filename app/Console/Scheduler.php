<?php

declare(strict_types=1);

namespace Pterodactyl\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Console\PruneCommand;
use Illuminate\Database\QueryException;
use Pterodactyl\Console\Commands\Billing\ProcessSubscriptionsCommand;
use Pterodactyl\Console\Commands\Billing\PruneTebexPayloadsCommand;
use Pterodactyl\Console\Commands\Maintenance\CleanServiceBackupFilesCommand;
use Pterodactyl\Console\Commands\Maintenance\PruneOrphanedBackupsCommand;
use Pterodactyl\Console\Commands\MonoForge\UpdateCommand;
use Pterodactyl\Console\Commands\Schedule\ProcessRunnableCommand;
use Pterodactyl\Console\Commands\Tunnels\SyncTunnelsCommand;
use Pterodactyl\Contracts\Telemetry\SendsTelemetry;
use Pterodactyl\Models\ActivityLog;
use Pterodactyl\Models\Setting;
use Ramsey\Uuid\Uuid;

final class Scheduler
{
    public function __invoke(Schedule $schedule): void
    {
        // https://laravel.com/docs/10.x/upgrade#redis-cache-tags
        $schedule->command('cache:prune-stale-tags')->hourly();

        // Execute scheduled commands for servers every minute, as if there was a normal cron running.
        $schedule->command(ProcessRunnableCommand::class)->everyMinute()->withoutOverlapping(60)->onOneServer();
        $schedule->command(CleanServiceBackupFilesCommand::class)->daily()->withoutOverlapping();

        if (config('backups.prune_age')) {
            // Every 30 minutes, run the backup pruning command so that any abandoned backups can be deleted.
            $schedule->command(PruneOrphanedBackupsCommand::class)->everyThirtyMinutes()->withoutOverlapping();
        }

        if (config('activity.prune_days')) {
            $schedule->command(PruneCommand::class, ['--model' => [ActivityLog::class]])->daily()->withoutOverlapping();
        }

        // Shop billing: renewals, suspensions, warnings and deletions after the grace period. Every
        // step is safe to repeat, so a missed or overlapping run only delays it.
        $schedule->command(ProcessSubscriptionsCommand::class)->everyFiveMinutes()->withoutOverlapping(30)->onOneServer();

        // Tebex webhook bodies carry the buyer's personal details; keep them 90 days.
        $schedule->command(PruneTebexPayloadsCommand::class)->daily()->withoutOverlapping()->onOneServer();

        // Retry failed playit.gg tunnels and pick up addresses for pending ones.
        $schedule->command(SyncTunnelsCommand::class)->everyTenMinutes()->withoutOverlapping(30)->onOneServer();

        $this->scheduleMonoForgeUpdates($schedule);

        if (config('pterodactyl.telemetry.enabled')) {
            $this->scheduleTelemetry($schedule);
        }
    }

    /**
     * Every minute: recover an update that died part way and pick up updates requested
     * from the admin area. Then check for MonoForge builds daily (notify) or check and
     * install them hourly (auto). These run as the panel user from cron, so updating never
     * needs root.
     */
    private function scheduleMonoForgeUpdates(Schedule $schedule): void
    {
        $schedule->command(UpdateCommand::class, ['--pending'])->everyMinute()->withoutOverlapping(120)->runInBackground();

        $mode = config('monoforge.update.mode');
        if ($mode !== 'notify' && $mode !== 'auto') {
            return;
        }

        if ($mode === 'auto') {
            $schedule->command(UpdateCommand::class)->hourly()->withoutOverlapping(120)->runInBackground();

            return;
        }

        $schedule->command(UpdateCommand::class, ['--check'])->daily()->withoutOverlapping();
    }

    /**
     * Push telemetry daily at a time derived from the install uuid.
     */
    private function scheduleTelemetry(Schedule $schedule): void
    {
        try {
            $uuid = Setting::fetch('app:telemetry:uuid');
        } catch (QueryException) {
            return;
        }

        if ($uuid === null) {
            try {
                $uuid = Uuid::uuid4()->toString();
                Setting::put('app:telemetry:uuid', $uuid);
            } catch (QueryException) {
                return;
            }
        }

        $time = hexdec(str_replace('-', '', mb_substr($uuid, 27))) % 1440;
        $hour = intdiv($time, 60);
        $minute = $time % 60;

        $schedule->call(static function (SendsTelemetry $telemetry): void {
            $telemetry->send();
        })->description('Collect Telemetry')->dailyAt(sprintf('%02d:%02d', $hour, $minute))->withoutOverlapping();
    }
}
