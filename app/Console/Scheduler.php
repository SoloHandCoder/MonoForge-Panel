<?php

declare(strict_types=1);

namespace Pterodactyl\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Console\PruneCommand;
use Illuminate\Database\QueryException;
use Pterodactyl\Console\Commands\Assistant\DetectCrashesCommand;
use Pterodactyl\Console\Commands\Billing\AutoSleepCommand;
use Pterodactyl\Console\Commands\Billing\ProcessSubscriptionsCommand;
use Pterodactyl\Console\Commands\Billing\PruneTebexPayloadsCommand;
use Pterodactyl\Console\Commands\Maintenance\CleanServiceBackupFilesCommand;
use Pterodactyl\Console\Commands\Maintenance\PruneOrphanedBackupsCommand;
use Pterodactyl\Console\Commands\MonoForge\UpdateCommand;
use Pterodactyl\Console\Commands\Schedule\ProcessRunnableCommand;
use Pterodactyl\Console\Commands\Status\CheckStatusCommand;
use Pterodactyl\Console\Commands\Support\PruneSupportAttachmentsCommand;
use Pterodactyl\Console\Commands\Tunnels\SyncTunnelsCommand;
use Pterodactyl\Contracts\Telemetry\SendsTelemetry;
use Pterodactyl\Models\ActivityLog;
use Pterodactyl\Models\Billing\TebexCheckout;
use Pterodactyl\Models\FriendRequest;
use Pterodactyl\Models\ServerCrash;
use Pterodactyl\Models\Setting;
use Pterodactyl\Models\Share\ServerPageSlugHold;
use Pterodactyl\Models\Share\ServerPageVote;
use Pterodactyl\Models\Status\StatusCheck;
use Pterodactyl\Models\Status\StatusDay;
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
        // Pay-as-you-play: one batched Wings read and one player query per running auto-sleep
        // server a minute; idle servers are stopped and sleeping ones that were started noticed.
        $schedule->command(AutoSleepCommand::class)->everyMinute()->withoutOverlapping(5)->onOneServer();

        // Tebex webhook bodies carry the buyer's personal details; keep them 90 days.
        $schedule->command(PruneTebexPayloadsCommand::class)->daily()->withoutOverlapping()->onOneServer();
        // Token checkouts no payment was ever linked to, after 30 days (abandoned baskets).
        $schedule->command(PruneCommand::class, ['--model' => [TebexCheckout::class]])->daily()->withoutOverlapping()->onOneServer();

        // Link tunnels made in the playit.gg dashboard, retry failed ones and pick up addresses for pending ones.
        $schedule->command(SyncTunnelsCommand::class)->everyTenMinutes()->withoutOverlapping(30)->onOneServer();

        // The public status page: one cheap reachability check of the panel and each shown node
        // a minute, and the checks and their daily sums dropped after 90 days.
        $schedule->command(CheckStatusCommand::class)->everyMinute()->withoutOverlapping(5)->onOneServer();
        $schedule->command(PruneCommand::class, ['--model' => [StatusCheck::class, StatusDay::class]])->daily()->withoutOverlapping()->onOneServer();

        // Public server page votes only matter while they block the next one (24 hours).
        $schedule->command(PruneCommand::class, ['--model' => [ServerPageVote::class, ServerPageSlugHold::class]])->daily()->withoutOverlapping()->onOneServer();
        // The AI crash doctor: compare the servers' states each minute (cached Wings readings, one
        // pool per node) and record crashes; crashes go after 30 days.
        $schedule->command(DetectCrashesCommand::class)->everyMinute()->withoutOverlapping(5)->onOneServer();
        $schedule->command(PruneCommand::class, ['--model' => [ServerCrash::class]])->daily()->withoutOverlapping()->onOneServer();
        // Friend requests nobody answered within 30 days.
        $schedule->command(PruneCommand::class, ['--model' => [FriendRequest::class]])->daily()->withoutOverlapping()->onOneServer();
        // Images on support tickets closed more than 180 days ago (the messages stay).
        $schedule->command(PruneSupportAttachmentsCommand::class)->daily()->withoutOverlapping()->onOneServer();

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
