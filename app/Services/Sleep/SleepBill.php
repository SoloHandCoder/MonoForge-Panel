<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Sleep;

/**
 * A renewal's price after the pay-as-you-play discount.
 */
final readonly class SleepBill
{
    public function __construct(
        /** The subscription's price for a full period. */
        public int $fullPrice,
        /** What is charged: the full price less the saving (0 when the whole period slept at 100%). */
        public int $charge,
        public int $saved,
        public int $sleptSeconds,
        public int $discountPercent,
    ) {}
}
