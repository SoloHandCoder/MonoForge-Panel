<?php

declare(strict_types=1);

namespace Pterodactyl\Console\Commands\Tunnels;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Pterodactyl\Enum\TunnelStatus;
use Pterodactyl\Models\Billing\ServerTunnel;
use Pterodactyl\Services\Tunnels\TunnelService;

#[Description('Retries failed playit.gg tunnels and checks pending ones for their address.')]
#[Signature('p:tunnels:sync {--limit=50 : The most tunnels to handle in one run.}')]
class SyncTunnelsCommand extends Command
{
    public function handle(TunnelService $tunnels): int
    {
        $pending = ServerTunnel::query()
            ->whereIn('status', [TunnelStatus::Pending, TunnelStatus::Failed])
            // A node without an agent stays failed until an administrator configures it.
            ->whereHas('server.node', fn (Builder $query) => $query->whereNotNull('playit_agent_id')->whereNotNull('playit_secret'))
            ->oldest('updated_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $active = 0;
        foreach ($pending as $tunnel) {
            if ($tunnels->sync($tunnel)->status === TunnelStatus::Active) {
                $active++;
            }
        }

        $this->components->info(sprintf('Checked %d playit.gg tunnel(s); %d now active.', $pending->count(), $active));

        return self::SUCCESS;
    }
}
