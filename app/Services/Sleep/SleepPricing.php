<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Sleep;

/**
 * The pay-as-you-play price of a period: full price × (awake fraction + sleeping fraction ×
 * (1 − discount)), rounded up to whole tokens.
 *
 * The saving is computed instead (rounded down), which is the same thing: ceil(price − x) is
 * price − floor(x) for a whole price. Time is counted in whole minutes and the arithmetic is
 * integer only, so the result never depends on floating point rounding and cannot overflow for
 * any period the shop allows (up to 3650 days) and any price the wallet accepts.
 */
final class SleepPricing
{
    /** Tokens saved: floor(price × slept × discount / (100 × period)). */
    public static function saved(int $price, int $periodSeconds, int $sleptSeconds, int $discountPercent): int
    {
        $periodMinutes = max(1, intdiv($periodSeconds, 60));
        $sleptMinutes = max(0, min($periodMinutes, intdiv($sleptSeconds, 60)));
        $discount = max(0, min(100, $discountPercent));

        if ($price <= 0 || $sleptMinutes === 0 || $discount === 0) {
            return 0;
        }

        return min($price, self::mulDivFloor($price, $sleptMinutes * $discount, 100 * $periodMinutes));
    }

    /**
     * Tokens saved when parts of the period slept at different (locked-in) discounts:
     * floor(price × Σ(slept minutes × discount) / (100 × period minutes)). With one discount it
     * equals saved().
     *
     * @param  array<int, int>  $sleptSecondsByDiscount  seconds slept, keyed by discount percent
     */
    public static function savedAtDiscounts(int $price, int $periodSeconds, array $sleptSecondsByDiscount): int
    {
        $periodMinutes = max(1, intdiv($periodSeconds, 60));
        if ($price <= 0) {
            return 0;
        }

        $weighted = 0;
        foreach ($sleptSecondsByDiscount as $discount => $seconds) {
            $weighted += max(0, intdiv($seconds, 60)) * max(0, min(100, $discount));
        }

        $weighted = min($weighted, 100 * $periodMinutes);
        if ($weighted === 0) {
            return 0;
        }

        return min($price, self::mulDivFloor($price, $weighted, 100 * $periodMinutes));
    }

    /** The tokens charged for the period. */
    public static function charge(int $price, int $periodSeconds, int $sleptSeconds, int $discountPercent): int
    {
        return max(0, $price) - self::saved($price, $periodSeconds, $sleptSeconds, $discountPercent);
    }

    /**
     * floor(a × b / c) for a ≥ 0, 0 ≤ b ≤ c, c > 0 without forming a × b: with a = q·c + r,
     * a·b/c = q·b + r·b/c, and r·b < c² stays far inside 64 bits for c up to 100 × 3650 days of minutes.
     */
    private static function mulDivFloor(int $a, int $b, int $c): int
    {
        $q = intdiv($a, $c);
        $r = $a % $c;

        return $q * $b + intdiv($r * $b, $c);
    }
}
