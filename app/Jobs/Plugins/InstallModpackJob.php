<?php

declare(strict_types=1);

namespace Pterodactyl\Jobs\Plugins;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Data\Plugins\MarketplaceFile;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Plugins\ModpackInstaller;
use Pterodactyl\Services\Plugins\ModpackProgress;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Installs a Modrinth modpack on a server in the background (see ModpackInstaller), writing
 * its progress for the Modpacks tab.
 *
 * It runs on the dedicated "installs" connection and queue, whose retry_after (1080 s) is above
 * this job's timeout, so a slow install is never handed to a second worker. While the backup
 * before the install runs, the job puts itself back on the queue every BACKUP_POLL_SECONDS
 * instead of sleeping (at most ModpackInstaller::BACKUP_WAIT in total); any exception ends it,
 * since a retried install would download everything again.
 *
 * The server's install slot (ModpackProgress) is taken by the request and renewed at the start
 * of every run; a run that finds another install holding it does nothing.
 */
class InstallModpackJob implements ShouldQueue
{
    use Queueable;

    public const int TIMEOUT = 1000;

    public const int BACKUP_POLL_SECONDS = 15;

    /** Enough runs for the backup wait; any exception still ends the job at once. */
    public int $tries = 60;

    public int $maxExceptions = 1;

    public bool $failOnTimeout = true;

    public int $timeout = self::TIMEOUT;

    public function __construct(
        public readonly int $serverId,
        public readonly int $userId,
        public readonly MarketplaceFile $pack,
        public readonly string $projectTitle,
        public readonly string $versionName,
        public readonly string $slotToken = '',
        public readonly bool $confirmedWithoutBackup = false,
    ) {
        $this->onConnection('installs');
        $this->onQueue((string) config('queue.connections.installs.queue', 'installs'));
    }

    public function handle(ModpackInstaller $installer): void
    {
        $server = Server::query()->find($this->serverId);
        if (! $server instanceof Server) {
            return;
        }

        if (! ModpackProgress::acquire($server, $this->slotToken)) {
            // Another install holds the server: this one never starts, and its slot is not ours to free.
            ModpackProgress::update($server, ['status' => ModpackProgress::FAILED, 'message' => 'Another modpack install is running on this server.']);

            return;
        }

        $waiting = false;
        try {
            ModpackProgress::update($server, ['status' => ModpackProgress::RUNNING]);
            if (! $installer->backupStep($server, $this->confirmedWithoutBackup)) {
                $waiting = true;
                $this->release(self::BACKUP_POLL_SECONDS);

                return;
            }

            $installer->install($server, $this->pack);
            ModpackProgress::update($server, ['status' => ModpackProgress::COMPLETED, 'step' => 'done', 'message' => null]);
            $this->log($server, 'server:modpack.installed', null);
        } catch (Throwable $throwable) {
            $this->markFailed($server, $throwable);
        } finally {
            if (! $waiting) {
                ModpackProgress::release($server, $this->slotToken);
            }
        }
    }

    /** A worker that died or timed out: the install is marked failed so the slot is free again. */
    public function failed(?Throwable $exception): void
    {
        $server = Server::query()->find($this->serverId);
        if ($server instanceof Server) {
            if ((ModpackProgress::get($server)['status'] ?? null) !== ModpackProgress::FAILED) {
                ModpackProgress::update($server, ['status' => ModpackProgress::FAILED, 'message' => 'The install stopped before it finished. Check the server files, or restore the backup.']);
            }

            ModpackProgress::release($server, $this->slotToken);
        }
    }

    private function markFailed(Server $server, Throwable $exception): void
    {
        // Only messages written for users are shown; anything else is logged.
        $message = $exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500
            ? $exception->getMessage()
            : 'The install failed while talking to the server. Nothing was changed if it failed before the install step; otherwise restore the backup.';

        if (! $exception instanceof HttpExceptionInterface || $exception->getStatusCode() >= 500) {
            Log::warning('A modpack install failed.', ['server' => $server->uuid, 'exception' => $exception]);
        }

        ModpackProgress::update($server, ['status' => ModpackProgress::FAILED, 'message' => $message]);
        $this->log($server, 'server:modpack.failed', $message);
    }

    private function log(Server $server, string $event, ?string $reason): void
    {
        $user = User::query()->find($this->userId);
        $activity = Activity::event($event)->subject($server)->property(array_filter([
            'name' => $this->projectTitle,
            'version' => $this->versionName,
            'reason' => $reason,
        ], fn (?string $value): bool => $value !== null));

        if ($user instanceof User) {
            $activity->actor($user);
        }

        $activity->log();
    }
}
