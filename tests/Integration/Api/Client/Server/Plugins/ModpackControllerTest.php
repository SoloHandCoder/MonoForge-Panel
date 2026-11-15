<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Client\Server\Plugins\ModpackControllerTest;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Pterodactyl\Events\ActivityLogged;
use Pterodactyl\Models\Backup;
use Pterodactyl\Services\Plugins\ModpackInstaller;
use Pterodactyl\Services\Plugins\ModpackProgress;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;
use Pterodactyl\Tests\Support\Fakes\FakePluginWorld;

uses(ClientApiIntegrationTestCase::class);

const PACK_URL = 'https://cdn.modrinth.com/data/1KVo5zza/versions/yKXF4LMk/Pack-6.2.0.mrpack';

/** @param  list<array<string, mixed>>  $files */
function index(array $files): string
{
    return json_encode([
        'formatVersion' => 1,
        'game' => 'minecraft',
        'versionId' => '6.2.0',
        'name' => 'Pack',
        'files' => $files,
        'dependencies' => ['minecraft' => '1.21.4', 'fabric-loader' => '0.16.9'],
    ]);
}

/** @return array<string, mixed> */
function packFile(string $path, string $contents, string $url, string $server = 'required'): array
{
    return [
        'path' => $path,
        'hashes' => ['sha1' => sha1($contents), 'sha512' => hash('sha512', $contents)],
        'env' => ['client' => 'required', 'server' => $server],
        'downloads' => [$url],
        'fileSize' => mb_strlen($contents),
    ];
}

/** A fabric server whose Modrinth modpack is the given archive. */
function modpackWorld(string $archive): FakePluginWorld
{
    $world = (new FakePluginWorld)->directory('.fabric')->directory('mods')->file('mods/old.jar', 'old')->file('config/keep.txt', 'mine');
    $world->remote[PACK_URL] = $archive;
    $world->catalog['https://api.modrinth.com/v2/version/yKXF4LMk'] = [
        'id' => 'yKXF4LMk', 'project_id' => '1KVo5zza', 'name' => 'Pack 6.2.0', 'version_number' => '6.2.0',
        'game_versions' => ['1.21.4'], 'loaders' => ['fabric'], 'version_type' => 'release', 'date_published' => '2026-11-01T00:00:00Z',
        'files' => [['hashes' => ['sha1' => sha1($archive), 'sha512' => hash('sha512', $archive)], 'url' => PACK_URL, 'filename' => 'Pack-6.2.0.mrpack', 'primary' => true, 'size' => mb_strlen($archive)]],
    ];
    $world->catalog['https://api.modrinth.com/v2/projects*'] = [['id' => '1KVo5zza', 'title' => 'Fabulously Optimized', 'slug' => 'fabulously-optimized', 'icon_url' => null, 'project_type' => 'modpack']];

    return $world;
}

function stagedFiles(FakePluginWorld $world): array
{
    return array_values(array_filter(array_keys($world->files), fn (string $path): bool => str_starts_with($path, ModpackInstaller::STAGING)));
}

