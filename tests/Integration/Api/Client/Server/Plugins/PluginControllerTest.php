<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Client\Server\Plugins\PluginControllerTest;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Events\ActivityLogged;
use Pterodactyl\Jobs\Plugins\InstallPluginJob;
use Pterodactyl\Jobs\Plugins\ScanInstalledPluginsJob;
use Pterodactyl\Models\Setting;
use Pterodactyl\Services\Plugins\DownloadPolicy;
use Pterodactyl\Services\Plugins\InstalledPlugins;
use Pterodactyl\Services\Plugins\MarketplaceHttp;
use Pterodactyl\Services\Plugins\PluginInstallProgress;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;
use Pterodactyl\Tests\Integration\TestResponse;
use Pterodactyl\Tests\Support\Fakes\FakePluginWorld;

uses(ClientApiIntegrationTestCase::class);

const JAR = 'jar-contents-v2';
const OLD_JAR = 'jar-contents-v1';

/** A Modrinth version payload whose file is $contents served from $url. */
function modrinthVersion(string $id, string $project, string $number, string $filename, string $url, string $contents, array $loaders = ['paper']): array
{
    return [
        'id' => $id,
        'project_id' => $project,
        'name' => 'Release '.$number,
        'version_number' => $number,
        'game_versions' => ['1.21.4'],
        'loaders' => $loaders,
        'version_type' => 'release',
        'date_published' => '2026-11-01T00:00:00Z',
        'files' => [[
            'hashes' => ['sha1' => sha1($contents), 'sha512' => hash('sha512', $contents)],
            'url' => $url,
            'filename' => $filename,
            'primary' => true,
            'size' => mb_strlen($contents),
        ]],
    ];
}

/** A Paper 1.21.4 server, as its version_history.json tells. */
function paperWorld(): FakePluginWorld
{
    return (new FakePluginWorld)
        ->file('version_history.json', json_encode(['currentVersion' => '1.21.4-122-abcdef (MC: 1.21.4)']))
        ->file('server.properties', 'motd=hi')
        ->directory('plugins');
}

/** Posts an install or update (queued; the test queue runs it at once) and returns its progress. */
function queued(object $test, $user, string $url, string $progressUrl, array $body): TestResponse
{
    $id = $test->actingAs($user)->postJson($url, $body)
        ->assertAccepted()
        ->assertJsonPath('object', 'plugin_install')
        ->assertJsonPath('attributes.status', 'queued')
        ->json('attributes.id');

    return $test->actingAs($user)->getJson($progressUrl.'/'.$id)->assertOk();
}

test('reading needs file.read and managing needs plugins.manage', function (): void {
    [, $server] = $this->generateTestAccount();
    $this->getJson($this->link($server, '/plugins'))->assertUnauthorized();

    [$user, $server] = $this->generateTestAccount([Permissions::ControlConsole->value]);
    paperWorld();
    $this->actingAs($user)->getJson($this->link($server, '/plugins'))->assertForbidden();
    $this->actingAs($user)->getJson($this->link($server, '/plugins/installed'))->assertForbidden();

    [$reader, $shared] = $this->generateTestAccount([Permissions::FileRead->value]);
    $this->actingAs($reader)->getJson($this->link($shared, '/plugins'))->assertOk();
    $this->actingAs($reader)->postJson($this->link($shared, '/plugins/install'), ['source' => 'modrinth', 'project' => 'abc', 'version' => 'def'])->assertForbidden();
    $this->actingAs($reader)->postJson($this->link($shared, '/plugins/installed/remove'), ['file' => 'a.jar'])->assertForbidden();
    $this->actingAs($reader)->postJson($this->link($shared, '/plugins/modpack'), ['project' => 'abc', 'version' => 'def'])->assertForbidden();
});

