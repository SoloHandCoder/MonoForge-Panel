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

// .env.example turns environment-only mode on; these tests load every setting unless they say otherwise.
beforeEach(function (): void {
    config()->set('pterodactyl.load_environment_only', false);
});

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

test('in environment-only mode MonoForge settings still load from the database, upstream ones do not', function (): void {
    config()->set('pterodactyl.load_environment_only', true);
    config()->set('app.name', 'From env');
    config()->set('mail.default', 'smtp');
    config()->set('mail.mailers.smtp.host', 'env.mail.test');
    config()->set('monoforge.shop.enabled', false);
    config()->set('monoforge.ai.enabled', false);
    config()->set('monoforge.auth.registration.enabled', false);

    bootSettings([
        'app:name' => 'From database',
        'mail:default' => 'log',
        'mail:mailers:smtp:host' => 'db.mail.test',
        'monoforge:shop:enabled' => 'true',
        'monoforge:ai:enabled' => 'true',
        'monoforge:auth:registration:enabled' => 'true',
        'monoforge:privacy:hidden_ips' => '203.0.113.9',
    ]);

    // The panel is the only place MonoForge's own settings can be set, so they always apply.
    expect(config('monoforge.shop.enabled'))->toBeTrue()
        ->and(config('monoforge.ai.enabled'))->toBeTrue()
        ->and(config('monoforge.auth.registration.enabled'))->toBeTrue()
        ->and(config('monoforge.privacy.hidden_ips'))->toBe('203.0.113.9')
        // Upstream settings keep coming from the environment.
        ->and(config('app.name'))->toBe('From env')
        ->and(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('env.mail.test');
});

test('every MonoForge setting key is loaded in environment-only mode', function (): void {
    $provider = new class(app()) extends SettingsServiceProvider
    {
        /** @return list<string> */
        public function loaded(bool $environmentOnly): array
        {
            return $this->keysToLoad($environmentOnly);
        }
    };

    $all = $provider->loaded(false);
    $monoforge = array_values(array_filter($all, fn (string $key): bool => str_starts_with($key, 'monoforge:')));

    expect($provider->loaded(true))->toBe($monoforge)
        ->and($monoforge)->toContain('monoforge:shop:enabled', 'monoforge:ai:enabled', 'monoforge:auth:discord:client_secret', 'monoforge:captcha:provider', 'monoforge:privacy:hidden_ips');
});

test('in environment-only mode a MonoForge setting whose variable is set in the environment wins over a stale stored value', function (): void {
    config()->set('pterodactyl.load_environment_only', true);
    config()->set('monoforge.settings.set_in_env', ['monoforge:update:mode', 'monoforge:auth:discord:enabled']);
    config()->set('monoforge.update.mode', 'notify');
    config()->set('monoforge.auth.discord.enabled', false);
    config()->set('monoforge.auth.google.enabled', false);

    bootSettings([
        'monoforge:update:mode' => 'auto',
        'monoforge:auth:discord:enabled' => 'true',
        'monoforge:auth:google:enabled' => 'true',
    ]);

    expect(config('monoforge.update.mode'))->toBe('notify')
        ->and(config('monoforge.auth.discord.enabled'))->toBeFalse()
        // Its variable is unset, so the panel's value applies.
        ->and(config('monoforge.auth.google.enabled'))->toBeTrue();
});

test('outside environment-only mode stored values still win over the environment', function (): void {
    config()->set('monoforge.settings.set_in_env', ['monoforge:update:mode']);
    config()->set('monoforge.update.mode', 'notify');

    bootSettings(['monoforge:update:mode' => 'auto']);

    expect(config('monoforge.update.mode'))->toBe('auto');
});

test('the configuration records which MonoForge variables are set in the environment', function (): void {
    putenv('MONOFORGE_UPDATE_MODE=off');
    putenv('DISCORD_CLIENT_ID=');

    try {
        $config = require config_path('monoforge.php');
    } finally {
        putenv('MONOFORGE_UPDATE_MODE');
        putenv('DISCORD_CLIENT_ID');
    }

    expect($config['settings']['set_in_env'])->toContain('monoforge:update:mode')
        ->not->toContain('monoforge:auth:google:enabled')
        // An empty variable counts as unset.
        ->not->toContain('monoforge:auth:discord:client_id')
        ->and($config['settings']['environment_variables']['monoforge:auth:discord:client_secret'])->toBe('DISCORD_CLIENT_SECRET');
});
