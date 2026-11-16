<?php

declare(strict_types=1);

namespace Pterodactyl\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Pterodactyl\Events\Server\Created;
use Pterodactyl\Events\Server\Installed;
use Pterodactyl\Extensions\Illuminate\Events\Contracts\SubscribesToEvents;
use Pterodactyl\Jobs\Entrance\ApplyProxyProtocolJob;
use Pterodactyl\Services\Entrance\EntranceHostnames;
use Pterodactyl\Services\Entrance\MinecraftEntrance;
use Throwable;

/**
 * New Minecraft servers on a node with the shared Minecraft entrance get their host name at
 * once, and, when the node forwards player IPs, PROXY protocol set up once they are installed.
 * A problem here never stops the server change it follows.
 */
class EntranceListener implements SubscribesToEvents
{
    public function created(Created $event): void
    {
        try {
            if (MinecraftEntrance::isRouted($event->server)) {
                EntranceHostnames::for($event->server);
            }
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }

    public function installed(Installed $event): void
    {
        try {
            $server = $event->server->loadMissing('node');
            if ($server->node->mc_entrance_proxy_protocol === true && MinecraftEntrance::isRouted($server)) {
                dispatch(new ApplyProxyProtocolJob($server->id))->afterCommit();
            }
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Created::class, $this->created(...));
        $events->listen(Installed::class, $this->installed(...));
    }
}
