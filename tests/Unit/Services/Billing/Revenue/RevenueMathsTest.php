<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Unit\Services\Billing\Revenue\RevenueMathsTest;

use Carbon\CarbonImmutable;
use Pterodactyl\Services\Billing\Revenue\RevenueRange;
use Pterodactyl\Services\Billing\Revenue\RevenueService;

test('decimal amounts become whole cents without a float', function (mixed $amount, int $cents): void {
    expect(RevenueService::cents($amount))->toBe($cents);
})->with([
    ['12.34', 1234],
    ['12.3', 1230],
    ['12', 1200],
    ['0.10', 10],
    ['.5', 50],
    ['-4.99', -499],
    ['1234567.899', 123456789],
    [1999, 199900],
    ['', 0],
    ['abc', 0],
    [null, 0],
]);

test('cents print as a decimal with two places', function (int $cents, string $decimal): void {
    expect(RevenueService::decimal($cents))->toBe($decimal);
})->with([
    [0, '0.00'],
    [5, '0.05'],
    [1234, '12.34'],
    [-499, '-4.99'],
    [-5, '-0.05'],
]);

test('ranges are whole days with both ends included', function (): void {
    $range = RevenueRange::lastDays(7, CarbonImmutable::parse('2026-11-16 15:30:00'));

    expect($range->from->toDateTimeString())->toBe('2026-11-10 00:00:00')
        ->and($range->to->toDateTimeString())->toBe('2026-11-16 23:59:59')
        ->and($range->days())->toBe(7)
        ->and($range->dates())->toBe(['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-14', '2026-11-15', '2026-11-16'])
        ->and($range->cacheKey())->toBe('billing:revenue:2026-11-10:2026-11-16');

    expect(RevenueRange::between('2026-02-01', '2026-02-01')->days())->toBe(1)
        ->and(RevenueRange::between('2025-11-16', '2026-11-16')->days())->toBe(366);
});