test('a modpack is downloaded, checked and installed with its overrides', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $archive = FakePluginWorld::zip([
        'modrinth.index.json' => index([
            packFile('mods/sodium.jar', 'sodium', 'https://cdn.modrinth.com/data/a/sodium.jar'),
            packFile('mods/lithium.jar', 'lithium', 'https://cdn.modrinth.com/data/b/lithium.jar'),
            packFile('mods/client-only.jar', 'menu', 'https://cdn.modrinth.com/data/c/menu.jar', server: 'unsupported'),
        ]),
        'overrides/config/sodium.json' => '{"a":1}',
        'overrides/config/keep.txt' => 'from pack',
        'server-overrides/config/keep.txt' => 'server pack',
        'server-overrides/server.properties' => 'motd=pack',
        'client-overrides/options.txt' => 'client',
    ]);
    $world = modpackWorld($archive);
    $world->remote['https://cdn.modrinth.com/data/a/sodium.jar'] = 'sodium';
    $world->remote['https://cdn.modrinth.com/data/b/lithium.jar'] = 'lithium';

    $this->actingAs($user)->postJson($this->link($server, '/plugins/modpack'), ['project' => '1KVo5zza', 'version' => 'yKXF4LMk'])
        ->assertAccepted()
        ->assertJsonPath('attributes.project_title', 'Fabulously Optimized');

    $this->actingAs($user)->getJson($this->link($server, '/plugins/modpack'))
        ->assertOk()
        ->assertJsonPath('attributes.status', 'completed')
        ->assertJsonPath('attributes.step', 'done')
        ->assertJsonPath('attributes.files_done', 2)
        ->assertJsonPath('attributes.files_total', 2)
        ->assertJsonPath('attributes.backup', 'skipped');

    expect($world->files['mods/sodium.jar'])->toBe('sodium')
        ->and($world->files['mods/lithium.jar'])->toBe('lithium')
        ->and($world->files['config/sodium.json'])->toBe('{"a":1}')
        ->and($world->files['config/keep.txt'])->toBe('server pack')
        ->and($world->files['server.properties'])->toBe('motd=pack')
        ->and($world->files['mods/old.jar'])->toBe('old')
        ->and($world->files)->not->toHaveKeys(['mods/client-only.jar', 'options.txt'])
        ->and(stagedFiles($world))->toBe([])
        ->and($world->pulled)->not->toContain('https://cdn.modrinth.com/data/c/menu.jar');

    Event::assertDispatched(ActivityLogged::class, fn (ActivityLogged $event): bool => $event->is('server:modpack.install') && $event->model->propertyValues() === ['name' => 'Fabulously Optimized', 'version' => '6.2.0']);
    Event::assertDispatched(ActivityLogged::class, fn (ActivityLogged $event): bool => $event->is('server:modpack.installed'));
});

test('a modpack that writes outside the server or uses another host changes nothing', function (array $file, string $message): void {
    [$user, $server] = $this->generateTestAccount();
    $archive = FakePluginWorld::zip(['modrinth.index.json' => index([packFile('mods/fine.jar', 'fine', 'https://cdn.modrinth.com/data/a/fine.jar'), $file]), 'overrides/server.properties' => 'motd=pack']);
    $world = modpackWorld($archive);
    $world->remote['https://cdn.modrinth.com/data/a/fine.jar'] = 'fine';
    $before = $world->files;

    $this->actingAs($user)->postJson($this->link($server, '/plugins/modpack'), ['project' => '1KVo5zza', 'version' => 'yKXF4LMk'])->assertAccepted();

    $this->actingAs($user)->getJson($this->link($server, '/plugins/modpack'))
        ->assertJsonPath('attributes.status', 'failed')
        ->assertJsonPath('attributes.message', $message);

    expect($world->files)->toBe($before)
        ->and($world->pulled)->toBe([PACK_URL]);
    Event::assertDispatched(ActivityLogged::class, fn (ActivityLogged $event): bool => $event->is('server:modpack.failed'));
})->with([
    'parent folder' => [packFile('../../etc/passwd', 'x', 'https://cdn.modrinth.com/x'), 'The modpack wants to write outside the server folder (../../etc/passwd), so it was not installed.'],
    'absolute path' => [packFile('/etc/cron.d/x', 'x', 'https://cdn.modrinth.com/x'), 'The modpack wants to write outside the server folder (/etc/cron.d/x), so it was not installed.'],
    'windows drive' => [packFile('C:/Windows/x.dll', 'x', 'https://cdn.modrinth.com/x'), 'The modpack wants to write outside the server folder (C:/Windows/x.dll), so it was not installed.'],
    'backslashes' => [packFile('mods\\..\\..\\x.jar', 'x', 'https://cdn.modrinth.com/x'), 'The modpack wants to write outside the server folder (mods\\..\\..\\x.jar), so it was not installed.'],
    'staging folder' => [packFile('.monoforge-modpack/x.jar', 'x', 'https://cdn.modrinth.com/x'), 'The modpack wants to write outside the server folder (.monoforge-modpack/x.jar), so it was not installed.'],
    'other host' => [packFile('mods/evil.jar', 'x', 'https://github.com/evil/evil.jar'), 'mods/evil.jar is not hosted on an allowed download site, so the modpack was not installed.'],
    'no hash' => [[...packFile('mods/nohash.jar', 'x', 'https://cdn.modrinth.com/x'), 'hashes' => []], 'The modpack gives no hash for mods/nohash.jar, so it was not installed.'],
]);

