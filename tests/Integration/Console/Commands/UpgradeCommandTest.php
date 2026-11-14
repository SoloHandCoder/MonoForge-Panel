<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Console\Commands\UpgradeCommandTest;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Pterodactyl\Tests\Integration\IntegrationTestCase;

uses(IntegrationTestCase::class);

test('refuses to upgrade MonoForge from Pterodactyl releases', function (): void {
    Http::fake();
    Process::fake();

    expect(Artisan::call('p:upgrade', ['--no-interaction' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('MonoForge is updated from its own repository');

    Http::assertNothingSent();
    Process::assertNothingRan();
});
