<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Console\Commands\ImportM12LabsSettingsCommandTest;

use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Database\Connection;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Setting;
use Pterodactyl\Tests\Integration\IntegrationTestCase;

uses(IntegrationTestCase::class, DatabaseTransactions::class);

const SETTINGS_SOURCE_DATABASE = 'm12labs_settings_source_h';

function settingsSourceKey(): string
{
    return str_repeat('k', 32);
}

function settingsAdminConnection(): Connection
{
    Config::set('database.connections.m12labs_settings_admin', config('database.connections.mysql'));

    return DB::connection('m12labs_settings_admin');
}

function runSettingsImport(object $test, array $options = []): mixed
{
    return $test->artisan('p:migrate:m12labs-settings', [
        '--host' => config('database.connections.mysql.host'),
        '--port' => config('database.connections.mysql.port'),
        '--database' => SETTINGS_SOURCE_DATABASE,
        '--username' => config('database.connections.mysql.username'),
        '--password' => config('database.connections.mysql.password'),
        '--source-key' => 'base64:'.base64_encode(settingsSourceKey()),
        '--no-interaction' => true,
        ...$options,
    ]);
}

beforeEach(function (): void {
    $admin = settingsAdminConnection();
    $source = SETTINGS_SOURCE_DATABASE;
    $admin->statement("DROP DATABASE IF EXISTS `{$source}`");
    $admin->statement("CREATE DATABASE `{$source}`");
    $admin->statement("CREATE TABLE `{$source}`.`settings` (`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, `key` VARCHAR(191) NOT NULL UNIQUE, `value` TEXT NOT NULL)");

    $old = new Encrypter(settingsSourceKey(), 'AES-256-CBC');
    foreach ([
        'settings::modules:auth:discord:client_id' => '1234567890',
        'settings::modules:auth:discord:client_secret' => $old->encryptString($old->encryptString('discord-secret')),
        'settings::modules:email:resend:api_key' => $old->encryptString('re_old_key'),
        'settings::modules:email:from_email' => 'noreply@monoforge.example',
        'settings::modules:email:primary' => 'resend',
    ] as $key => $value) {
        $admin->table("{$source}.settings")->insert(['key' => $key, 'value' => $value]);
    }

    Setting::query()->delete();
});

afterEach(function (): void {
    settingsAdminConnection()->statement('DROP DATABASE IF EXISTS `'.SETTINGS_SOURCE_DATABASE.'`');
});

test('a dry run lists the settings without their values and writes nothing', function (): void {
    runSettingsImport($this, ['--dry-run' => true])
        ->expectsOutputToContain('monoforge:auth:discord:client_secret')
        ->doesntExpectOutputToContain('discord-secret')
        ->doesntExpectOutputToContain('re_old_key')
        ->expectsOutputToContain('Nothing was written')
        ->assertExitCode(0);

    expect(Setting::query()->count())->toBe(0);
});

test('imports the settings and encrypts secrets with this panel key', function (): void {
    runSettingsImport($this, ['--assume-yes' => true])->assertExitCode(0);

    $encrypter = $this->app->make(EncrypterContract::class);

    expect(Setting::fetch('settings::monoforge:auth:discord:client_id'))->toBe('1234567890')
        ->and(Setting::fetch('settings::monoforge:auth:discord:enabled'))->toBe('true')
        ->and($encrypter->decrypt((string) Setting::fetch('settings::monoforge:auth:discord:client_secret')))->toBe('discord-secret')
        ->and($encrypter->decrypt((string) Setting::fetch('settings::services:resend:key')))->toBe('re_old_key')
        ->and(Setting::fetch('settings::mail:default'))->toBe('resend')
        ->and(Setting::fetch('settings::mail:from:address'))->toBe('noreply@monoforge.example');
});

test('a wrong m12labs APP_KEY aborts without writing unless told to skip', function (): void {
    $wrongKey = ['--source-key' => 'base64:'.base64_encode(str_repeat('w', 32)), '--assume-yes' => true];

    runSettingsImport($this, $wrongKey)
        ->expectsOutputToContain('Nothing was written')
        ->assertExitCode(1);
    expect(Setting::query()->count())->toBe(0);

    runSettingsImport($this, [...$wrongKey, '--skip-undecryptable' => true])->assertExitCode(0);
    expect(Setting::fetch('settings::monoforge:auth:discord:client_id'))->toBe('1234567890')
        ->and(Setting::fetch('settings::monoforge:auth:discord:client_secret'))->toBeNull()
        ->and(Setting::fetch('settings::services:resend:key'))->toBeNull();
});

test('refuses to write unattended without --assume-yes', function (): void {
    runSettingsImport($this)->assertExitCode(1);

    expect(Setting::query()->count())->toBe(0);
});
