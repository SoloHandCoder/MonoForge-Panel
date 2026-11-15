<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Billing;

/**
 * What a shop server costs every period, as PricingService::quote() works it out.
 * Every token amount is a whole number and the lines add up to the total.
 */
readonly class Quote
{
    /**
     * @param  int  $basePerPeriod  the plan price for the period with the period modifier, before the multiplier
     * @param  float  $multiplier  the node's multiplier, else its location's, else 1.0
     * @param  int  $addonsPerPeriod  the add-ons for the period with the period modifier
     * @param  int  $totalPerPeriod  what is charged every period, never below 1
     * @param  list<array{label: string, tokens: int}>  $lines  the plan line and one line per add-on, adding up to the total
     * @param  array{memory: int, swap: int, disk: int, io: int, cpu: int, databases: int, backups: int, allocations: int}  $resources  the server's final resources, add-ons included
     */
    public function __construct(
        public int $basePerPeriod,
        public float $multiplier,
        public int $addonsPerPeriod,
        public int $totalPerPeriod,
        public int $periodDays,
        public array $lines,
        public array $resources,
    ) {}

    /**
     * @return array{base_per_period: int, multiplier: float, addons_per_period: int, total_per_period: int, period_days: int, lines: list<array{label: string, tokens: int}>, resources: array<string, int>}
     */
    public function toArray(): array
    {
        return [
            'base_per_period' => $this->basePerPeriod,
            'multiplier' => $this->multiplier,
            'addons_per_period' => $this->addonsPerPeriod,
            'total_per_period' => $this->totalPerPeriod,
            'period_days' => $this->periodDays,
            'lines' => $this->lines,
            'resources' => $this->resources,
        ];
    }
}
