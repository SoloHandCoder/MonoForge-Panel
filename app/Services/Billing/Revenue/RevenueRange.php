<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Billing\Revenue;

use Carbon\CarbonImmutable;

/**
 * The days the revenue dashboard reports on: whole days in the panel's timezone, `from` and `to`
 * both included.
 */
final readonly class RevenueRange
{
    /** The longest custom range, so one request never walks years of the ledger. */
    public const int MAX_DAYS = 366;

    public function __construct(public CarbonImmutable $from, public CarbonImmutable $to) {}

    /** The last `$days` days, today included. */
    public static function lastDays(int $days, ?CarbonImmutable $today = null): self
    {
        $today = ($today ?? CarbonImmutable::now())->startOfDay();

        return new self($today->subDays(max(1, $days) - 1), $today->endOfDay());
    }

    public static function between(string $from, string $to): self
    {
        return new self(CarbonImmutable::parse($from)->startOfDay(), CarbonImmutable::parse($to)->endOfDay());
    }

    public function days(): int
    {
        return (int) $this->from->startOfDay()->diffInDays($this->to->startOfDay()) + 1;
    }

    /**
     * Every day of the range, as Y-m-d.
     *
     * @return list<string>
     */
    public function dates(): array
    {
        $dates = [];
        for ($day = $this->from->startOfDay(), $i = 0; $i < $this->days(); $day = $day->addDay(), $i++) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }

    public function cacheKey(): string
    {
        return sprintf('billing:revenue:%s:%s', $this->from->toDateString(), $this->to->toDateString());
    }
}
