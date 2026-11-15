<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Admin\Settings\PluginSettingsTest;

use Illuminate\Contracts\Encryption\Encrypter;
use Psr\Log\LoggerInterface;
use Pterodactyl\Models\Setting;
use Pterodactyl\Models\User;
use Pterodactyl\Providers\SettingsServiceProvider;
use Pterodactyl\Tests\Integration\Api\Admin\AdminApiIntegrationTestCase;

uses(AdminApiIntegrationTestCase::class);

test('the curseforge key is stored encrypted and only reported as set', function (): void {
    $this->getJson('/api/admin/settings/plugins')->assertOk()->assertExactJson(['curseforge_key_set' => false, 'set_in_env' => false]);

    $this->putJson('/api/admin/settings/plugins', ['curseforge_key' => ' cf-secret '])->assertNoContent();

    $stored = (string) Setting::fetch('settings::monoforge:plugins:curseforge_key');
    expect($stored)->not->toContain('cf-secret')
        ->and($this->app->make(Encrypter::class)->decrypt($stored))->toBe('cf-secret');

    $response = $this->getJson('/api/admin/settings/plugins')->assertOk()->assertJsonPath('curseforge_key_set', true);
    expect($response->getContent())->not->toContain('cf-secret');

    // A blank key keeps the stored one; clearing removes it.
    $this->putJson('/api/admin/settings/plugins', ['curseforge_key' => ''])->assertNoContent();
    expect((string) Setting::fetch('settings::monoforge:plugins:curseforge_key'))->toBe($stored);

    $this->putJson('/api/admin/settings/plugins', ['clear_curseforge_key' => true])->assertNoContent();
    $this->getJson('/api/admin/settings/plugins')->assertJsonPath('curseforge_key_set', false);
});

test('a key set in the environment cannot be changed', function (): void {
    config()->set('pterodactyl.load_environment_only', true);
    config()->set('monoforge.settings.set_in_env', ['monoforge:plugins:curseforge_key']);
    config()->set('monoforge.plugins.curseforge_key', 'from-env');

    $this->getJson('/api/admin/settings/plugins')->assertExactJson(['curseforge_key_set' => true, 'set_in_env' => true]);
    $this->putJson('/api/admin/settings/plugins', ['curseforge_key' => 'other'])->assertNoContent();
    expect(Setting::fetch('settings::monoforge:plugins:curseforge_key'))->toBeNull();
});

test('a stored key is decrypted into the configuration', function (): void {
    Setting::put('settings::monoforge:plugins:curseforge_key', $this->app->make(Encrypter::class)->encrypt('db-key'));
    (new SettingsServiceProvider($this->app))->boot($this->app->make('config'), $this->app->make(Encrypter::class), $this->app->make(LoggerInterface::class));

    expect(config('monoforge.plugins.curseforge_key'))->toBe('db-key');
});

test('regular admins without settings access cannot read or change it', function (): void {
    $this->actingAs(User::factory()->create(['root_admin' => false]));

    $this->getJson('/api/admin/settings/plugins')->assertForbidden();
    $this->putJson('/api/admin/settings/plugins', ['curseforge_key' => 'x'])->assertForbidden();
});
