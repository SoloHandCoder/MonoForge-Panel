<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Admin\Settings\RegistrationSettingsTest;

use Illuminate\Contracts\Encryption\Encrypter;
use Pterodactyl\Models\Setting;
use Pterodactyl\Tests\Integration\Api\Admin\AdminApiIntegrationTestCase;

uses(AdminApiIntegrationTestCase::class);

// .env.example turns environment-only mode on; these tests use the settings UI unless they say otherwise.
beforeEach(function (): void {
    config()->set('pterodactyl.load_environment_only', false);
});

test('registration settings never return the turnstile secret', function (): void {
    config()->set('monoforge.auth.registration.enabled', true);
    config()->set('monoforge.captcha.provider', 'turnstile');
    config()->set('monoforge.captcha.turnstile.site_key', 'site-key');
    config()->set('monoforge.captcha.turnstile.secret_key', 'stored-turnstile-secret');
    config()->set('pterodactyl.load_environment_only', false);

    $response = $this->getJson(route('api.admin.settings.registration'))->assertOk()->assertExactJson([
        'registration_enabled' => true,
        'captcha_provider' => 'turnstile',
        'turnstile_site_key' => 'site-key',
        'turnstile_secret_key_set' => true,
        'load_environment_only' => false,
        'set_in_env' => ['registration_enabled' => false, 'captcha_provider' => false, 'turnstile_site_key' => false, 'turnstile_secret_key' => false],
    ]);

    expect($response->getContent())->not->toContain('stored-turnstile-secret');
});

test('the provider falls back to the legacy recaptcha switch while unset', function (): void {
    config()->set('monoforge.captcha.provider');
    config()->set('recaptcha.enabled', true);

    $this->getJson(route('api.admin.settings.registration'))->assertOk()->assertJsonPath('captcha_provider', 'recaptcha');
});

test('updating stores sign-up and turnstile settings with the secret encrypted', function (): void {
    $this->putJson(route('api.admin.settings.registration.update'), [
        'registration_enabled' => true,
        'captcha_provider' => 'turnstile',
        'turnstile_site_key' => 'site-key',
        'turnstile_secret_key' => 'new-secret',
    ])->assertNoContent();

    $secret = Setting::fetch('settings::monoforge:captcha:turnstile:secret_key');
    expect(Setting::fetch('settings::monoforge:auth:registration:enabled'))->toBe('true')
        ->and(Setting::fetch('settings::monoforge:captcha:provider'))->toBe('turnstile')
        ->and(Setting::fetch('settings::recaptcha:enabled'))->toBe('false')
        ->and(Setting::fetch('settings::monoforge:captcha:turnstile:site_key'))->toBe('site-key')
        ->and($secret)->not->toBe('new-secret')
        ->and(resolve(Encrypter::class)->decrypt((string) $secret))->toBe('new-secret');
});

test('turnstile cannot be chosen without its keys', function (): void {
    config()->set('monoforge.captcha.turnstile.secret_key', '');

    $this->putJson(route('api.admin.settings.registration.update'), [
        'registration_enabled' => false,
        'captcha_provider' => 'turnstile',
        'turnstile_site_key' => 'site-key',
    ])->assertUnprocessable();

    $this->putJson(route('api.admin.settings.registration.update'), [
        'registration_enabled' => false,
        'captcha_provider' => 'turnstile',
        'turnstile_secret_key' => 'secret',
    ])->assertUnprocessable();

    expect(Setting::fetch('settings::monoforge:captcha:provider'))->toBeNull();
});

test('non admins cannot read or change registration settings', function (string $method, string $route): void {
    $this->actingAsNonAdmin();

    $this->assertAccessDeniedJson($this->{$method}(route($route)));
})->with([['getJson', 'api.admin.settings.registration'], ['putJson', 'api.admin.settings.registration.update']]);

test('in environment-only mode only the fields whose variable is unset are saved', function (): void {
    config()->set('pterodactyl.load_environment_only', true);
    config()->set('monoforge.settings.set_in_env', ['monoforge:captcha:provider']);
    config()->set('monoforge.captcha.provider', 'none');

    $this->getJson(route('api.admin.settings.registration'))->assertOk()
        ->assertJsonPath('set_in_env', ['registration_enabled' => false, 'captcha_provider' => true, 'turnstile_site_key' => false, 'turnstile_secret_key' => false]);

    $this->putJson(route('api.admin.settings.registration.update'), [
        'registration_enabled' => true,
        'captcha_provider' => 'turnstile',
        'turnstile_site_key' => 'site',
        'turnstile_secret_key' => 'secret',
    ])->assertNoContent();

    expect(Setting::fetch('settings::monoforge:auth:registration:enabled'))->toBe('true')
        ->and(Setting::fetch('settings::monoforge:captcha:turnstile:site_key'))->toBe('site')
        // Set in .env: left alone, as is the upstream reCAPTCHA switch.
        ->and(Setting::fetch('settings::monoforge:captcha:provider'))->toBeNull()
        ->and(Setting::fetch('settings::recaptcha:enabled'))->toBeNull();
});