test('the environment is detected from the server files and can be overridden', function (): void {
    [$user, $server] = $this->generateTestAccount();
    paperWorld();

    $this->actingAs($user)->getJson($this->link($server, '/plugins'))
        ->assertOk()
        ->assertJsonPath('attributes.platform.loader', 'paper')
        ->assertJsonPath('attributes.platform.game_version', '1.21.4')
        ->assertJsonPath('attributes.platform.directory', 'plugins')
        ->assertJsonPath('attributes.sources.plugin', ['modrinth', 'hangar'])
        ->assertJsonPath('attributes.sources.modpack', ['modrinth'])
        ->assertJsonPath('attributes.curseforge_enabled', false);

    $this->actingAs($user)->getJson($this->link($server, '/plugins?loader=fabric&game_version=1.20.1'))
        ->assertOk()
        ->assertJsonPath('attributes.platform.loader', 'fabric')
        ->assertJsonPath('attributes.platform.game_version', '1.20.1')
        ->assertJsonPath('attributes.platform.directory', 'mods')
        ->assertJsonPath('attributes.detected.loader', 'paper')
        ->assertJsonPath('attributes.sources.mod', ['modrinth']);

    $this->actingAs($user)->getJson($this->link($server, '/plugins?loader=windows'))->assertUnprocessable();
    $this->actingAs($user)->getJson($this->link($server, '/plugins?game_version=latest'))->assertUnprocessable();
});

test('a fabric server is detected from its .fabric folder', function (): void {
    [$user, $server] = $this->generateTestAccount();
    (new FakePluginWorld)->directory('.fabric/server')->directory('mods');

    $this->actingAs($user)->getJson($this->link($server, '/plugins'))
        ->assertOk()
        ->assertJsonPath('attributes.platform.loader', 'fabric')
        ->assertJsonPath('attributes.platform.type', 'mod');
});

test('without telling files the loader comes from the egg', function (): void {
    // The test egg is BungeeCord, so a server without telling files is a BungeeCord proxy.
    [$owner, $other] = $this->generateTestAccount();
    new FakePluginWorld;
    $this->actingAs($owner)->getJson($this->link($other, '/plugins'))
        ->assertOk()
        ->assertJsonPath('attributes.platform.loader', 'bungeecord')
        ->assertJsonPath('attributes.detected.detected_from', ['egg']);
});

test('modrinth search filters by loader and version, sends the user agent and is cached', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $world = paperWorld();
    $world->catalog['https://api.modrinth.com/v2/search*'] = [
        'hits' => [[
            'project_id' => 'P7dR8mSH', 'slug' => 'luckperms', 'title' => 'LuckPerms', 'description' => 'Permissions', 'author' => 'Luck',
            'icon_url' => 'https://cdn.modrinth.com/icon.png', 'downloads' => 10, 'date_modified' => '2026-11-01T00:00:00Z', 'project_type' => 'mod', 'server_side' => 'required',
        ]],
        'offset' => 0, 'limit' => 20, 'total_hits' => 1,
    ];

    foreach ([1, 2] as $attempt) {
        $this->actingAs($user)->getJson($this->link($server, '/plugins/search?source=modrinth&query=perms'))
            ->assertOk()
            ->assertJsonPath('data.0.id', 'P7dR8mSH')
            ->assertJsonPath('data.0.url', 'https://modrinth.com/plugin/luckperms')
            ->assertJsonPath('meta.pagination.total', 1);
    }

    $searches = $world->requestsTo('https://api.modrinth.com/v2/search*');
    expect($searches)->toHaveCount(1);
    parse_str((string) parse_url($searches[0]['url'], PHP_URL_QUERY), $query);
    expect(json_decode((string) $query['facets'], true))->toBe([['project_type:mod'], ['categories:paper', 'categories:spigot', 'categories:bukkit'], ['versions:1.21.4']])
        ->and($query['query'])->toBe('perms');

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.modrinth.com/') && $request->header('User-Agent')[0] === MarketplaceHttp::USER_AGENT);
});

