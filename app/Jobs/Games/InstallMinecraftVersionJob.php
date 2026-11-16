<?php

declare(strict_types=1);

namespace Pterodactyl\Jobs\Games;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Activity\ActivityLogService;
use Pterodactyl\Services\Games\Minecraft\MinecraftSoftware;
use Pterodactyl\Services\Games\Minecraft\MinecraftVersionInstaller;
use Pterodactyl\Services\Plugins\PluginInstallProgress;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Switches a Minecraft server's software in the background, so the Wings download and the hash
 * check never hold a web worker. Runs on the "installs" queue, once: a retry would download the
 * jar again.
 */
class InstallMinecraftVersionJob implements ShouldQueue
{
    use Queueable;

    /** The foreground Wings pull (600 s) plus streaming the jar back for its hash. */
    public const int TIMEOUT = 960;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public bool $failOnTimeout = true;

    public int $timeout = self::TIMEOUT;

    /**
     * @param  array{software: string, version: string, minecraft: string, url: string|null, filename: string|null, hashes: array<string, string>}  $download
     */
    public function __construct(
        public readonly int $serverId,
        public readonly int $userId,
        public readonly string $progressId,
        public readonly array $download,
    ) {
        $this->onConnection('installs');
        $this->onQueue((string) config('queue.connections.installs.queue', 'installs'));
    }

    public function handle(MinecraftVersionInstaller $installer): void
    {
        $server = Server::query()->find($this->serverId);
        if (! $server instanceof Server) {
            return;
        }

        $software = MinecraftSoftware::from($this->download['software']);
        PluginInstallProgress::update($server, $this->progressId, ['status' => PluginInstallProgress::RUNNING]);

        try {
            $installed = $installer->install($server, $software, $this->download);
        } catch (Throwable $throwable) {
            $visible = $throwable instanceof HttpExceptionInterface && $throwable->getStatusCode() < 500;
            $message = $visible ? $throwable->getMessage() : 'The switch failed while talking to the server. Please try again.';
            if (! $visible) {
                Log::warning('A Minecraft version switch failed.', ['server' => $server->uuid, 'exception' => $throwable]);
            }

            PluginInstallProgress::update($server, $this->progressId, ['status' => PluginInstallProgress::FAILED, 'message' => $message]);
            $this->log($server, Activity::event('server:version.failed')->property(['software' => $software->label(), 'version' => $this->download['version'], 'reason' => mb_substr($message, 0, 200)]));

            return;
        }

        PluginInstallProgress::update($server, $this->progressId, ['status' => PluginInstallProgress::COMPLETED, 'file_name' => $installed]);
        $this->log($server, Activity::event('server:version.install')->property(['software' => $software->label(), 'version' => $this->download['version']]));
    }

    public function failed(?Throwable $exception): void
    {
        $server = Server::query()->find($this->serverId);
        if ($server instanceof Server) {
            PluginInstallProgress::update($server, $this->progressId, ['status' => PluginInstallProgress::FAILED, 'message' => 'The switch stopped before it finished. Please try again.']);
        }
    }

    private function log(Server $server, ActivityLogService $activity): void
    {
        $activity->subject($server);

        $user = User::query()->find($this->userId);
        if ($user instanceof User) {
            $activity->actor($user);
        }

        $activity->log();
    }
}
