<?php

declare(strict_types=1);

namespace Pterodactyl\Console\Commands\Status;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Pterodactyl\Services\Status\StatusMonitor;

#[Description('Records whether the panel and the nodes on the public status page are reachable.')]
#[Signature('p:status:check')]
class CheckStatusCommand extends Command
{
    public function handle(StatusMonitor $monitor): int
    {
        $checks = $monitor->run();
        if ($checks === []) {
            $this->components->info('The status page is off; nothing was checked.');

            return self::SUCCESS;
        }

        foreach ($checks as $check) {
            $this->components->twoColumnDetail($check['target'], $check['ok'] ? 'up' : 'down');
        }

        return self::SUCCESS;
    }
}