test('hangar is searched for paper servers and curseforge only with a key', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $world = paperWorld();
    $world->catalog['https://hangar.papermc.io/api/v1/projects?*'] = [
        'pagination' => ['count' => 1, 'limit' => 20, 'offset' => 0],
        'result' => [['id' => 31, 'name' => 'ViaVersion', 'namespace' => ['owner' => 'ViaVersion', 'slug' => 'ViaVersion'], 'stats' => ['downloads' => 5], 'description' => 'Versions', 'avatarUrl' => 'https://hangarcdn.papermc.io/a.webp', 'lastUpdated' => '2026-11-01T00:00:00Z']],
    ];
    $world->catalog['https://api.curseforge.com/v1/mods/search*'] = ['data' => [['id' => 123, 'name' => 'Essentials', 'slug' => 'essentials', 'summary' => 'Basics', 'downloadCount' => 9, 'logo' => ['thumbnailUrl' => 'https://media.forgecdn.net/e.png'], 'authors' => [['name' => 'ess']], 'links' => ['websiteUrl' => 'https://www.curseforge.com/minecraft/bukkit-plugins/essentials']]], 'pagination' => ['totalCount' => 1]];

    $this->actingAs($user)->getJson($this->link($server, '/plugins/search?source=hangar'))
        ->assertOk()
        ->assertJsonPath('data.0.id', 'ViaVersion')
        ->assertJsonPath('data.0.url', 'https://hangar.papermc.io/ViaVersion/ViaVersion');
    parse_str((string) parse_url($world->requestsTo('https://hangar.papermc.io/*')[0]['url'], PHP_URL_QUERY), $query);
    expect($query)->toMatchArray(['platform' => 'PAPER', 'version' => '1.21.4']);

    $this->actingAs($user)->getJson($this->link($server, '/plugins/search?source=curseforge'))
        ->assertStatus(400)
        ->assertJsonPath('errors.0.detail', 'CurseForge is not set up on this panel.');

    Setting::put('settings::monoforge:plugins:curseforge_key', Crypt::encrypt('cf-key'));
    config()->set('monoforge.plugins.curseforge_key', 'cf-key');
    $this->actingAs($user)->getJson($this->link($server, '/plugins/search?source=curseforge'))
        ->assertOk()
        ->assertJsonPath('data.0.id', '123');
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.curseforge.com/') && $request->header('x-api-key')[0] === 'cf-key' && str_contains($request->url(), 'classId=5'));

    // Hangar has nothing for mod servers.
    $this->actingAs($user)->getJson($this->link($server, '/plugins/search?source=hangar&loader=fabric'))->assertStatus(400);
});

test('a plugin is pulled under a temporary name, hash checked and renamed into plugins', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $world = paperWorld();
    $url = 'https://cdn.modrinth.com/data/P7dR8mSH/versions/v2/LuckPerms-2.jar';
    $world->remote[$url] = JAR;
    $world->catalog['https://api.modrinth.com/v2/version/v2id'] = modrinthVersion('v2id', 'P7dR8mSH', '2.0', 'LuckPerms-2.jar', $url, JAR);

    queued($this, $user, $this->link($server, '/plugins/install'), $this->link($server, '/plugins/install'), ['source' => 'modrinth', 'project' => 'P7dR8mSH', 'version' => 'v2id'])
        ->assertJsonPath('attributes.status', 'completed')
        ->assertJsonPath('attributes.file_name', 'LuckPerms-2.jar')
        ->assertJsonPath('attributes.directory', 'plugins');

    expect($world->files['plugins/LuckPerms-2.jar'] ?? null)->toBe(JAR)
        ->and(array_filter(array_keys($world->files), fn (string $path): bool => str_ends_with($path, '.part')))->toBe([]);
    $pull = $world->requestsTo('*/files/pull')[0]['data'];
    expect($pull['root'])->toBe('/plugins')->and($pull['file_name'])->toEndWith('.part')->and($pull['foreground'])->toBeTrue();

    Event::assertDispatched(ActivityLogged::class, fn (ActivityLogged $event): bool => $event->is('server:plugin.install') && $event->model->propertyValues() === ['file' => 'plugins/LuckPerms-2.jar', 'version' => '2.0', 'source' => 'Modrinth']);
});

test('a download that fails its hash check is deleted and nothing is installed', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $world = paperWorld();
    $url = 'https://cdn.modrinth.com/data/abc/evil.jar';
    $world->remote[$url] = 'tampered';
    $world->catalog['https://api.modrinth.com/v2/version/v2id'] = modrinthVersion('v2id', 'abc', '2.0', 'Good.jar', $url, JAR);

    queued($this, $user, $this->link($server, '/plugins/install'), $this->link($server, '/plugins/install'), ['source' => 'modrinth', 'project' => 'abc', 'version' => 'v2id'])
        ->assertJsonPath('attributes.status', 'failed')
        ->assertJsonPath('attributes.message', "Good.jar did not match the catalog's sha512 hash, so it was removed.");

    expect(array_filter(array_keys($world->files), fn (string $path): bool => str_starts_with($path, 'plugins/')))->toBe([]);
});

