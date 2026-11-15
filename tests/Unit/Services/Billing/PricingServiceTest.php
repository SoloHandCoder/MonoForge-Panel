<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Unit\Services\Billing\PricingServiceTest;

use Pterodactyl\Enum\ShopAddonResource;
use Pterodactyl\Models\Billing\ShopAddon;
use Pterodactyl\Models\Billing\ShopPeriod;
use Pterodactyl\Models\Billing\ShopPlan;
use Pterodactyl\Services\Billing\PricingService;
use Pterodactyl\Services\Billing\Quote;

function plan(int $price = 500): ShopPlan
{
    return new ShopPlan([
        'name' => 'Stone',
        'price_tokens' => $price,
        'memory' => 2048,
        'swap' => 0,
        'disk' => 10240,
        'cpu' => 100,
        'io' => 500,
        'databases' => 1,
        'backups' => 1,
        'allocations' => 0,
        'egg_ids' => [1],
    ]);
}

function period(int $days = 30, int $modifier = 0): ShopPeriod
{
    return new ShopPeriod(['name' => "{$days} days", 'days' => $days, 'price_modifier_percent' => $modifier]);
}

function addon(string $key, ShopAddonResource $resource, int $amount, int $price): ShopAddon
{
    return new ShopAddon([
        'key' => $key,
        'name' => ucfirst($key),
        'resource' => $resource,
        'amount' => $amount,
        'price_tokens' => $price,
        'max_units' => 5,
        'enabled' => true,
    ]);
}

/**
 * @param  list<array{ShopAddon, int}>  $addons
 */
function price(ShopPlan $plan, ShopPeriod $period, float $multiplier = 1.0, array $addons = []): Quote
{
    return (new PricingService())->calculate($plan, $period, $multiplier, $addons);
}

test('a 30-day period charges the plan price', function (): void {
    $quote = price(plan(500), period());

    expect($quote->basePerPeriod)->toBe(500)
        ->and($quote->totalPerPeriod)->toBe(500)
        ->and($quote->addonsPerPeriod)->toBe(0)
        ->and($quote->multiplier)->toBe(1.0)
        ->and($quote->periodDays)->toBe(30)
        ->and($quote->lines)->toBe([['label' => 'Stone · 30 days', 'tokens' => 500]]);
});

test('longer periods scale by days and apply their discount or surcharge', function (int $days, int $modifier, int $expected): void {
    $quote = price(plan(500), period($days, $modifier));

    expect($quote->basePerPeriod)->toBe($expected)->and($quote->totalPerPeriod)->toBe($expected);
})->with([
    '90 days, 20% off' => [90, -20, 1200],
    '90 days, no modifier' => [90, 0, 1500],
    '7 days, 10% surcharge' => [7, 10, 128],
    // 500 x 365 / 30 x 1.1 = 6691.67, rounded to 6692
    '365 days, 10% surcharge' => [365, 10, 6692],
    // 500 x 365 / 30 x 0.75 = 4562.5, rounded half up to 4563
    '365 days, 25% off' => [365, -25, 4563],
]);

test('the multiplier scales the plan and the total rounds up', function (): void {
    expect(price(plan(500), period(), 1.5)->totalPerPeriod)->toBe(750);

    // 333 x 1.15 = 382.95, rounded up to 383; the base stays without the multiplier.
    $quote = price(plan(333), period(), 1.15);
    expect($quote->basePerPeriod)->toBe(333)
        ->and($quote->totalPerPeriod)->toBe(383)
        ->and($quote->lines[0]['label'])->toBe('Stone · 30 days × 1.15');
});

test('float noise does not add a token', function (): void {
    // 100 x 1.15 is 114.99999999999999 in floating point; it must still cost 115.
    expect(price(plan(100), period(), 1.15)->totalPerPeriod)->toBe(115);
    // 3 x 1.1 = 3.3000000000000003 rounds up to 4, not 5.
    expect(price(plan(3), period(), 1.1)->totalPerPeriod)->toBe(4);
});

test('add-ons are priced per unit for the period and are not multiplied', function (): void {
    $backups = addon('backup', ShopAddonResource::Backups, 1, 50);
    $memory = addon('ram', ShopAddonResource::Memory, 1024, 100);

    $quote = price(plan(500), period(90, -20), 1.5, [[$backups, 2], [$memory, 1]]);

    // plan 500 x 3 x 0.8 = 1200 x 1.5 = 1800; backups 2 x 50 x 3 x 0.8 = 240; memory 1 x 100 x 3 x 0.8 = 240
    expect($quote->basePerPeriod)->toBe(1200)
        ->and($quote->addonsPerPeriod)->toBe(480)
        ->and($quote->totalPerPeriod)->toBe(2280)
        ->and($quote->lines)->toBe([
            ['label' => 'Stone · 90 days × 1.5', 'tokens' => 1800],
            ['label' => '2 × Backup', 'tokens' => 240],
            ['label' => '1 × Ram', 'tokens' => 240],
        ])
        ->and($quote->resources['backups'])->toBe(3)
        ->and($quote->resources['memory'])->toBe(3072)
        ->and($quote->resources['disk'])->toBe(10240);
});

test('fractional add-on prices round up once, on the total, and the lines still add up', function (): void {
    $quote = price(plan(100), period(45), 1.0, [[addon('backup', ShopAddonResource::Backups, 1, 7), 1]]);

    // plan 100 x 1.5 = 150; add-on 7 x 1.5 = 10.5; total ceil(160.5) = 161
    expect($quote->totalPerPeriod)->toBe(161)
        ->and(array_sum(array_column($quote->lines, 'tokens')))->toBe(161);
});

test('zero add-on units are ignored', function (): void {
    $quote = price(plan(500), period(), 1.0, [[addon('backup', ShopAddonResource::Backups, 1, 50), 0]]);

    expect($quote->totalPerPeriod)->toBe(500)->and($quote->lines)->toHaveCount(1)->and($quote->resources['backups'])->toBe(1);
});

test('a quote never costs less than one token', function (): void {
    expect(price(plan(0), period())->totalPerPeriod)->toBe(1)
        ->and(price(plan(1), period(1, -50))->totalPerPeriod)->toBe(1);
});

test('the quote serialises for the API', function (): void {
    expect(price(plan(500), period())->toArray())->toMatchArray([
        'base_per_period' => 500,
        'total_per_period' => 500,
        'period_days' => 30,
        'multiplier' => 1.0,
    ]);
});
