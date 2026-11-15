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

test('a value written back where the next read would not mask it is refused (round trip)', function (string $original, string $from, string $to): void {
    $edited = str_replace($from, $to, SecretRedactor::redact($original));

    expect($edited)->toContain(MASK)
        ->and(SecretRedactor::restore($edited, $original))->toBeNull();
})->with([
    'URL credential with the @ moved' => ["url: jdbc:mysql://root:S3cretPw@db:3306/mc\n", MASK.'@db', MASK.'/@db'],
    'URL credential pointed at another host' => ["url: jdbc:mysql://root:S3cretPw@db:3306/mc\n", '@db:3306', '@attacker.example:3306'],
    'JSON closing quote moved' => ["{\n  \"password\": \"S3cretPw\"\n}\n", '"'.MASK.'"', '"'.MASK."\n\""],
    'PHP closing quote moved' => ["<?php return [\n  'password' => 'S3cretPw',\n];\n", "'".MASK."',", "'".MASK."\n',"],
]);

test('a value never moves to another section with the same key', function (): void {
    $original = "mysql:\n  host: db\n  password: MYSQLPW\nredis:\n  host: cache\n  password: REDISPW\n";
    $masked = SecretRedactor::redact($original);

    // The MySQL section is removed: the redis password stays the redis password.
    $withoutMysql = "redis:\n  host: cache\n  password: ".MASK."\n";
    expect(SecretRedactor::restore($withoutMysql, $original))->toBe("redis:\n  host: cache\n  password: REDISPW\n");

    // Swapping the sections is fine too: each value follows its own key path.
    $swapped = "redis:\n  host: cache\n  password: ".MASK."\nmysql:\n  host: db\n  password: ".MASK."\n";
    expect(SecretRedactor::restore($swapped, $original))->toBe("redis:\n  host: cache\n  password: REDISPW\nmysql:\n  host: db\n  password: MYSQLPW\n");

    // A mask under a section that had none, or twice under one, is refused.
    expect(SecretRedactor::restore($masked."web:\n  password: ".MASK."\n", $original))->toBeNull()
        ->and(SecretRedactor::restore("redis:\n  password: ".MASK."\n  password: ".MASK."\n", $original))->toBeNull();
});

test('a mask without a key is restored only on identical lines', function (): void {
    $original = "motd: Hello\nkey: |\n  -----BEGIN RSA PRIVATE KEY-----\n  MIIEow\n  -----END RSA PRIVATE KEY-----\n";
    $masked = SecretRedactor::redact($original);

    expect($masked)->toBe("motd: Hello\nkey: |\n  ".MASK."\n")
        // The block moved under the MOTD (public in the server list): refused.
        ->and(SecretRedactor::restore("motd: |\n  ".MASK."\n", $original))->toBeNull()
        // Left byte-identical while another line changes: restored.
        ->and(SecretRedactor::restore(str_replace('Hello', 'Hi', $masked), $original))->toBe(str_replace('Hello', 'Hi', $original));
});

test('keys are found in JSON and PHP nesting', function (): void {
    $original = '{"db": {"password": "A"}, "cache": {"password": "B"}}';
    $masked = SecretRedactor::redact($original);
    $swapped = '{"cache": {"password": "'.MASK.'"}, "db": {"password": "'.MASK.'"}}';

    expect(SecretRedactor::restore($swapped, $original))->toBe('{"cache": {"password": "B"}, "db": {"password": "A"}}')
        ->and(SecretRedactor::restore(str_replace('"db"', '"other"', $masked), $original))->toBeNull();
});

test('file keys named like a secret startup variable are masked, and log lines are masked mid-line', function (): void {
    expect(SecretRedactor::redact('STEAM_ACC=ABCDEF'))->toBe('STEAM_ACC='.MASK)
        ->and(SecretRedactor::redactLog('[12:00:01 INFO]: RCON password: hunter22'))->toBe('[12:00:01 INFO]: RCON password: '.MASK)
        ->and(SecretRedactor::redactLog('[Bot] token=MTAxabc rest'))->toBe('[Bot] token='.MASK.' rest')
        ->and(SecretRedactor::redactLog('[12:00:01 INFO]: Done (3.2s)! port: 25565'))->toBe('[12:00:01 INFO]: Done (3.2s)! port: 25565');
});
