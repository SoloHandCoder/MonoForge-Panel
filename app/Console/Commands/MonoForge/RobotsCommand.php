<?php

declare(strict_types=1);

namespace Pterodactyl\Console\Commands\MonoForge;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Pterodactyl\Services\Seo\SeoFiles;

#[Description('Writes public/robots.txt with the sitemap address of the configured panel URL. Runs hourly and after an update.')]
#[Signature('p:monoforge:robots')]
class RobotsCommand extends Command
{
    public function handle(SeoFiles $files): int
    {
        if (! $files->writeRobots()) {
            $this->components->error('robots.txt could not be written to the public folder.');

            return self::FAILURE;
        }

        $this->components->info('robots.txt points at the sitemap.');

        return self::SUCCESS;
    }
}
