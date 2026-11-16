<?php

declare(strict_types=1);

namespace Pterodactyl\Jobs\Entrance;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Entrance\MinecraftEntrance;
use Pterodactyl\Services\Entrance\ProxyProtocolApplier;

/**
 * Sets a freshly installed Paper server up for PROXY protocol behind its node's shared
 * Minecraft entrance, when the node forwards player IPs. Until the server is seen reading it,
 * the proxy keeps sending plain connections, so a race with the first start costs nothing.
 */
class ApplyProxyProtocolJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $serverId) {}

    public function handle(ProxyProtocolApplier $applier): void
    {
        $server = Server::query()->with(['node', 'egg'])->find($this->serverId);
        if (! $server instanceof Server || $server->node->mc_entrance_proxy_protocol !== true || ! MinecraftEntrance::isRouted($server)) {
            return;
        }

        $applier->apply($server);
    }
}
