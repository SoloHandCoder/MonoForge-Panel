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
use Pterodactyl\Services\Plugins\InstalledPlugins;
use Pterodactyl\Services\Plugins\PluginInstaller;
use Pterodactyl\Services\Plugins\PluginInstallProgress;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Installs (or, with $replaces, updates) one plugin or mod jar in the background, so the Wings
 * download and the hash check never hold a web worker. Runs on the dedicated "installs" queue.
 * One attempt only: a retry would download the file again.
 */
class InstallPluginJob implements ShouldQueue
{
    use Queueable;

    /** The foreground Wings pull (600 s) plus streaming the file back for its hashes. */
    public const int TIMEOUT = 960;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public bool $failOnTimeout = true;

    public int $timeout = self::TIMEOUT;

    public function __construct(
        public readonly int $serverId,
        public readonly int $userId,
        public readonly string $progressId,
        public readonly MarketplaceFile $file,
        public readonly string $directory,
        public readonly string $versionNumber,
        public readonly string $sourceLabel,
        public readonly ?string $replaces = null,
    ) {
        $this->onConnection('installs');
        $this->onQueue((string) config('queue.connections.installs.queue', 'installs'));
    }

    public function handle(PluginInstaller $installer, InstalledPlugins $installed): void
    {
        $server = Server::query()->find($this->serverId);
        if (! $server instanceof Server) {
            return;
        }

        PluginInstallProgress::update($server, $this->progressId, ['status' => PluginInstallProgress::RUNNING]);

        try {
            $name = $installer->install($server, $this->file, $this->directory, $this->replaces);
        } catch (Throwable $throwable) {
            $message = $throwable instanceof HttpExceptionInterface && $throwable->getStatusCode() < 500
                ? $throwable->getMessage()
                : 'The install failed while talking to the server. Please try again.';
            if (! $throwable instanceof HttpExceptionInterface || $throwable->getStatusCode() >= 500) {
                Log::warning('A plugin install failed.', ['server' => $server->uuid, 'exception' => $throwable]);
            }

            PluginInstallProgress::update($server, $this->progressId, ['status' => PluginInstallProgress::FAILED, 'message' => $message]);

            return;
        }

        PluginInstallProgress::update($server, $this->progressId, ['status' => PluginInstallProgress::COMPLETED, 'file_name' => $name]);
        $installed->forget($server);

        $activity = $this->replaces === null
            ? Activity::event('server:plugin.install')->property(['file' => $this->directory.'/'.$name, 'version' => $this->versionNumber, 'source' => $this->sourceLabel])
            : Activity::event('server:plugin.update')->property(['old' => $this->directory.'/'.$this->replaces, 'file' => $this->directory.'/'.$name, 'version' => $this->versionNumber]);
        $activity->subject($server);
        $user = User::query()->find($this->userId);
        if ($user instanceof User) {
            $activity->actor($user);
        }

        $activity->log();
    }

    public function failed(?Throwable $exception): void
    {
        $server = Server::query()->find($this->serverId);
        if ($server instanceof Server) {
            PluginInstallProgress::update($server, $this->progressId, ['status' => PluginInstallProgress::FAILED, 'message' => 'The install stopped before it finished. Please try again.']);
        }
    }
}
