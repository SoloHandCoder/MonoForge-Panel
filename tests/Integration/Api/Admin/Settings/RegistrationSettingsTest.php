<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Admin\Settings\RegistrationSettingsTest;

use Illuminate\Contracts\Encryption\Encrypter;
use Pterodactyl\Models\Setting;
use Pterodactyl\Tests\Integration\Api\Admin\AdminApiIntegrationTestCase;

uses(AdminApiIntegrationTestCase::class);

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