test('files on hosts outside the allowlist are never pulled', function (string $url): void {
    [$user, $server] = $this->generateTestAccount();
    $world = paperWorld();
    $world->remote[$url] = JAR;
    $world->catalog['https://api.modrinth.com/v2/version/v2id'] = modrinthVersion('v2id', 'abc', '2.0', 'Good.jar', $url, JAR);

    queued($this, $user, $this->link($server, '/plugins/install'), $this->link($server, '/plugins/install'), ['source' => 'modrinth', 'project' => 'abc', 'version' => 'v2id'])
        ->assertJsonPath('attributes.status', 'failed')
        ->assertJsonPath('attributes.message', 'This file is not hosted on an allowed download site, so it was not installed.');

    expect($world->requestsTo('*/files/pull'))->toBe([]);
})->with([
    'other host' => 'https://evil.example.com/Good.jar',
    'look-alike host' => 'https://cdn.modrinth.com.evil.example/Good.jar',
    'plain http' => 'http://cdn.modrinth.com/Good.jar',
    'credentials' => 'https://user:pass@cdn.modrinth.com/Good.jar',
    'other port' => 'https://cdn.modrinth.com:8443/Good.jar',
]);

test('a version of another project, an unsafe file name or a missing download url is refused', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $world = paperWorld();
    $world->catalog['https://api.modrinth.com/v2/version/other'] = modrinthVersion('other', 'someone-else', '1.0', 'A.jar', 'https://cdn.modrinth.com/A.jar', JAR);
    $world->catalog['https://api.modrinth.com/v2/version/sneaky'] = modrinthVersion('sneaky', 'abc', '1.0', '../../start.sh.jar', 'https://cdn.modrinth.com/A.jar', JAR);

    $this->actingAs($user)->postJson($this->link($server, '/plugins/install'), ['source' => 'modrinth', 'project' => 'abc', 'version' => 'other'])
        ->assertStatus(400)->assertJsonPath('errors.0.detail', 'That version was not found in the catalog.');
    $this->actingAs($user)->postJson($this->link($server, '/plugins/install'), ['source' => 'modrinth', 'project' => 'abc', 'version' => 'sneaky'])
        ->assertStatus(400)->assertJsonPath('errors.0.detail', 'The catalog gave this file a name the panel does not install.');
    $this->actingAs($user)->postJson($this->link($server, '/plugins/install'), ['source' => 'modrinth', 'project' => '../etc', 'version' => 'sneaky'])
        ->assertUnprocessable();

    config()->set('monoforge.plugins.curseforge_key', 'cf-key');
    $world->catalog['https://api.curseforge.com/v1/mods/55/files/77'] = ['data' => ['id' => 77, 'modId' => 55, 'displayName' => 'X 1.0', 'fileName' => 'X.jar', 'downloadUrl' => null, 'hashes' => [['value' => sha1(JAR), 'algo' => 1]], 'gameVersions' => ['1.21.4'], 'fileLength' => 5, 'releaseType' => 1]];
    $this->actingAs($user)->postJson($this->link($server, '/plugins/install'), ['source' => 'curseforge', 'project' => '55', 'version' => '77'])
        ->assertStatus(400)
        ->assertJsonPath('errors.0.detail', 'This version cannot be installed from the panel: its author does not allow downloads from other apps, or it has no jar.');

    expect($world->requestsTo('*/files/pull'))->toBe([]);
});

