<?php

declare(strict_types=1);

namespace Pterodactyl\Console\Commands\MonoForge;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Pterodactyl\Services\ServerPages\PublicServerPages;

#[Description('Refreshes the status the server discovery page shows for the listed public server pages that nobody opened lately. Runs every five minutes.')]
#[Signature('p:monoforge:refresh-discovery {--limit=25 : The most pages refreshed in one run.}')]
class RefreshServerDiscoveryCommand extends Command
{
    /** A page whose status is younger than this was opened lately and is left alone. */
    public const int STALE_SECONDS = 240;

    public function handle(PublicServerPages $pages): int
    {
        $limit = max(1, min(200, (int) $this->option('limit')));
        $count = $pages->refreshStale(self::STALE_SECONDS, $limit);

        $this->components->info("Refreshed the status of {$count} listed server pages.");

        return self::SUCCESS;
    }
}