test('a modpack file that fails its hash check stops the install before anything moves', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $archive = FakePluginWorld::zip(['modrinth.index.json' => index([packFile('mods/a.jar', 'expected', 'https://cdn.modrinth.com/a.jar')])]);
    $world = modpackWorld($archive);
    $world->remote['https://cdn.modrinth.com/a.jar'] = 'tampered';
    $before = $world->files;

    $this->actingAs($user)->postJson($this->link($server, '/plugins/modpack'), ['project' => '1KVo5zza', 'version' => 'yKXF4LMk'])->assertAccepted();

    $this->actingAs($user)->getJson($this->link($server, '/plugins/modpack'))
        ->assertJsonPath('attributes.status', 'failed')
        ->assertJsonPath('attributes.message', "mods/a.jar did not match the catalog's sha512 hash, so it was removed.");
    expect($world->files)->toBe($before);
});

test('a tampered .mrpack is refused', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $world = modpackWorld(FakePluginWorld::zip(['modrinth.index.json' => index([])]));
    $world->remote[PACK_URL] = 'something else';
    $before = $world->files;

    $this->actingAs($user)->postJson($this->link($server, '/plugins/modpack'), ['project' => '1KVo5zza', 'version' => 'yKXF4LMk'])->assertAccepted();

    $this->actingAs($user)->getJson($this->link($server, '/plugins/modpack'))
        ->assertJsonPath('attributes.status', 'failed')
        ->assertJsonPath('attributes.message', "Pack-6.2.0.mrpack did not match the catalog's sha512 hash, so it was removed.");
    expect($world->files)->toBe($before);
});

test('the server is backed up first when it has a free backup slot', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $server->update(['backup_limit' => 2]);
    $world = modpackWorld(FakePluginWorld::zip(['modrinth.index.json' => index([]), 'overrides/a.txt' => 'a']));

    Sleep::fake();
    Sleep::whenFakingSleep(fn () => Backup::query()->where('server_id', $server->id)->update(['completed_at' => now(), 'is_successful' => true]));

    $this->actingAs($user)->postJson($this->link($server, '/plugins/modpack'), ['project' => '1KVo5zza', 'version' => 'yKXF4LMk'])->assertAccepted();

    $this->actingAs($user)->getJson($this->link($server, '/plugins/modpack'))
        ->assertJsonPath('attributes.status', 'completed')
        ->assertJsonPath('attributes.backup', 'created');
    expect(Backup::query()->where('server_id', $server->id)->count())->toBe(1)
        ->and($world->files['a.txt'])->toBe('a')
        ->and($world->requestsTo('*/backup'))->toHaveCount(1);
});

test('a failed backup stops the install', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $server->update(['backup_limit' => 1]);
    $world = modpackWorld(FakePluginWorld::zip(['modrinth.index.json' => index([])]));

    Sleep::fake();
    Sleep::whenFakingSleep(fn () => Backup::query()->where('server_id', $server->id)->update(['completed_at' => now(), 'is_successful' => false]));

    $this->actingAs($user)->postJson($this->link($server, '/plugins/modpack'), ['project' => '1KVo5zza', 'version' => 'yKXF4LMk'])->assertAccepted();

    $this->actingAs($user)->getJson($this->link($server, '/plugins/modpack'))
        ->assertJsonPath('attributes.status', 'failed')
        ->assertJsonPath('attributes.message', 'The backup before the install failed, so the modpack was not installed.');
    expect($world->pulled)->toBe([]);
});

test('only one modpack install runs per server and unknown versions are refused', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $world = modpackWorld(FakePluginWorld::zip(['modrinth.index.json' => index([])]));
    $world->catalog['https://api.modrinth.com/v2/version/missing'] = fn (): PromiseInterface => Http::response([], 404);

    $this->actingAs($user)->postJson($this->link($server, '/plugins/modpack'), ['project' => '1KVo5zza', 'version' => 'missing'])
        ->assertStatus(400)
        ->assertJsonPath('errors.0.detail', 'That modpack version was not found on Modrinth.');

    expect(ModpackProgress::claim($server))->toBeTrue();
    $this->actingAs($user)->postJson($this->link($server, '/plugins/modpack'), ['project' => '1KVo5zza', 'version' => 'yKXF4LMk'])->assertConflict();
    ModpackProgress::release($server);

    $this->actingAs($user)->getJson($this->link($server, '/plugins/modpack'))->assertOk()->assertJsonPath('attributes.status', 'idle');
});