test('hangar and curseforge files are checked against their sha256 and sha1', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $world = paperWorld();
    $hangarUrl = 'https://hangarcdn.papermc.io/plugins/Via/ViaVersion/versions/5/PAPER/ViaVersion-5.jar';
    $world->remote[$hangarUrl] = JAR;
    $world->catalog['https://hangar.papermc.io/api/v1/projects/ViaVersion/versions/33541'] = [
        'id' => 33541, 'name' => '5.0', 'createdAt' => '2026-11-01T00:00:00Z', 'channel' => ['name' => 'Release'],
        'downloads' => ['PAPER' => ['fileInfo' => ['name' => 'ViaVersion-5.jar', 'sizeBytes' => mb_strlen(JAR), 'sha256Hash' => hash('sha256', JAR)], 'externalUrl' => null, 'downloadUrl' => $hangarUrl]],
        'platformDependencies' => ['PAPER' => ['1.21.4']],
    ];

    queued($this, $user, $this->link($server, '/plugins/install'), $this->link($server, '/plugins/install'), ['source' => 'hangar', 'project' => 'ViaVersion', 'version' => '33541'])
        ->assertJsonPath('attributes.file_name', 'ViaVersion-5.jar');

    config()->set('monoforge.plugins.curseforge_key', 'cf-key');
    $cfUrl = 'https://edge.forgecdn.net/files/1/2/Essentials.jar';
    $world->remote[$cfUrl] = 'not what was published';
    $world->catalog['https://api.curseforge.com/v1/mods/55/files/77'] = ['data' => ['id' => 77, 'modId' => 55, 'displayName' => 'Essentials', 'fileName' => 'Essentials.jar', 'downloadUrl' => $cfUrl, 'hashes' => [['value' => sha1(JAR), 'algo' => 1], ['value' => md5(JAR), 'algo' => 2]], 'gameVersions' => ['1.21.4', 'Bukkit'], 'fileLength' => 5, 'releaseType' => 1]];

    queued($this, $user, $this->link($server, '/plugins/install'), $this->link($server, '/plugins/install'), ['source' => 'curseforge', 'project' => '55', 'version' => '77'])
        ->assertJsonPath('attributes.status', 'failed')
        ->assertJsonPath('attributes.message', "Essentials.jar did not match the catalog's sha1 hash, so it was removed.");

    expect(array_keys(array_filter($world->files, fn (string $path): bool => str_starts_with($path, 'plugins/'), ARRAY_FILTER_USE_KEY)))->toBe(['plugins/ViaVersion-5.jar']);
});

