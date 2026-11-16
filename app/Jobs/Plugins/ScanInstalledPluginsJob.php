<?php

declare(strict_types=1);

namespace Pterodactyl\Jobs\Plugins;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Plugins\InstalledPlugins;

/**
 * Hashes the jars of a server's plugins/ or mods/ folder in the background (see
 * InstalledPlugins::scan), on the dedicated "installs" queue.
 */
class ScanInstalledPluginsJob implements ShouldQueue
{
    use Queueable;

    /** 300 jars of at most 100 MB, streamed back from Wings. */
    public const int TIMEOUT = 900;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public int $timeout = self::TIMEOUT;

    public function __construct(public readonly int $serverId, public readonly string $directory)
    {
        $this->onConnection('installs');
        $this->onQueue((string) config('queue.connections.installs.queue', 'installs'));
    }

    public function handle(InstalledPlugins $installed): void
    {
        $server = Server::query()->find($this->serverId);
        if ($server instanceof Server && in_array($this->directory, ['plugins', 'mods'], true)) {
            $installed->scan($server, $this->directory);
        }
    }
}
