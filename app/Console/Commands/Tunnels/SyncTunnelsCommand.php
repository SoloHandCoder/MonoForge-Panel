<?php

declare(strict_types=1);

namespace Pterodactyl\Console\Commands\Tunnels;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Pterodactyl\Enum\TunnelStatus;
use Pterodactyl\Models\Billing\ServerTunnel;
use Pterodactyl\Models\Node;
use Pterodactyl\Services\Tunnels\TunnelImportService;
use Pterodactyl\Services\Tunnels\TunnelService;

#[Description('Links tunnels made in the playit.gg dashboard to their servers, creates missing tunnels on self-managed agents, retries failed tunnels and checks pending ones for their address.')]
#[Signature('p:tunnels:sync {--limit=50 : The most tunnels to handle in one run.}')]
class SyncTunnelsCommand extends Command
{
    public function handle(TunnelService $tunnels, TunnelImportService $importer): int
    {
        // First link the tunnels made by hand in the playit dashboard (one agent read per node),
        // so a server whose tunnel exists now is not tried again below.
        $linked = $importer->import();
        foreach ($linked['errors'] as $error) {
            $this->components->warn(sprintf('%s: %s', $error['node_name'], $error['message']));
        }

        // Nodes on the self-managed agent the panel set up can create tunnels: give servers that
        // never had one (such as those from before the switch) a tunnel too.
        $created = 0;
        foreach (Node::query()->where('playit_mode', Node::PLAYIT_SELF_MANAGED)->get() as $node) {
            $created += $tunnels->provisionMissing($node);
        }

        $pending = ServerTunnel::query()
            ->whereIn('status', [TunnelStatus::Pending, TunnelStatus::Failed])
            // A node without an agent stays failed until an administrator configures it.
            ->whereHas('server.node', fn (Builder $query) => $query->whereNotNull('playit_agent_id')->whereNotNull('playit_secret'))
            // A read-only agent key cannot create tunnels: those wait for the dashboard and the link above.
            ->where(fn (Builder $query) => $query->whereNotNull('playit_tunnel_id')
                ->orWhereHas('server.node', fn (Builder $node) => $node->whereNull('playit_mode')->orWhere('playit_mode', '!=', Node::PLAYIT_READ_ONLY)))
            ->oldest('updated_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $active = 0;
        foreach ($pending as $tunnel) {
            if ($tunnels->sync($tunnel)->status === TunnelStatus::Active) {
                $active++;
            }
        }

        $this->components->info(sprintf('Linked %d playit.gg tunnel(s) from the dashboard. Queued %d new tunnel(s). Checked %d tunnel(s); %d now active.', $linked['imported'], $created, $pending->count(), $active));

        return self::SUCCESS;
    }
}
