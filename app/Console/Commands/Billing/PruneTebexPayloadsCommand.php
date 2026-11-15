<?php

declare(strict_types=1);

namespace Pterodactyl\Console\Commands\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Pterodactyl\Models\Billing\TebexPayment;

#[Description('Removes the buyer details from Tebex webhook bodies stored for more than 90 days, keeping the payments and their event list.')]
#[Signature('p:billing:prune-tebex-payloads')]
class PruneTebexPayloadsCommand extends Command
{
    public function handle(): int
    {
        $before = CarbonImmutable::now()->subDays(TebexPayment::PAYLOAD_RETENTION_DAYS);
        $pruned = 0;

        TebexPayment::query()
            ->whereNull('raw_pruned_at')
            ->where('updated_at', '<', $before)
            ->lazyById(200)
            ->each(function (TebexPayment $payment) use (&$pruned): void {
                $payment->pruneRaw();
                $pruned++;
            });

        $this->components->twoColumnDetail('Tebex payloads pruned', (string) $pruned);

        return self::SUCCESS;
    }
}
