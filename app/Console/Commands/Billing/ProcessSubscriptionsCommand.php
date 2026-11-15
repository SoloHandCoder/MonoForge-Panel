<?php

declare(strict_types=1);

namespace Pterodactyl\Console\Commands\Billing;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Pterodactyl\Services\Billing\SubscriptionService;
use Throwable;

#[Description('Bills shop servers: collects paid top-ups, renews due subscriptions, suspends unpaid ones, sends warnings and deletes servers after the grace period.')]
#[Signature('p:billing:process')]
class ProcessSubscriptionsCommand extends Command
{
    public function handle(SubscriptionService $subscriptions): int
    {
        // Top-ups first, so a server whose owner just paid is restored instead of deleted.
        $steps = [
            'restored after a top-up' => $subscriptions->collectOverdue(...),
            'renewals processed' => $subscriptions->renewDue(...),
            'server states synced with Wings' => $subscriptions->syncServerStates(...),
            'short balance warnings' => $subscriptions->warnUpcoming(...),
            'deletion warnings' => $subscriptions->warnDeletionSoon(...),
            'servers deleted after the grace period' => $subscriptions->deleteExpired(...),
        ];

        $failed = false;
        foreach ($steps as $label => $step) {
            try {
                $this->components->twoColumnDetail(ucfirst($label), (string) $step());
            } catch (Throwable $throwable) {
                // Each step is safe to run again, so one failing step must not block the others.
                report($throwable);
                $this->components->error(ucfirst($label).': '.$throwable->getMessage());
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
