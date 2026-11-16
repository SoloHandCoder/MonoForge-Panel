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

    /**
     * A custom range of whole days. Times are dropped, so every request for the same days shares
     * one cache entry, and a range longer than MAX_DAYS keeps its last MAX_DAYS days (the request
     * refuses those anyway).
     */
    public static function between(string $from, string $to): self
    {
        $end = CarbonImmutable::parse(mb_substr(mb_trim($to), 0, 10))->endOfDay();
        $start = CarbonImmutable::parse(mb_substr(mb_trim($from), 0, 10))->startOfDay();
        $earliest = $end->startOfDay()->subDays(self::MAX_DAYS - 1);

        return new self($start->lt($earliest) ? $earliest : $start, $end);
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
