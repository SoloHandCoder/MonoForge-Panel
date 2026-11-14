<?php

declare(strict_types=1);

namespace Pterodactyl\Console\Commands\MonoForge;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Pterodactyl\Exceptions\Service\MonoForge\UpdateFailedException;
use Pterodactyl\Services\MonoForge\MonoForgeRelease;
use Pterodactyl\Services\MonoForge\MonoForgeUpdateService;

#[Description('Checks GitHub for a new MonoForge build and installs it when automatic updates are on.')]
#[Signature('p:monoforge:update
    {--check : Only report whether a newer build is available.}
    {--force : Install the latest build now, whatever the update mode, even if it is already installed.}
    {--pending : Recover an unfinished update, then install an update requested from the panel, if any.}')]
class UpdateCommand extends Command
{
    public function handle(MonoForgeUpdateService $updates): int
    {
        if ($this->option('pending')) {
            return $this->pending($updates);
        }

        $latest = $updates->latest(fresh: true);
        $current = $updates->currentVersion();

        if (! $latest instanceof MonoForgeRelease) {
            $this->components->warn("Running MonoForge build {$current}. The latest build could not be found on GitHub.");

            return $this->option('force') ? self::FAILURE : self::SUCCESS;
        }

        $check = $updates->updateCheck($latest);
        match ($check) {
            'available' => $this->components->info("Update available: MonoForge build {$latest->build->version} (running {$current}). {$latest->pageUrl()}"),
            'unknown' => $this->components->warn("Could not compare build {$current} with the latest build {$latest->build->version} on GitHub, so nothing is installed automatically."),
            default => $this->components->info("MonoForge build {$current} is up to date."),
        };

        if ($this->option('check')) {
            return self::SUCCESS;
        }

        if ($this->option('force') || ($check === 'available' && $updates->mode() === 'auto')) {
            return $this->apply($updates, force: (bool) $this->option('force'));
        }

        return self::SUCCESS;
    }

    private function pending(MonoForgeUpdateService $updates): int
    {
        $recovered = $updates->recoverIfStale();
        if ($recovered !== null) {
            $this->components->warn($recovered['error'] ?? 'Recovered from an unfinished update.');
        }

        return $updates->hasPendingRequest() ? $this->apply($updates, force: false) : self::SUCCESS;
    }

    private function apply(MonoForgeUpdateService $updates, bool $force): int
    {
        try {
            $status = $updates->apply($force);
        } catch (UpdateFailedException $updateFailedException) {
            $this->components->error($updateFailedException->getMessage());

            return self::FAILURE;
        }

        if ($status['state'] === 'failed') {
            $this->components->error($status['error'] ?? 'The update failed.');

            return self::FAILURE;
        }

        $this->components->info($status['message'] ?? 'Done.');

        return self::SUCCESS;
    }
}
