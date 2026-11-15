<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Unit\Services\Assistant\SecretRedactorTest;

use Pterodactyl\Services\Assistant\Tools\OutputRedactor;
use Pterodactyl\Services\Assistant\Tools\SecretRedactor;

const MASK = SecretRedactor::PLACEHOLDER;

test('values of secret-named keys are masked in common config formats', function (string $text, string $expected): void {
    expect(SecretRedactor::redact($text))->toBe($expected);
})->with([
    'env' => ["APP_NAME=Bot\nDISCORD_TOKEN=abc.def\nexport API_KEY=xyz", "APP_NAME=Bot\nDISCORD_TOKEN=".MASK."\nexport API_KEY=".MASK],
    'properties' => ["motd=Hi\nrcon.password=hunter2\nmax-players=20", "motd=Hi\nrcon.password=".MASK."\nmax-players=20"],
    'yaml' => ["database:\n  host: db\n  password: 'hunter2'\n  user: mc", "database:\n  host: db\n  password: ".MASK."\n  user: mc"],
    'json' => ['{"name": "bot", "token": "abc123", "count": 2}', '{"name": "bot", "token": "'.MASK.'", "count": 2}'],
    'php define' => ["define( 'DB_PASSWORD', 'hunter2' );", "define( 'DB_PASSWORD', '".MASK."' );"],
    'php array' => ["'secret' => 'abc',", "'secret' => '".MASK."',"],
    'empty value' => ['DB_PASSWORD=', 'DB_PASSWORD='],
    'yaml block' => ["auth:\n  enabled: true", "auth:\n  enabled: true"],
]);

test('key blocks and JWTs are masked anywhere', function (): void {
    $text = "before\n-----BEGIN RSA PRIVATE KEY-----\nMIIEow\nIBAAK\n-----END RSA PRIVATE KEY-----\nafter eyJhbGciOiJIUzI1.eyJzdWIiOiIxMjM0.SflKxwRJSMeKKF2QT4 end";

    expect(SecretRedactor::redact($text))->toBe("before\n".MASK."\nafter ".MASK.' end');
});

test('secret-looking variable names are recognised', function (string $name, bool $secret): void {
    expect(SecretRedactor::isSecretName($name))->toBe($secret);
})->with([
    ['RCON_PASSWORD', true],
    ['DISCORD_TOKEN', true],
    ['STEAM_API_KEY', true],
    ['client_secret', true],
    ['SERVER_JARFILE', false],
    ['MAX_PLAYERS', false],
]);

test('the placeholder can never be written back into a file', function (): void {
    expect(OutputRedactor::containsPlaceholder('password: '.MASK))->toBeTrue()
        ->and(OutputRedactor::containsPlaceholder('password: x'))->toBeFalse();
});
