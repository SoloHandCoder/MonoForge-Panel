<?php

declare(strict_types=1);

namespace Pterodactyl\Console\Commands\Assistant;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Pterodactyl\Services\Assistant\AssistantSettings;
use Pterodactyl\Services\Assistant\CrashDoctor\CrashDetector;

#[Description("Compares the servers' states with the last minute's and records crashes for the AI crash doctor.")]
#[Signature('p:crash-doctor:detect')]
class DetectCrashesCommand extends Command
{
    public function handle(CrashDetector $detector): int
    {
        if (! AssistantSettings::crashDoctorAvailable()) {
            $this->components->info('The crash doctor is off; nothing was checked.');

            return self::SUCCESS;
        }

        foreach ($detector->run() as $crash) {
            $this->components->twoColumnDetail("Server #{$crash->server_id}", $crash->kind);
        }

        return self::SUCCESS;
    }
}