test('installed jars are matched by sha1, updated and removed', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $world = paperWorld();
    $world->file('plugins/LuckPerms-1.jar', OLD_JAR)->file('plugins/Unknown.jar', 'mystery')->file('plugins/config.yml', 'x');
    $newUrl = 'https://cdn.modrinth.com/data/P7dR8mSH/versions/v2/LuckPerms-2.jar';
    $world->remote[$newUrl] = JAR;
    $current = modrinthVersion('v1id', 'P7dR8mSH', '1.0', 'LuckPerms-1.jar', 'https://cdn.modrinth.com/old.jar', OLD_JAR);
    $latest = modrinthVersion('v2id', 'P7dR8mSH', '2.0', 'LuckPerms-2.jar', $newUrl, JAR);
    $world->catalog['https://api.modrinth.com/v2/version_files/update'] = fn (Request $request): array => [sha1(OLD_JAR) => $latest];
    $world->catalog['https://api.modrinth.com/v2/version_files'] = fn (Request $request): array => in_array(sha1(OLD_JAR), $request->data()['hashes'], true) ? [sha1(OLD_JAR) => $current] : [];
    $world->catalog['https://api.modrinth.com/v2/projects*'] = [['id' => 'P7dR8mSH', 'title' => 'LuckPerms', 'slug' => 'luckperms', 'icon_url' => null, 'project_type' => 'mod']];
    $world->catalog['https://api.modrinth.com/v2/version/v2id'] = $latest;

    // The first look lists the jars and queues the check (the test queue runs it at once).
    $this->actingAs($user)->getJson($this->link($server, '/plugins/installed'))
        ->assertOk()
        ->assertJsonPath('meta.scanning', true)
        ->assertJsonPath('data.0.sha1', null);

    $this->actingAs($user)->getJson($this->link($server, '/plugins/installed'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.directory', 'plugins')
        ->assertJsonPath('meta.scanning', false)
        ->assertJsonPath('data.0.file_name', 'LuckPerms-1.jar')
        ->assertJsonPath('data.0.project.title', 'LuckPerms')
        ->assertJsonPath('data.0.version.version_number', '1.0')
        ->assertJsonPath('data.0.update.id', 'v2id')
        ->assertJsonPath('data.1.file_name', 'Unknown.jar')
        ->assertJsonPath('data.1.project', null);

    $body = $world->requestsTo('https://api.modrinth.com/v2/version_files/update')[0]['data'];
    expect($body['loaders'])->toBe(['paper', 'spigot', 'bukkit'])->and($body['game_versions'])->toBe(['1.21.4']);

    queued($this, $user, $this->link($server, '/plugins/installed/update'), $this->link($server, '/plugins/install'), ['file' => 'LuckPerms-1.jar'])
        ->assertJsonPath('attributes.status', 'completed')
        ->assertJsonPath('attributes.file_name', 'LuckPerms-2.jar');
    expect($world->files)->toHaveKey('plugins/LuckPerms-2.jar')->not->toHaveKey('plugins/LuckPerms-1.jar');
    $this->assertActivityFor('server:plugin.update', $user, $server);

    $this->actingAs($user)->getJson($this->link($server, '/plugins/installed'))->assertOk();
    $this->actingAs($user)->postJson($this->link($server, '/plugins/installed/update'), ['file' => 'Unknown.jar'])
        ->assertStatus(400)->assertJsonPath('errors.0.detail', 'There is no update for this file.');

    $this->actingAs($user)->postJson($this->link($server, '/plugins/installed/remove'), ['file' => 'Unknown.jar'])->assertNoContent();
    expect($world->files)->not->toHaveKey('plugins/Unknown.jar')->toHaveKey('plugins/config.yml');
    Event::assertDispatched(ActivityLogged::class, fn (ActivityLogged $event): bool => $event->is('server:plugin.remove') && $event->model->propertyValues() === ['file' => 'plugins/Unknown.jar']);
});

test('only jars in the plugin folder can be removed', function (string $file, int $status): void {
    [$user, $server] = $this->generateTestAccount();
    $world = paperWorld();
    $world->file('plugins/config.yml', 'x')->file('server.jar', 'core');

    $this->actingAs($user)->postJson($this->link($server, '/plugins/installed/remove'), ['file' => $file])->assertStatus($status);
    expect($world->requestsTo('*/files/delete'))->toBe([]);
})->with([
    'traversal' => ['../server.jar', 400],
    'nested' => ['sub/x.jar', 400],
    'not a jar' => ['config.yml', 400],
    'absolute' => ['/server.jar', 400],
    'not installed' => ['Missing.jar', 404],
]);

test('plugin search and install calls are rate limited per user', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $world = paperWorld();
    $world->catalog['https://api.modrinth.com/v2/version/*'] = fn (): PromiseInterface => Http::response([], 404);

    for ($i = 0; $i < 15; $i++) {
        $this->actingAs($user)->postJson($this->link($server, '/plugins/install'), ['source' => 'modrinth', 'project' => 'abc', 'version' => 'v'.$i])->assertStatus(400);
    }

    $this->actingAs($user)->postJson($this->link($server, '/plugins/install'), ['source' => 'modrinth', 'project' => 'abc', 'version' => 'last'])->assertTooManyRequests();
});

