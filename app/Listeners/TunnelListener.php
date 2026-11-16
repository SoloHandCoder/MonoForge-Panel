<?php

declare(strict_types=1);

namespace Pterodactyl\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Pterodactyl\Events\Server\Created;
use Pterodactyl\Events\Server\Deleting;
use Pterodactyl\Events\Server\Updated;
use Pterodactyl\Extensions\Illuminate\Events\Contracts\SubscribesToEvents;
use Pterodactyl\Models\Billing\ServerTunnel;
use Pterodactyl\Services\Tunnels\TunnelService;
use Throwable;

/**
 * Keeps playit.gg tunnels in step with servers however they are created or deleted: the admin
 * area, the application API or the shop. A tunnel problem is reported but never stops the
 * server change it follows.
 */
class TunnelListener implements SubscribesToEvents
{
    public function __construct(private readonly TunnelService $tunnels) {}

    public function created(Created $event): void
    {
        $this->safely(function () use ($event): void {
            $event->server->loadMissing('node');
            if ($event->server->node->hasPlayitAgent()) {
                // Only servers whose egg belongs to a game with automatic tunnels get one.
                $this->tunnels->provisionAutomatic($event->server);
            }
        });
    }

    /**
     * The server is gone, so its tunnel goes too, including one made by hand on playit and
     * imported: left behind it would point at a port the next server on it gets.
     */
    public function deleting(Deleting $event): void
    {
        $this->safely(fn () => $this->tunnels->release($event->server, deleteImported: true));
    }

    /**
     * A new primary port needs a new tunnel pointing at it. An imported tunnel is left on
     * playit (it may serve something the owner set up by hand); the server gets a new one.
     */
    public function updated(Updated $event): void
    {
        if (! $event->server->wasChanged('allocation_id')) {
            return;
        }

        $this->safely(function () use ($event): void {
            $tunnel = ServerTunnel::query()->where('server_id', $event->server->id)->where('port_key', ServerTunnel::MAIN)->first();
            if ($tunnel instanceof ServerTunnel && $tunnel->allocation_id !== $event->server->allocation_id) {
                $this->tunnels->recreate($tunnel);
            }
        });
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Created::class, $this->created(...));
        $events->listen(Deleting::class, $this->deleting(...));
        $events->listen(Updated::class, $this->updated(...));
    }

    private function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }
}
