<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Unit\Services\Billing\CapacityServiceTest;

use Pterodactyl\Services\Billing\CapacityService;

const NO_LIMITS = ['memory' => 0, 'disk' => 0, 'cpu' => 0];

test('zero limits sell without limit', function (): void {
    expect(CapacityService::fits(NO_LIMITS, ['memory' => 999999, 'disk' => 999999, 'cpu' => 99999], ['memory' => 4096, 'disk' => 50000, 'cpu' => 400]))
        ->toBeTrue();
});

test('a server fits until it would pass a limit', function (array $resources, bool $fits): void {
    $limits = ['memory' => 20480, 'disk' => 512000, 'cpu' => 1500];
    $used = ['memory' => 16384, 'disk' => 400000, 'cpu' => 1200];

    expect(CapacityService::fits($limits, $used, $resources))->toBe($fits);
})->with([
    'well within' => [['memory' => 2048, 'disk' => 10240, 'cpu' => 100], true],
    'exactly at every limit' => [['memory' => 4096, 'disk' => 112000, 'cpu' => 300], true],
    'one MB of memory too many' => [['memory' => 4097, 'disk' => 10240, 'cpu' => 100], false],
    'too much disk' => [['memory' => 1024, 'disk' => 112001, 'cpu' => 100], false],
    'too much cpu' => [['memory' => 1024, 'disk' => 1024, 'cpu' => 301], false],
]);

test('only limited resources are checked and other keys are ignored', function (): void {
    $limits = ['memory' => 0, 'disk' => 0, 'cpu' => 200];

    expect(CapacityService::fits($limits, ['memory' => 0, 'disk' => 0, 'cpu' => 100], ['memory' => 99999, 'backups' => 50, 'cpu' => 100]))->toBeTrue()
        ->and(CapacityService::fits($limits, ['memory' => 0, 'disk' => 0, 'cpu' => 100], ['cpu' => 101]))->toBeFalse();
});

test('missing or negative resources count as nothing', function (): void {
    $limits = ['memory' => 1024, 'disk' => 1024, 'cpu' => 100];
    $full = ['memory' => 1024, 'disk' => 1024, 'cpu' => 100];

    expect(CapacityService::fits($limits, $full, []))->toBeTrue()
        ->and(CapacityService::fits($limits, $full, ['memory' => -50]))->toBeTrue();
});
