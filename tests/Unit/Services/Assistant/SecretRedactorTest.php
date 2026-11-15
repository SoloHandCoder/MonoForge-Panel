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
    'camelCase' => ['rconPassword: x', 'rconPassword: '.MASK],
    'empty value' => ['DB_PASSWORD=', 'DB_PASSWORD='],
    'yaml block' => ["auth:\n  enabled: true", "auth:\n  enabled: true"],
    'block scalar marker' => ["token: |\n  abc", "token: |\n  abc"],
]);

test('keys are judged by whole segments, not substrings', function (string $text): void {
    expect(SecretRedactor::redact($text))->toBe($text);
})->with([
    'rcon.port=25575',
    'enable-rcon=true',
    'author: Steve',
    'authors: [Steve]',
    'api-version: 1.20',
    'bypass-permission: x.y',
    'hotkey: F5',
    'passive-mobs: true',
    'oauth-redirect: /cb',
    'keep-alive: 30',
]);

test('credentials in URLs, webhook tokens, key blocks and JWTs are masked anywhere', function (): void {
    $text = "db: mysql://root:hunter2@db/mc\nhook: https://discord.com/api/webhooks/123456/aBc-DeF_ghi\n-----BEGIN RSA PRIVATE KEY-----\nMIIEow\n-----END RSA PRIVATE KEY-----\njwt eyJhbGciOiJIUzI1.eyJzdWIiOiIxMjM0.SflKxwRJSMeKKF2QT4 end";

    expect(SecretRedactor::redact($text))->toBe('db: mysql://root:'.MASK."@db/mc\nhook: https://discord.com/api/webhooks/123456/".MASK."\n".MASK."\njwt ".MASK.' end')
        // A key block cut off before its END line is masked to the end.
        ->and(SecretRedactor::redact("a\n-----BEGIN OPENSSH PRIVATE KEY-----\nb3Blbn"))->toBe("a\n".MASK);
});

test('secret-looking variable names are recognised', function (string $name, bool $secret): void {
    expect(SecretRedactor::isSecretName($name))->toBe($secret);
})->with([
    ['RCON_PASSWORD', true],
    ['DISCORD_TOKEN', true],
    ['STEAM_API_KEY', true],
    ['client_secret', true],
    ['STEAM_ACC', true],
    ['DB_PW', true],
    ['ADMIN_PASS', true],
    ['SERVER_JARFILE', false],
    ['MAX_PLAYERS', false],
    ['ENABLE_RCON', false],
    ['RCON_PORT', false],
]);

test('masked values are restored from the original when the model writes the file back', function (): void {
    $original = "motd=Old\nrcon.password=hunter2\ndb: mysql://root:pw1@db/mc\nmax-players=20";
    $edited = str_replace(['motd=Old', 'max-players=20'], ['motd=New', 'max-players=50'], SecretRedactor::redact($original));

    expect($edited)->toContain(MASK)
        ->and(SecretRedactor::restore($edited, $original))->toBe("motd=New\nrcon.password=hunter2\ndb: mysql://root:pw1@db/mc\nmax-players=50");
});

test('repeated keys are restored in order, and a mask without an original is refused', function (): void {
    $original = "a:\n  password: one\nb:\n  password: two";
    $masked = SecretRedactor::redact($original);

    expect(SecretRedactor::restore($masked, $original))->toBe($original)
        // A mask moved to a key that had none, or one mask too many, cannot be restored.
        ->and(SecretRedactor::restore('token: '.MASK, $original))->toBeNull()
        ->and(SecretRedactor::restore($masked."\nc:\n  password: ".MASK, $original))->toBeNull()
        ->and(SecretRedactor::restore('plain', $original))->toBe('plain');
});

test('address placeholders are still never written back', function (): void {
    expect(OutputRedactor::containsPlaceholder('ip='.OutputRedactor::IP))->toBeTrue()
        ->and(OutputRedactor::containsPlaceholder('password: '.MASK))->toBeFalse();
});
