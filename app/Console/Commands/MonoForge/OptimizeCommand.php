<?php

declare(strict_types=1);

namespace Pterodactyl\Console\Commands\MonoForge;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Pterodactyl\Services\MonoForge\MonoForgeUpdateService;

#[Description('Caches the panel config, routes, events and views like a MonoForge update does; clears them again if that fails. Run it after editing .env.')]
#[Signature('p:monoforge:optimize')]
class OptimizeCommand extends Command
{
    public function handle(MonoForgeUpdateService $updates): int
    {
        $warning = $updates->optimize();
        if ($warning !== null) {
            $this->components->error($warning);

            return self::FAILURE;
        }

        $this->components->info('Cached the panel config, routes, events and views.');

        return self::SUCCESS;
    }
}
