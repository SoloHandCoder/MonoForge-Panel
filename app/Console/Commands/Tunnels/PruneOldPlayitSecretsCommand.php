<?php

declare(strict_types=1);

namespace Pterodactyl\Console\Commands\Tunnels;

use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Pterodactyl\Models\Node;

/**
 * After a node switched to a self-managed playit agent, the old agent's key is kept so the
 * switch can be undone and the old tunnels removed. RETENTION_DAYS after the switch it is
 * dropped: the switch can no longer be undone from the panel then.
 */
#[Description('Drops the previous playit.gg agent key of nodes that switched to a self-managed agent more than 30 days ago.')]
#[Signature('p:tunnels:prune-old-secrets')]
class PruneOldPlayitSecretsCommand extends Command
{
    public const int RETENTION_DAYS = 30;

    public function handle(): int
    {
        $pruned = Node::query()
            ->whereNotNull('playit_previous_secret')
            ->whereNotNull('playit_switched_at')
            ->where('playit_switched_at', '<', CarbonImmutable::now()->subDays(self::RETENTION_DAYS))
            ->update(['playit_previous_secret' => null]);

        $this->components->twoColumnDetail('Old playit.gg agent keys dropped', (string) $pruned);

        return self::SUCCESS;
    }
}
