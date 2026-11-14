<?php

declare(strict_types=1);

namespace Pterodactyl\Console\Commands\MonoForge;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Pterodactyl\Exceptions\Service\MonoForge\UpdateFailedException;
use Pterodactyl\Services\MonoForge\MonoForgeUpdateService;

#[Description('Restores the code from before an unfinished MonoForge update (or the newest code backup) and leaves maintenance mode.')]
#[Signature('p:monoforge:recover')]
class RecoverCommand extends Command
{
    public function handle(MonoForgeUpdateService $updates): int
    {
        try {
            $status = $updates->recover();
        } catch (UpdateFailedException $updateFailedException) {
            $this->components->error($updateFailedException->getMessage());

            return self::FAILURE;
        }

        $failed = ($status['message'] ?? '') !== 'Recovered from an unfinished update.';
        $failed ? $this->components->error($status['error'] ?? 'Recovery failed.') : $this->components->info($status['error'] ?? 'Recovered.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
