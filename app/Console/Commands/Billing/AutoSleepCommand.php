<?php

declare(strict_types=1);

namespace Pterodactyl\Console\Commands\Billing;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Pterodactyl\Services\Sleep\ServerSleep;

#[Description('Pay-as-you-play: puts shop servers nobody plays on to sleep, and notices sleeping servers somebody started again.')]
#[Signature('p:billing:auto-sleep')]
class AutoSleepCommand extends Command
{
    public function handle(ServerSleep $sleep): int
    {
        $counts = $sleep->check();

        $this->components->twoColumnDetail('Servers put to sleep', (string) $counts['asleep']);
        $this->components->twoColumnDetail('Sleeping servers started again', (string) $counts['awake']);

        return self::SUCCESS;
    }
}
