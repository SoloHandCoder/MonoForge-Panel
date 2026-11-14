<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Admin\Settings\EmailSettingsTest;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use Pterodactyl\Extensions\Mail\ResendTransport;
use Pterodactyl\Models\Setting;
use Pterodactyl\Providers\SettingsServiceProvider;
use Pterodactyl\Tests\Integration\Api\Admin\AdminApiIntegrationTestCase;

uses(AdminApiIntegrationTestCase::class);

test('selecting Resend stores the driver, sender and an encrypted key that is never returned', function (): void {
    Setting::put('settings::mail:mailers:smtp:host', 'smtp.example.com');

    $response = $this->putJson(route('api.admin.settings.mail'), [
        'driver' => 'resend',
        'from_address' => 'noreply@example.com',
        'from_name' => 'MonoForge',
        'resend' => ['api_key' => 're_live_secret'],
    ]);

    $response->assertStatus(Response::HTTP_NO_CONTENT);
    expect(Setting::fetch('settings::mail:default'))->toBe('resend')
        ->and(Setting::fetch('settings::mail:from:address'))->toBe('noreply@example.com')
        // SMTP settings are left alone while another driver is selected.
        ->and(Setting::fetch('settings::mail:mailers:smtp:host'))->toBe('smtp.example.com');

    $stored = (string) Setting::fetch('settings::services:resend:key');
    expect($stored)->not->toBe('re_live_secret')
        ->and($this->app->make(Encrypter::class)->decrypt($stored))->toBe('re_live_secret');

    // A blank key keeps the stored one.
    $this->putJson(route('api.admin.settings.mail'), ['driver' => 'resend', 'from_address' => 'noreply@example.com', 'resend' => ['api_key' => '']])
        ->assertStatus(Response::HTTP_NO_CONTENT);
    expect(Setting::fetch('settings::services:resend:key'))->toBe($stored);

    config()->set('services.resend.key', 're_live_secret');
    $settings = $this->getJson(route('api.admin.settings'))->assertOk();
    $settings->assertJsonPath('mail.services:resend:key_set', true);

    expect($settings->getContent())->not->toContain('re_live_secret');
});

test('the SMTP driver still requires its settings', function (): void {
    $this->putJson(route('api.admin.settings.mail'), ['driver' => 'smtp', 'from_address' => 'noreply@example.com'])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

    $this->putJson(route('api.admin.settings.mail'), ['driver' => 'carrier-pigeon', 'from_address' => 'noreply@example.com'])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
});

test('stored Resend settings are loaded onto the mail config', function (): void {
    config()->set('mail.default', 'smtp');
    Setting::put('settings::mail:default', 'resend');
    Setting::put('settings::services:resend:key', $this->app->make(Encrypter::class)->encrypt('re_stored'));
    Setting::put('settings::mail:from:address', 'sender@example.com');

    (new SettingsServiceProvider($this->app))->boot(config(), $this->app->make(Encrypter::class), $this->app->make(LoggerInterface::class));

    expect(config('mail.default'))->toBe('resend')
        ->and(config('services.resend.key'))->toBe('re_stored')
        ->and(config('mail.from.address'))->toBe('sender@example.com');
});

test('the test email goes out through Resend when it is the driver', function (): void {
    config()->set('mail.default', 'resend');
    config()->set('mail.from', ['address' => 'noreply@example.com', 'name' => 'MonoForge']);
    config()->set('services.resend.key', 're_test');
    Http::fake(['api.resend.com/*' => Http::response(['id' => 're_1'])]);

    $this->postJson(route('api.admin.settings.mail.test'))->assertStatus(Response::HTTP_NO_CONTENT);

    Http::assertSent(fn (Request $request): bool => $request->url() === ResendTransport::ENDPOINT
        && $request->hasHeader('Authorization', 'Bearer re_test')
        && $request['to'] === [$this->getAdminUser()->email]);
});
