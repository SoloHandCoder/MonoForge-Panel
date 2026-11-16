<?php

declare(strict_types=1);

namespace Pterodactyl\Console\Commands\Billing;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Pterodactyl\Services\Billing\ReferralService;

#[Description('Pays the referral rewards whose first purchase has stayed paid for the hold period.')]
#[Signature('p:billing:pay-referrals')]
class PayReferralsCommand extends Command
{
    public function handle(ReferralService $referrals): int
    {
        $this->components->twoColumnDetail('Referral rewards paid', (string) $referrals->payoutDue());

        return self::SUCCESS;
    }
}
