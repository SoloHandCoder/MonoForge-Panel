<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Unit\Services\Assistant\ServerPathsTest;

use Pterodactyl\Services\Assistant\Tools\ServerPaths;

test('paths are normalised inside the server root', function (string $path, string $expected): void {
    expect(ServerPaths::normalize($path))->toBe($expected);
})->with([
    'root' => ['/', '/'],
    'empty' => ['', '/'],
    'relative' => ['config/server.properties', '/config/server.properties'],
    'dot segments' => ['/a/./b/../c', '/a/c'],
    'climbing above the root' => ['../../etc/passwd', '/etc/passwd'],
    'duplicate and trailing slashes' => ['//plugins//x.jar/', '/plugins/x.jar'],
    'backslashes' => ['config\\a.yml', '/config/a.yml'],
]);

test('basename and dirname follow the normalised path', function (): void {
    expect(ServerPaths::basename('/config/../.env/'))->toBe('.env')
        ->and(ServerPaths::dirname('/config/../.env'))->toBe('/')
        ->and(ServerPaths::dirname('/a/b/c.txt'))->toBe('/a/b')
        ->and(ServerPaths::basename('/'))->toBe('')
        ->and(ServerPaths::join('/plugins', 'x.jar'))->toBe('/plugins/x.jar')
        ->and(ServerPaths::join('/plugins', '/x.jar'))->toBe('/x.jar');
});

test('files that may hold secrets are sensitive', function (string $path): void {
    expect(ServerPaths::isSensitive($path))->toBeTrue();
})->with([
    '/.env',
    '/app/.ENV',
    '/bot/production.env',
    '/.env.local',
    '/certs/server.pem',
    '/certs/private.KEY',
    '/home/.ssh/id_rsa',
    '/home/.ssh/id_rsa.pub',
    '/keystore.p12',
    '/cert.pfx',
    '/config/client_secret.json',
    '/db-password.txt',
    '/discord_token',
    '/plugins/LuckPerms/Credentials.yml',
    'nested/../.env',
    '/.env/',
    '/home/.ssh/id_ed25519',
    '/home/.ssh/id_ecdsa',
    '/putty.ppk',
    '/.htpasswd',
    '/.netrc',
    '/.npmrc',
    '/.pgpass',
    '/web/wp-config.php',
    '/composer/auth.json',
    '/aws/credentials',
    '/app.keystore',
    '/release.jks',
    '/server.properties',
    '/ops.json',
    '/whitelist.json',
    '/plugins/LuckPerms/luckperms-h2.db',
    '/data.sqlite',
    '/bot/api_key.txt',
    '/my-passwd.txt',
    '/APIKEY',
]);

test('ordinary files are not sensitive', function (string $path): void {
    expect(ServerPaths::isSensitive($path))->toBeFalse();
})->with([
    '/config/paper-global.yml',
    '/environment.txt',
    '/keys/readme.md',
    '/.env-files/notes.txt',
    '/',
    '/logs/latest.log',
]);

test('wildcard matching is case-insensitive and anchored', function (): void {
    expect(ServerPaths::matches('*.yml', 'Config.YML'))->toBeTrue()
        ->and(ServerPaths::matches('*.yml', 'config.yml.bak'))->toBeFalse()
        ->and(ServerPaths::matches('level?.dat', 'level1.dat'))->toBeTrue()
        ->and(ServerPaths::matches('*[a]*', 'x[a]y'))->toBeTrue();
});
