<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Admin\Settings\LoginProvidersControllerTest;

use Illuminate\Contracts\Encryption\Encrypter;
use Pterodactyl\Models\Setting;
use Pterodactyl\Tests\Integration\Api\Admin\AdminApiIntegrationTestCase;

uses(AdminApiIntegrationTestCase::class);

/** @return array<string, array{enabled: bool, client_id: string, client_secret: string}> */
function loginProvidersPayload(string $discordSecret = 'discord-secret'): array
{
    return [
        'discord' => ['enabled' => true, 'client_id' => '1234', 'client_secret' => $discordSecret],
        'google' => ['enabled' => false, 'client_id' => '', 'client_secret' => ''],
    ];
}

test('the settings report whether a secret is set but never the secret', function (): void {
    config()->set('app.url', 'https://panel.example.com/');
    config()->set('monoforge.auth.discord.client_secret', 'stored-discord-secret');

    $response = $this->getJson('/api/admin/settings/login-providers')->assertOk()
        ->assertJsonPath('discord.client_secret_set', true)
        ->assertJsonPath('google.client_secret_set', false)
        ->assertJsonPath('discord.callback_url', 'https://panel.example.com/auth/modules/discord/authenticate');

    expect($response->getContent())->not->toContain('stored-discord-secret');
});

test('updating stores the secret encrypted and keeps it when left blank', function (): void {
    $this->putJson('/api/admin/settings/login-providers', loginProvidersPayload())->assertNoContent();

    $stored = (string) Setting::fetch('settings::monoforge:auth:discord:client_secret');
    expect($stored)->not->toBe('discord-secret')
        ->and($this->app->make(Encrypter::class)->decrypt($stored))->toBe('discord-secret')
        ->and(Setting::fetch('settings::monoforge:auth:discord:enabled'))->toBe('true')
        ->and(Setting::fetch('settings::monoforge:auth:discord:client_id'))->toBe('1234');

    $this->putJson('/api/admin/settings/login-providers', loginProvidersPayload(''))->assertNoContent();
    expect(Setting::fetch('settings::monoforge:auth:discord:client_secret'))->toBe($stored);
});

test('a provider cannot be enabled without credentials', function (): void {
    $payload = loginProvidersPayload('');
    $payload['discord']['client_id'] = '';

    $this->putJson('/api/admin/settings/login-providers', $payload)->assertUnprocessable();
    expect(Setting::fetch('settings::monoforge:auth:discord:enabled'))->toBeNull();
});

test('non-admins cannot read or change login providers', function (): void {
    $this->actingAsNonAdmin();

    $this->getJson('/api/admin/settings/login-providers')->assertForbidden();
    $this->putJson('/api/admin/settings/login-providers', loginProvidersPayload())->assertForbidden();
});
