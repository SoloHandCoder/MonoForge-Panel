<?php

declare(strict_types=1);

namespace Pterodactyl\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Explains that MonoForge is not upgraded from Pterodactyl releases.')]
#[Signature('p:upgrade')]
class UpgradeCommand extends Command
{
    public function handle(): int
    {
        $this->error('MonoForge is updated from its own repository (github.com/SoloHandCoder/MonoForge-Panel). Pull the latest code and rebuild instead.');

        return self::FAILURE;
    }
}
