<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Unit\Services\Plugins\DownloadPolicyTest;

use Pterodactyl\Services\Plugins\DownloadPolicy;
use Pterodactyl\Services\Plugins\ServerPlatform;

test('only https urls on the catalog cdns are allowed', function (string $url, bool $allowed): void {
    expect(DownloadPolicy::allowedUrl($url))->toBe($allowed);
})->with([
    'modrinth' => ['https://cdn.modrinth.com/data/AANobbMI/versions/x/sodium.jar', true],
    'hangar' => ['https://hangarcdn.papermc.io/plugins/a/b/versions/1/PAPER/b.jar', true],
    'forgecdn media' => ['https://mediafilez.forgecdn.net/files/1/2/a.jar', true],
    'forgecdn edge' => ['https://edge.forgecdn.net/files/1/2/a.jar', true],
    'upper-case host' => ['https://CDN.MODRINTH.COM/a.jar', true],
    'explicit 443' => ['https://cdn.modrinth.com:443/a.jar', true],
    'http' => ['http://cdn.modrinth.com/a.jar', false],
    'github' => ['https://github.com/a/b/releases/a.jar', false],
    'subdomain trick' => ['https://cdn.modrinth.com.example.org/a.jar', false],
    'prefix trick' => ['https://evilcdn.modrinth.com/a.jar', false],
    'userinfo' => ['https://cdn.modrinth.com@example.org/a.jar', false],
    'other port' => ['https://cdn.modrinth.com:8080/a.jar', false],
    'space' => ['https://cdn.modrinth.com/a b.jar', false],
    'backslash' => ['https://cdn.modrinth.com\\@example.org/a.jar', false],
    'file scheme' => ['file:///etc/passwd', false],
    'empty' => ['', false],
]);

test('modpack paths must stay inside the server root', function (string $path, ?string $expected): void {
    expect(DownloadPolicy::relativePath($path))->toBe($expected);
})->with([
    'plain' => ['mods/sodium.jar', 'mods/sodium.jar'],
    'root file' => ['server.properties', 'server.properties'],
    'nested' => ['config/a/b/c.toml', 'config/a/b/c.toml'],
    'parent' => ['../x', null],
    'inner parent' => ['mods/../../x', null],
    'dot' => ['./mods/x.jar', null],
    'absolute' => ['/etc/passwd', null],
    'home' => ['~/x', null],
    'windows drive' => ['C:/x', null],
    'backslash' => ['mods\\x.jar', null],
    'double slash' => ['mods//x.jar', null],
    'trailing slash' => ['mods/', null],
    'null byte' => ["mods/x\0.jar", null],
    'padded segment' => ['mods/ x.jar', null],
    'empty' => ['', null],
]);

test('jar names are single safe segments', function (string $name, bool $valid): void {
    expect(DownloadPolicy::jarName($name) !== null)->toBe($valid);
})->with([
    ['sodium-fabric-0.6.0+mc1.21.4.jar', true],
    ['LuckPerms-Bukkit-5.4.150.jar', true],
    ['Geyser (Spigot) [2.4].jar', true],
    ['../x.jar', false],
    ['sub/x.jar', false],
    ['.hidden.jar', false],
    ['x.zip', false],
    ['x.jar.part', false],
    ['x..jar', false],
]);

test('a server platform knows its folder and compatible loaders', function (): void {
    expect((new ServerPlatform('purpur', '1.21.4'))->compatibleLoaders())->toBe(['purpur', 'paper', 'spigot', 'bukkit'])
        ->and((new ServerPlatform('purpur', null))->directory())->toBe('plugins')
        ->and((new ServerPlatform('quilt', null))->compatibleLoaders())->toBe(['quilt', 'fabric'])
        ->and((new ServerPlatform('neoforge', null))->directory())->toBe('mods')
        ->and((new ServerPlatform('velocity', null))->hangarPlatform())->toBe('VELOCITY')
        ->and((new ServerPlatform('spigot', null))->hangarPlatform())->toBeNull()
        ->and((new ServerPlatform('fabric', null))->curseForgeLoader())->toBe(4)
        ->and((new ServerPlatform(null, null))->directory())->toBeNull()
        ->and((new ServerPlatform('paper', '1.21.4', ['files']))->withOverrides('fabric', null)->toArray())
        ->toBe(['loader' => 'fabric', 'game_version' => '1.21.4', 'type' => 'mod', 'directory' => 'mods', 'detected_from' => ['files', 'override']])
        ->and(ServerPlatform::validGameVersion('26.1'))->toBeTrue()
        ->and(ServerPlatform::validGameVersion('1.21-pre1'))->toBeTrue()
        ->and(ServerPlatform::validGameVersion('latest'))->toBeFalse();
});
