<?php

declare(strict_types=1);

namespace Pterodactyl\Console\Commands\Tunnels;

use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Pterodactyl\Enum\TunnelStatus;
use Pterodactyl\Models\Billing\ServerTunnel;
use Pterodactyl\Models\Game;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Entrance\EntranceTunnelService;
use Pterodactyl\Services\Games\GameResolver;
use Pterodactyl\Services\Tunnels\TunnelImportService;
use Pterodactyl\Services\Tunnels\TunnelService;

#[Description('Links tunnels made in the playit.gg dashboard to their servers, creates missing tunnels on self-managed agents, retries failed tunnels and checks pending ones for their address.')]
#[Signature('p:tunnels:sync {--limit=50 : The most tunnels to handle in one run.}')]
class SyncTunnelsCommand extends Command
{
    public function handle(TunnelService $tunnels, TunnelImportService $importer, EntranceTunnelService $entrances): int
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
        // Nodes in game gateway mode get no playit tunnels at all: they are skipped everywhere below.
        foreach (Node::query()->where('playit_mode', Node::PLAYIT_SELF_MANAGED)->where('gateway_enabled', false)->get() as $node) {
            $created += $tunnels->provisionMissing($node);
        }

        // On every other node that can create tunnels, servers that have their main tunnel get
        // the extra ones their game asks for (such as a voice chat port added later).
        $others = Node::query()->whereNotNull('playit_agent_id')->whereNotNull('playit_secret')->where('gateway_enabled', false)
            ->where(fn (Builder $query) => $query->whereNull('playit_mode')->orWhereNotIn('playit_mode', [Node::PLAYIT_SELF_MANAGED, Node::PLAYIT_READ_ONLY]))
            ->get();
        foreach ($others as $node) {
            $created += $tunnels->provisionMissing($node, extrasOnly: true);
        }

        $pending = ServerTunnel::query()
            ->whereIn('status', [TunnelStatus::Pending, TunnelStatus::Failed])
            // A node without an agent stays failed until an administrator configures it.
            ->whereHas('server.node', fn (Builder $query) => $query->whereNotNull('playit_agent_id')->whereNotNull('playit_secret')->where('gateway_enabled', false))
            // A read-only agent key cannot create tunnels: those wait for the dashboard and the link above.
            ->where(fn (Builder $query) => $query->whereNotNull('playit_tunnel_id')
                ->orWhereHas('server.node', fn (Builder $node) => $node->whereNull('playit_mode')->orWhere('playit_mode', '!=', Node::PLAYIT_READ_ONLY)))
            // Held tunnels wait: "not a game" ones for good, "tunnel limit" ones until their back-off ends.
            ->where(fn (Builder $query) => $query->whereNull('hold_reason')
                ->orWhere(fn (Builder $held) => $held->whereNotNull('retry_after')->where('retry_after', '<=', CarbonImmutable::now())))
            ->with('server.egg')
            ->oldest('updated_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $active = 0;
        $held = 0;
        foreach ($pending as $tunnel) {
            // A failed tunnel nothing exists for on playit, of a server whose egg is not a game
            // that wants tunnels, is parked instead of being retried every ten minutes.
            $server = $tunnel->server;
            if ($server instanceof Server && $tunnel->status === TunnelStatus::Failed && $tunnel->playit_tunnel_id === null && ! $tunnel->isImported()) {
                $game = GameResolver::forServer($server);
                if (! $game instanceof Game || ! $game->auto_tunnels) {
                    $tunnels->holdNotAGame($server);
                    $held++;

                    continue;
                }
            }

            if ($tunnels->sync($tunnel)->status === TunnelStatus::Active) {
                $active++;
            }
        }

        // The one playit tunnel of each node whose shared Minecraft entrance goes through playit
        // (and the removal of one that is no longer needed).
        foreach (Node::query()->where('mc_entrance_enabled', true)->orWhereNotNull('mc_entrance_tunnel_id')->get() as $node) {
            $entrances->ensure($node);
        }

        $this->components->info(sprintf('Linked %d playit.gg tunnel(s) from the dashboard. Queued %d new tunnel(s). Checked %d tunnel(s); %d now active; %d parked as not a game.', $linked['imported'], $created, $pending->count() - $held, $active, $held));

        return self::SUCCESS;
    }
}
