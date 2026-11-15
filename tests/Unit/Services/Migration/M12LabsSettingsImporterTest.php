<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Unit\Services\Migration\M12LabsSettingsImporterTest;

use Illuminate\Encryption\Encrypter;
use Pterodactyl\Services\Migration\M12LabsSettingsImporter;
use RuntimeException;

function oldKey(): Encrypter
{
    return new Encrypter(str_repeat('o', 32), 'AES-256-CBC');
}

function importer(): M12LabsSettingsImporter
{
    return new M12LabsSettingsImporter(oldKey(), new Encrypter(str_repeat('n', 32), 'AES-256-CBC'));
}

test('decrypts single, double and serialized encryption and leaves plaintext alone', function (): void {
    $old = oldKey();

    expect(importer()->decrypt($old->encryptString('re_single')))->toBe('re_single')
        ->and(importer()->decrypt($old->encryptString($old->encryptString('re_double'))))->toBe('re_double')
        ->and(importer()->decrypt($old->encrypt('re_serialized')))->toBe('re_serialized')
        ->and(importer()->decrypt('plain-client-id'))->toBe('plain-client-id');
});

test('a value encrypted with another key is rejected', function (): void {
    $other = new Encrypter(str_repeat('x', 32), 'AES-256-CBC');

    expect(fn (): string => importer()->decrypt($other->encryptString('secret')))->toThrow(RuntimeException::class);
});

test('maps the old settings onto MonoForge keys', function (): void {
    $old = oldKey();
    $importer = importer();

    $settings = $importer->map([
        'settings::modules:auth:discord:client_id' => '1234',
        'settings::modules:auth:discord:client_secret' => $old->encryptString($old->encryptString('discord-secret')),
        'settings::modules:auth:google:client_id' => 'google-id',
        'settings::modules:auth:captcha:provider' => 'turnstile',
        'settings::modules:auth:captcha:site_key' => '0x4AAA',
        'settings::modules:auth:captcha:secret_key' => $old->encryptString('turnstile-secret'),
        'settings::modules:email:resend:api_key' => $old->encryptString('re_key'),
        'settings::modules:email:from_email' => 'noreply@example.com',
        'settings::modules:email:from_name' => '',
        'settings::modules:email:primary' => 'resend',
        'settings::app:name' => 'MonoForge',
        'settings::modules:billing:keys:secret' => $old->encryptString('ignored'),
    ]);

    $values = array_map(fn (array $setting): string => $setting['value'], $settings);

    expect($values)->toEqual([
        'monoforge:auth:discord:client_id' => '1234',
        'monoforge:auth:discord:client_secret' => 'discord-secret',
        'monoforge:auth:discord:enabled' => 'true',
        'monoforge:auth:google:client_id' => 'google-id',
        'monoforge:captcha:turnstile:site_key' => '0x4AAA',
        'monoforge:captcha:turnstile:secret_key' => 'turnstile-secret',
        'monoforge:captcha:provider' => 'turnstile',
        'services:resend:key' => 're_key',
        'mail:default' => 'resend',
        'mail:from:address' => 'noreply@example.com',
        'app:name' => 'MonoForge',
    ])->and($settings['services:resend:key']['from'])->toBe('settings::modules:email:resend:api_key')
        // Google has no secret, so it is not switched on.
        ->and($settings)->not->toHaveKey('monoforge:auth:google:enabled');
});

test('skips values that do not decrypt with the m12labs key', function (): void {
    $other = new Encrypter(str_repeat('x', 32), 'AES-256-CBC');
    $importer = importer();

    $settings = $importer->map(['settings::modules:email:resend:api_key' => $other->encryptString('re_key')]);

    expect($settings)->toBe([])
        ->and($importer->undecryptable())->toBe(['settings::modules:email:resend:api_key']);
});

test('keeps captcha off and SMTP as the driver when m12labs had them that way', function (): void {
    $old = oldKey();
    $importer = importer();

    $settings = $importer->map([
        'settings::modules:auth:captcha:provider' => 'disabled',
        'settings::modules:auth:captcha:site_key' => '0x4AAA',
        'settings::modules:auth:captcha:secret_key' => $old->encryptString('turnstile-secret'),
        'settings::modules:email:resend:api_key' => $old->encryptString('re_key'),
        'settings::modules:email:primary' => 'smtp',
        'settings::modules:email:backup' => 'resend',
    ]);

    expect(array_keys($settings))->toEqual(['monoforge:captcha:turnstile:site_key', 'monoforge:captcha:turnstile:secret_key', 'services:resend:key'])
        ->and(array_column($importer->decisions(), 'decision', 'setting'))->toEqual([
            'monoforge:captcha:provider' => 'left unchanged',
            'mail:default' => 'left unchanged',
        ])
        ->and(implode(' ', array_column($importer->decisions(), 'because')))->not->toContain('re_key')->not->toContain('turnstile-secret');
});

test('uses Resend without a stored primary only when mail was enabled and SMTP never configured', function (): void {
    $old = oldKey();
    $rows = [
        'settings::modules:email:resend:api_key' => $old->encryptString('re_key'),
        'settings::modules:email:enabled' => 'true',
    ];

    expect(importer()->map($rows))->toHaveKey('mail:default')
        ->and(importer()->map([...$rows, 'settings::modules:email:smtp:host' => 'smtp.example.com']))->not->toHaveKey('mail:default')
        ->and(importer()->map([...$rows, 'settings::modules:email:enabled' => 'false']))->not->toHaveKey('mail:default');
});
