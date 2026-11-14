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
        $this->error('MonoForge is updated from its own repository (github.com/SoloHandCoder/MonoForge-Panel). Run "php artisan p:monoforge:update" or use the update button in the admin overview instead.');

        return self::FAILURE;
    }
}