test('installed jars are hashed by a queued scan on the installs queue, again only when files change and at most once a minute', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $world = paperWorld();
    $world->file('plugins/A.jar', 'a')->file('plugins/Huge.jar', 'huge');
    $world->reportedSizes['plugins/Huge.jar'] = InstalledPlugins::MAX_HASH_BYTES + 1;
    $world->catalog['https://api.modrinth.com/v2/version_files'] = [];
    Bus::fake([ScanInstalledPluginsJob::class]);

    $this->actingAs($user)->getJson($this->link($server, '/plugins/installed'))->assertJsonPath('meta.scanning', true);
    $this->actingAs($user)->getJson($this->link($server, '/plugins/installed'))->assertJsonPath('meta.scanning', true);
    // Two looks within a minute queue one scan, and the request itself hashed nothing.
    Bus::assertDispatchedTimes(ScanInstalledPluginsJob::class, 1);
    Bus::assertDispatched(ScanInstalledPluginsJob::class, fn (ScanInstalledPluginsJob $job): bool => $job->connection === 'installs' && $job->queue === 'installs');
    expect($world->requestsTo('*/files/contents?file=plugins*'))->toBe([]);

    resolve(InstalledPlugins::class)->scan($server, 'plugins');
    $this->actingAs($user)->getJson($this->link($server, '/plugins/installed'))
        ->assertJsonPath('meta.scanning', false)
        ->assertJsonPath('data.0.file_name', 'A.jar')
        ->assertJsonPath('data.0.sha1', sha1('a'))
        ->assertJsonPath('data.1.file_name', 'Huge.jar')
        ->assertJsonPath('data.1.sha1', null)
        ->assertJsonPath('data.1.note', InstalledPlugins::TOO_LARGE_NOTE);
    // Only the small jar was streamed.
    expect($world->requestsTo('*/files/contents?file=plugins*'))->toHaveCount(1);

    // Unchanged files: nothing is queued. A changed folder queues a rescan after the minute.
    $this->actingAs($user)->getJson($this->link($server, '/plugins/installed'));
    $world->file('plugins/B.jar', 'b');
    $this->actingAs($user)->getJson($this->link($server, '/plugins/installed'))->assertJsonPath('meta.scanning', true);
    Bus::assertDispatchedTimes(ScanInstalledPluginsJob::class, 1);
    $this->travel(InstalledPlugins::RESCAN_SECONDS + 1)->seconds();
    $this->actingAs($user)->getJson($this->link($server, '/plugins/installed'))->assertJsonPath('meta.scanning', true);
    Bus::assertDispatchedTimes(ScanInstalledPluginsJob::class, 2);

    // The stored hashes are one entry per server and folder, rebuilt (so pruned) on every scan.
    unset($world->files['plugins/A.jar']);
    resolve(InstalledPlugins::class)->scan($server, 'plugins');
    expect(array_keys(Cache::get('plugins:installed:'.$server->uuid.':plugins')['files']))->toBe(['B.jar', 'Huge.jar']);
});

test('an update waits until the installed files have been checked', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $world = paperWorld();
    $world->file('plugins/A.jar', 'a');
    Bus::fake([ScanInstalledPluginsJob::class]);

    $this->actingAs($user)->postJson($this->link($server, '/plugins/installed/update'), ['file' => 'A.jar'])
        ->assertConflict()
        ->assertJsonPath('errors.0.detail', 'The installed files are still being checked. Try again in a moment.');
});

test('installs are queued on the installs queue, and an unknown install id is not found', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $world = paperWorld();
    $url = 'https://cdn.modrinth.com/data/P7dR8mSH/versions/v2/LuckPerms-2.jar';
    $world->catalog['https://api.modrinth.com/v2/version/v2id'] = modrinthVersion('v2id', 'P7dR8mSH', '2.0', 'LuckPerms-2.jar', $url, JAR);
    Bus::fake([InstallPluginJob::class]);

    $this->actingAs($user)->postJson($this->link($server, '/plugins/install'), ['source' => 'modrinth', 'project' => 'P7dR8mSH', 'version' => 'v2id'])->assertAccepted();
    Bus::assertDispatched(InstallPluginJob::class, fn (InstallPluginJob $job): bool => $job->connection === 'installs' && $job->queue === 'installs');
    expect($world->pulled)->toBe([]);

    $this->actingAs($user)->getJson($this->link($server, '/plugins/install/9b2f3c4d-5e6f-4a1b-8c9d-0e1f2a3b4c5d'))->assertNotFound();
    [$other, $otherServer] = $this->generateTestAccount();
    $id = PluginInstallProgress::start($server, 'plugins', '2.0')['id'];
    $this->actingAs($other)->getJson($this->link($otherServer, '/plugins/install/'.$id))->assertNotFound();
});

