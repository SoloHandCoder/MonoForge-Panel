<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Billing;

/**
 * STAND-IN for Task R (phase 3). Task R owns this class (built by PricingService::quote()):
 * on merge, keep Task R's version. Task T reads totalPerPeriod and periodDays.
 */
final readonly class Quote
{
    /**
     * @param  list<array{label: string, tokens: int}>  $lines
     * @param  array<string, int>  $resources
     */
    public function __construct(
        public int $basePerPeriod,
        public float $multiplier,
        public int $addonsPerPeriod,
        public int $totalPerPeriod,
        public int $periodDays,
        public array $lines = [],
        public array $resources = [],
    ) {}
}
