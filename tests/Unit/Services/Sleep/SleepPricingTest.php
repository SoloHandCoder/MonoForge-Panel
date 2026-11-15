<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Unit\Services\Sleep\SleepPricingTest;

use Pterodactyl\Services\Billing\TokenWallet;
use Pterodactyl\Services\Sleep\SleepPricing;

const DAY = 86_400;

test('charges full price × (awake + sleeping × (1 − discount)), rounded up', function (int $price, int $days, int $sleptSeconds, int $discount, int $charge): void {
    expect(SleepPricing::charge($price, $days * DAY, $sleptSeconds, $discount))->toBe($charge)
        ->and(SleepPricing::saved($price, $days * DAY, $sleptSeconds, $discount))->toBe($price - $charge);
})->with([
    'no sleep' => [500, 30, 0, 50, 500],
    'no discount' => [500, 30, 15 * DAY, 0, 500],
    'half the period at 50%' => [500, 30, 15 * DAY, 50, 375],
    'the whole period at 50%' => [500, 30, 30 * DAY, 50, 250],
    'the whole period at 100% is free' => [500, 30, 30 * DAY, 100, 0],
    // 7 × (2/3 + 1/3 × 0.5) = 5.83 → 6.
    'a third at 50% rounds up' => [7, 30, 10 * DAY, 50, 6],
    // 1 × (1 − 1/30 × 0.75) = 0.975 → 1: a small saving never makes a token free.
    'a cheap server keeps its last token' => [1, 30, DAY, 75, 1],
    // 999 × (1 − (1 h / 30 d) × 0.33) = 998.54 → 999.
    'an hour of sleep saves nothing yet' => [999, 30, 3600, 33, 999],
    // 1000 × (1 − 1/3 × 0.3) = 900 exactly: no rounding up when it is whole.
    'an exact result is not rounded' => [1000, 30, 10 * DAY, 30, 900],
    'seconds under a minute do not count' => [500, 30, 59, 100, 500],
    'more sleep than the period is capped' => [500, 30, 90 * DAY, 40, 300],
    'negative values count as nothing' => [500, 30, -5 * DAY, 50, 500],
    'a discount over 100% is capped' => [500, 30, 30 * DAY, 250, 0],
]);

test('stays exact with the largest prices and periods (no overflow)', function (): void {
    $price = TokenWallet::MAX_TOKENS;

    // Ten years, every minute but one asleep, at 99%.
    $period = 3650 * DAY;
    $slept = $period - 60;
    $minutes = intdiv($period, 60);
    $expectedSaved = (int) bcdiv(bcmul((string) $price, (string) (($minutes - 1) * 99)), (string) (100 * $minutes), 0);

    expect(SleepPricing::saved($price, $period, $slept, 99))->toBe($expectedSaved)
        ->and(SleepPricing::charge($price, $period, $slept, 99))->toBe($price - $expectedSaved)
        ->and(SleepPricing::charge($price, $period, $period, 100))->toBe(0);
})->skip(! function_exists('bcmul'), 'bcmath is needed for the reference value.');

test('never charges more than the price or less than nothing', function (): void {
    foreach ([1, 2, 3, 7, 99, 500, 12_345] as $price) {
        foreach ([0, 1, 33, 50, 99, 100] as $discount) {
            foreach ([0, 60, 3600, 5 * DAY, 29 * DAY, 30 * DAY] as $slept) {
                $charge = SleepPricing::charge($price, 30 * DAY, $slept, $discount);
                $exact = $price * (1 - ($slept / (30 * DAY)) * ($discount / 100));

                expect($charge)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual($price)
                    // Rounded up from the exact value (counting whole minutes, so within a token).
                    ->and($charge)->toBeGreaterThanOrEqual((int) floor($exact));
            }
        }
    }
});