test('a single install needs a usable hash; an md5 alone only together with the size', function (array $hashes, int $size, ?string $failure): void {
    [$user, $server] = $this->generateTestAccount();
    $world = paperWorld();
    config()->set('monoforge.plugins.curseforge_key', 'cf-key');
    $cfUrl = 'https://edge.forgecdn.net/files/1/2/Essentials.jar';
    $world->remote[$cfUrl] = JAR;
    $world->catalog['https://api.curseforge.com/v1/mods/55/files/77'] = ['data' => ['id' => 77, 'modId' => 55, 'displayName' => 'Essentials', 'fileName' => 'Essentials.jar', 'downloadUrl' => $cfUrl, 'hashes' => $hashes, 'gameVersions' => ['1.21.4', 'Bukkit'], 'fileLength' => $size, 'releaseType' => 1]];

    $progress = queued($this, $user, $this->link($server, '/plugins/install'), $this->link($server, '/plugins/install'), ['source' => 'curseforge', 'project' => '55', 'version' => '77']);

    if ($failure === null) {
        $progress->assertJsonPath('attributes.status', 'completed');
        expect($world->files['plugins/Essentials.jar'] ?? null)->toBe(JAR);
    } else {
        $progress->assertJsonPath('attributes.status', 'failed')->assertJsonPath('attributes.message', $failure);
        expect($world->files)->not->toHaveKey('plugins/Essentials.jar');
    }
})->with([
    'no hash' => [[], mb_strlen(JAR), 'The catalog gave no hash for Essentials.jar, so it was not installed.'],
    'md5 and the right size' => [[['value' => md5(JAR), 'algo' => 2]], mb_strlen(JAR), null],
    'md5 and a wrong size' => [[['value' => md5(JAR), 'algo' => 2]], mb_strlen(JAR) + 1, "Essentials.jar did not match the catalog's size, so it was removed."],
    'md5 without a size' => [[['value' => md5(JAR), 'algo' => 2]], 0, 'The catalog gave no hash for Essentials.jar, so it was not installed.'],
]);

test('project icons are shown only from the catalog CDNs', function (): void {
    expect(DownloadPolicy::iconUrl('https://cdn.modrinth.com/data/AABB/icon.png'))->toBe('https://cdn.modrinth.com/data/AABB/icon.png')
        ->and(DownloadPolicy::iconUrl('https://media.forgecdn.net/avatars/1/2/logo.png'))->toBe('https://media.forgecdn.net/avatars/1/2/logo.png')
        ->and(DownloadPolicy::iconUrl('https://tracker.example.com/pixel.png'))->toBeNull()
        ->and(DownloadPolicy::iconUrl('http://cdn.modrinth.com/icon.png'))->toBeNull()
        ->and(DownloadPolicy::iconUrl('https://cdn.modrinth.com.evil.example/icon.png'))->toBeNull()
        ->and(DownloadPolicy::iconUrl(null))->toBeNull();
});

test('each user gets only a share of the catalog budget', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $world = paperWorld();
    $world->catalog['https://api.modrinth.com/v2/search*'] = ['hits' => [], 'total_hits' => 0, 'limit' => 20, 'offset' => 0];

    $statuses = [];
    for ($i = 0; $i < 45; $i++) {
        $statuses[] = $this->actingAs($user)->getJson($this->link($server, '/plugins/search?source=modrinth&query=q'.$i))->status();
    }

    // 40 catalog calls a minute for one user (the route allows 60 requests).
    expect(array_count_values($statuses))->toBe([200 => 40, 429 => 5]);
});

test('the size check counts bytes, not characters', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $world = paperWorld();
    config()->set('monoforge.plugins.curseforge_key', 'cf-key');
    $content = str_repeat("\u{00FC}n\u{00EF}c\u{00F6}d\u{00E9} ", 64);
    $cfUrl = 'https://edge.forgecdn.net/files/1/2/Essentials.jar';
    $world->remote[$cfUrl] = $content;
    $world->catalog['https://api.curseforge.com/v1/mods/55/files/77'] = ['data' => ['id' => 77, 'modId' => 55, 'displayName' => 'Essentials', 'fileName' => 'Essentials.jar', 'downloadUrl' => $cfUrl, 'hashes' => [['value' => md5($content), 'algo' => 2]], 'gameVersions' => ['1.21.4', 'Bukkit'], 'fileLength' => mb_strlen($content, '8bit'), 'releaseType' => 1]];

    queued($this, $user, $this->link($server, '/plugins/install'), $this->link($server, '/plugins/install'), ['source' => 'curseforge', 'project' => '55', 'version' => '77'])
        ->assertJsonPath('attributes.status', 'completed');
    expect(mb_strlen($content, '8bit'))->toBeGreaterThan(mb_strlen($content));
});
