<?php

declare(strict_types=1);

namespace Pterodactyl\Jobs\Tunnels;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Pterodactyl\Enum\TunnelStatus;
use Pterodactyl\Models\Billing\ServerTunnel;
use Pterodactyl\Services\Tunnels\TunnelService;

/**
 * Creates a server's playit.gg tunnel, then checks back every 20 seconds for about five minutes
 * until playit assigns its address. A tunnel still pending or failed after that is picked up by
 * the scheduler (p:tunnels:sync) every ten minutes.
 */
class ProvisionTunnelJob implements ShouldQueue
{
    use Queueable;

    private const int POLLS = 15;

    public int $tries = self::POLLS + 1;

    public int $timeout = 60;

    public function __construct(public readonly int $tunnelId) {}

    public function handle(TunnelService $tunnels): void
    {
        $tunnel = ServerTunnel::query()->find($this->tunnelId);
        if (! $tunnel instanceof ServerTunnel) {
            return;
        }

        $tunnels->sync($tunnel);

        if ($tunnel->status === TunnelStatus::Pending && $this->attempts() < self::POLLS) {
            $this->release(20);
        }
    }
}
