<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Providers\SettingsServiceProviderTest;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Psr\Log\LoggerInterface;
use Pterodactyl\Models\Setting;
use Pterodactyl\Providers\SettingsServiceProvider;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use RuntimeException;

uses(IntegrationTestCase::class, DatabaseTransactions::class);

/** @param array<string, string> $settings */
function bootSettings(array $settings): void
{
    foreach ($settings as $key => $value) {
        Setting::put('settings::'.$key, $value);
    }

    (new SettingsServiceProvider(app()))->boot(resolve(Repository::class), resolve(Encrypter::class), resolve(LoggerInterface::class));
}

test('stored numbers take the integer type of the configuration they replace', function (): void {
    config()->set('mail.default', 'smtp');

    bootSettings([
        'mail:mailers:smtp:port' => '587',
        'pterodactyl:auth:2fa_required' => '2',
        'pterodactyl:guzzle:timeout' => '30',
        'pterodactyl:guzzle:connect_timeout' => '10',
    ]);

    expect(config('mail.mailers.smtp.port'))->toBe(587)
        ->and(config('pterodactyl.auth.2fa_required'))->toBe(2)
        ->and(config('pterodactyl.guzzle.timeout'))->toBe(30)
        ->and(config('pterodactyl.guzzle.connect_timeout'))->toBe(10);
});

test('stored booleans take the boolean type of the configuration they replace', function (string $stored, bool $expected): void {
    bootSettings(['recaptcha:enabled' => $stored, 'pterodactyl:client_features:allocations:enabled' => $stored]);

    expect(config('recaptcha.enabled'))->toBe($expected)
        ->and(config('pterodactyl.client_features.allocations.enabled'))->toBe($expected);
})->with([['true', true], ['1', true], ['false', false], ['0', false]]);

test('stored values for keys without a typed default are kept as stored', function (): void {
    config()->set('pterodactyl.client_features.allocations.range_start');

    bootSettings(['pterodactyl:client_features:allocations:range_start' => '5000', 'app:name' => 'Test Panel']);

    expect(config('pterodactyl.client_features.allocations.range_start'))->toBe('5000')
        ->and(config('app.name'))->toBe('Test Panel');
});

test('stored null and encrypted values keep their meaning', function (): void {
    config()->set('mail.default', 'smtp');

    bootSettings([
        'mail:mailers:smtp:encryption' => 'null',
        'mail:mailers:smtp:password' => resolve(Encrypter::class)->encrypt('super-secret'),
    ]);

    expect(config('mail.mailers.smtp.encryption'))->toBeNull()
        ->and(config('mail.mailers.smtp.password'))->toBe('super-secret');
});

test('configuration without a stored setting is left unchanged', function (): void {
    bootSettings([]);

    expect(config('pterodactyl.auth.2fa_required'))->toBe(0)
        ->and(config('recaptcha.enabled'))->toBeBool();
});

test('settings are read from the cache store and saving one clears it', function (): void {
    Setting::put('settings::app:name', 'Cached Panel');
    expect(Setting::values())->toHaveKey('settings::app:name', 'Cached Panel');

    // A write that bypasses the model stays unseen until the cache entry expires...
    Setting::query()->where('key', 'settings::app:name')->update(['value' => 'Raw Write']);
    expect(Setting::cached('settings::app:name'))->toBe('Cached Panel');

    // ...while saving or deleting through the model is seen at once.
    Setting::put('settings::app:name', 'Saved Panel');
    expect(Setting::cached('settings::app:name'))->toBe('Saved Panel');

    Setting::query()->where('key', 'settings::app:name')->firstOrFail()->delete();
    expect(Setting::cached('settings::app:name'))->toBeNull();
});

test('settings are read from the database when the cache store fails', function (): void {
    Setting::put('settings::app:name', 'Uncached Panel');
    Cache::shouldReceive('get')->andThrow(new RuntimeException('cache store down'));

    expect(Setting::values())->toHaveKey('settings::app:name', 'Uncached Panel');
});
