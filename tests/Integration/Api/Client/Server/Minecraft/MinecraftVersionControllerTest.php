<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Client\Server\Minecraft\MinecraftVersionControllerTest;

use Illuminate\Support\Facades\Http;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Models\ActivityLog;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\EggVariable;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\ServerVariable;
use Pterodactyl\Services\Games\Minecraft\MinecraftSoftware;
use Pterodactyl\Services\Games\Minecraft\MinecraftVersionCatalog;
use Pterodactyl\Services\Plugins\PluginInstallProgress;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;
use Pterodactyl\Tests\Support\Fakes\FakePluginWorld;

uses(ClientApiIntegrationTestCase::class);

const PAPER_JAR = 'paper-1.21.4-232 contents';
const PAPER_URL = 'https://fill-data.papermc.io/v1/objects/abc/paper-1.21.4-232.jar';
const VANILLA_JAR = 'vanilla 1.21.4 contents';
const VANILLA_URL = 'https://piston-data.mojang.com/v1/objects/5c4d/server.jar';

/** The public version APIs, as they answered when this was written. */
function versionApis(FakePluginWorld $world, string $paperUrl = PAPER_URL, ?string $paperHash = null): FakePluginWorld
{
    $world->catalog['https://piston-meta.mojang.com/mc/game/version_manifest_v2.json'] = [
        'latest' => ['release' => '1.21.4', 'snapshot' => '25w03a'],
        'versions' => [
            ['id' => '25w03a', 'type' => 'snapshot', 'url' => 'https://piston-meta.mojang.com/v1/packages/x/25w03a.json'],
            ['id' => '1.21.4', 'type' => 'release', 'url' => 'https://piston-meta.mojang.com/v1/packages/y/1.21.4.json'],
            ['id' => 'b1.7.3', 'type' => 'old_beta', 'url' => 'https://piston-meta.mojang.com/v1/packages/z/b1.7.3.json'],
        ],
    ];
    $world->catalog['https://piston-meta.mojang.com/v1/packages/y/1.21.4.json'] = [
        'downloads' => ['server' => ['sha1' => sha1(VANILLA_JAR), 'size' => mb_strlen(VANILLA_JAR), 'url' => VANILLA_URL]],
    ];
    $world->catalog['https://fill.papermc.io/v3/projects/paper'] = [
        'project' => ['id' => 'paper', 'name' => 'Paper'],
        'versions' => ['1.21' => ['1.21.11-rc1', '1.21.4', '1.21'], '1.20' => ['1.20.6']],
    ];
    $world->catalog['https://fill.papermc.io/v3/projects/paper/versions/1.21.4/builds/latest'] = [
        'id' => 232,
        'channel' => 'STABLE',
        'downloads' => ['server:default' => ['name' => 'paper-1.21.4-232.jar', 'checksums' => ['sha256' => $paperHash ?? hash('sha256', PAPER_JAR)], 'size' => mb_strlen(PAPER_JAR), 'url' => $paperUrl]],
    ];
    $world->catalog['https://meta.fabricmc.net/v2/versions/game'] = [
        ['version' => '25w03a', 'stable' => false],
        ['version' => '1.21.4', 'stable' => true],
    ];
    $world->catalog['https://files.minecraftforge.net/net/minecraftforge/forge/promotions_slim.json'] = [
        'promos' => ['1.12.2-latest' => '14.23.5.2860', '1.21.4-latest' => '54.1.3', '1.21.4-recommended' => '54.1.0', '1.20.1-latest' => '47.4.0'],
    ];
    $world->catalog['https://maven.neoforged.net/api/maven/versions/releases/net/neoforged/neoforge'] = [
        'isSnapshot' => false,
        'versions' => ['0.25w14craftmine.3-beta', '20.2.3-beta', '21.4.100-beta', '21.0.5', '26.3.0.103'],
    ];
    $world->remote[PAPER_URL] = PAPER_JAR;
    $world->remote[VANILLA_URL] = VANILLA_JAR;

    return $world;
}

function versionsWorld(): FakePluginWorld
{
    return versionApis((new FakePluginWorld)->file('bungeecord.jar', 'old jar')->file('server.properties', 'motd=hi')->directory('world'));
}

test('the versions page is only for Minecraft servers', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $egg = Egg::factory()->create(['name' => 'Nginx website', 'features' => []]);
    $server->forceFill(['egg_id' => $egg->id])->save();

    $this->actingAs($user)->getJson($this->link($server, '/minecraft/versions'))
        ->assertBadRequest()
        ->assertJsonPath('errors.0.detail', 'This server does not run Minecraft Java.');
});

test('reading needs file.read and installing needs versions.manage', function (): void {
    versionsWorld();
    [$reader, $server] = $this->generateTestAccount([Permissions::FileRead->value]);

    $this->actingAs($reader)->getJson($this->link($server, '/minecraft/versions'))->assertOk();
    $this->actingAs($reader)->postJson($this->link($server, '/minecraft/versions/install'), ['software' => 'paper', 'version' => '1.21.4'])->assertForbidden();

    [$console, $other] = $this->generateTestAccount([Permissions::ControlConsole->value]);
    $this->actingAs($console)->getJson($this->link($other, '/minecraft/versions/paper'))->assertForbidden();
});

test('the software list says which software the egg can switch to', function (): void {
    versionsWorld();
    [$user, $server] = $this->generateTestAccount();

    $response = $this->actingAs($user)->getJson($this->link($server, '/minecraft/versions'))->assertOk()
        ->assertJsonPath('object', 'minecraft_software');

    $software = collect($response->json('attributes.software'))->keyBy('id');
    expect($software->keys()->all())->toBe(array_map(fn (MinecraftSoftware $case): string => $case->value, MinecraftSoftware::cases()))
        ->and($software['paper']['available'])->toBeTrue()
        ->and($software['forge']['available'])->toBeFalse()
        ->and($software['forge']['unavailable_reason'])->toContain('Forge egg');
});

test('each software lists its versions newest first, snapshots only when asked', function (string $software, array $releases, array $withSnapshots): void {
    versionsWorld();
    [$user, $server] = $this->generateTestAccount();

    $ids = fn (bool $snapshots): array => collect($this->actingAs($user)->getJson($this->link($server, '/minecraft/versions/'.$software.($snapshots ? '?snapshots=1' : '')))->assertOk()->json('data'))->pluck('id')->all();

    expect($ids(false))->toBe($releases)->and($ids(true))->toBe($withSnapshots);
})->with([
    'vanilla' => ['vanilla', ['1.21.4'], ['25w03a', '1.21.4']],
    'paper' => ['paper', ['1.21.4', '1.21', '1.20.6'], ['1.21.11-rc1', '1.21.4', '1.21', '1.20.6']],
    'fabric' => ['fabric', ['1.21.4'], ['25w03a', '1.21.4']],
    'forge' => ['forge', ['1.21.4-54.1.0', '1.20.1-47.4.0'], ['1.21.4-54.1.0', '1.20.1-47.4.0']],
    'neoforge' => ['neoforge', ['26.3.0.103', '21.4.100-beta', '21.0.5', '20.2.3-beta'], ['26.3.0.103', '21.4.100-beta', '21.0.5', '20.2.3-beta']],
]);

test('version lists are cached for an hour', function (): void {
    $world = versionsWorld();
    [$user, $server] = $this->generateTestAccount();

    $this->actingAs($user)->getJson($this->link($server, '/minecraft/versions/paper'))->assertOk();
    $this->actingAs($user)->getJson($this->link($server, '/minecraft/versions/paper'))->assertOk();

    expect($world->requestsTo('https://fill.papermc.io/v3/projects/paper'))->toHaveCount(1);
});

test('an API that does not answer gives a friendly error', function (): void {
    $world = versionsWorld();
    $world->catalog['https://fill.papermc.io/v3/projects/paper'] = fn () => Http::response('down', 503);
    [$user, $server] = $this->generateTestAccount();

    $this->actingAs($user)->getJson($this->link($server, '/minecraft/versions/paper'))
        ->assertStatus(502)
        ->assertJsonPath('errors.0.detail', 'PaperMC did not answer. Try again in a minute.');
});

test('installing Paper stops the server, checks the jar and swaps it in without touching the world', function (): void {
    $world = versionsWorld()->file('unix_args.txt', '@libraries/forge');
    [$user, $server] = $this->generateTestAccount();

    $id = $this->actingAs($user)->postJson($this->link($server, '/minecraft/versions/install'), ['software' => 'paper', 'version' => '1.21.4'])
        ->assertAccepted()
        ->assertJsonPath('object', 'minecraft_version_install')
        ->assertJsonPath('attributes.version_number', 'Paper 1.21.4')
        ->json('attributes.id');

    $this->actingAs($user)->getJson($this->link($server, '/minecraft/versions/install/'.$id))
        ->assertOk()
        ->assertJsonPath('attributes.status', 'completed')
        ->assertJsonPath('attributes.file_name', 'bungeecord.jar');

    expect($world->files['bungeecord.jar'])->toBe(PAPER_JAR)
        ->and($world->files['server.properties'])->toBe('motd=hi')
        ->and($world->directories)->toHaveKey('world')
        ->and($world->files)->not->toHaveKey('unix_args.txt')
        ->and($world->files['unix_args.txt.monoforge-old'])->toBe('@libraries/forge')
        ->and($world->pulled)->toBe([PAPER_URL])
        ->and($world->requestsTo('*/power'))->toHaveCount(1)
        ->and(collect(array_keys($world->files))->filter(fn (string $path): bool => str_ends_with($path, '.part'))->all())->toBe([]);

    $log = ActivityLog::query()->where('event', 'server:version.install')->sole();
    expect($log->properties->all())->toBe(['software' => 'Paper', 'version' => '1.21.4']);
});

test('installing Vanilla checks the sha1 Mojang gives', function (): void {
    $world = versionsWorld();
    $world->remote[VANILLA_URL] = 'tampered';
    [$user, $server] = $this->generateTestAccount();

    $id = $this->actingAs($user)->postJson($this->link($server, '/minecraft/versions/install'), ['software' => 'vanilla', 'version' => '1.21.4'])->assertAccepted()->json('attributes.id');

    $this->actingAs($user)->getJson($this->link($server, '/minecraft/versions/install/'.$id))
        ->assertJsonPath('attributes.status', 'failed')
        ->assertJsonPath('attributes.message', "bungeecord.jar did not match the catalog's sha1 hash, so it was removed.");
    expect($world->files['bungeecord.jar'])->toBe('old jar');
    expect(ActivityLog::query()->where('event', 'server:version.failed')->exists())->toBeTrue();
});

test('a download URL off the allowed hosts is never pulled', function (): void {
    $world = versionApis((new FakePluginWorld)->file('bungeecord.jar', 'old jar'), paperUrl: 'https://evil.example.com/paper.jar');
    $world->remote['https://evil.example.com/paper.jar'] = 'evil';
    [$user, $server] = $this->generateTestAccount();

    $this->actingAs($user)->postJson($this->link($server, '/minecraft/versions/install'), ['software' => 'paper', 'version' => '1.21.4'])
        ->assertBadRequest()
        ->assertJsonPath('errors.0.detail', 'The download is not on an allowed site, so it was not installed.');

    expect($world->pulled)->toBe([])->and($world->files['bungeecord.jar'])->toBe('old jar');
});

test('the allow-list only takes HTTPS on the version hosts', function (string $url, bool $allowed): void {
    expect(MinecraftVersionCatalog::allowedUrl($url))->toBe($allowed);
})->with([
    ['https://piston-data.mojang.com/v1/objects/a/server.jar', true],
    ['https://fill-data.papermc.io/v1/objects/a/paper.jar', true],
    ['https://api.purpurmc.org/v2/purpur/1.21.4/2416/download', true],
    ['https://meta.fabricmc.net/v2/versions/loader/1.21.4/0.16.10/1.0.1/server/jar', true],
    ['http://piston-data.mojang.com/server.jar', false],
    ['https://piston-data.mojang.com:8443/server.jar', false],
    ['https://user:pass@piston-data.mojang.com/server.jar', false],
    ['https://piston-data.mojang.com.evil.com/server.jar', false],
    ['https://cdn.modrinth.com/data/x.jar', false],
    ['https://127.0.0.1/server.jar', false],
]);

test('an unknown version or software is refused', function (): void {
    versionsWorld();
    [$user, $server] = $this->generateTestAccount();

    $this->actingAs($user)->postJson($this->link($server, '/minecraft/versions/install'), ['software' => 'paper', 'version' => '9.9.9'])->assertNotFound();
    $this->actingAs($user)->postJson($this->link($server, '/minecraft/versions/install'), ['software' => 'bukkit', 'version' => '1.21.4'])->assertUnprocessable();
    $this->actingAs($user)->postJson($this->link($server, '/minecraft/versions/install'), ['software' => 'paper', 'version' => '../../etc'])->assertUnprocessable();
    $this->actingAs($user)->getJson($this->link($server, '/minecraft/versions/bukkit'))->assertNotFound();
});

test('Forge needs a Forge egg, then sets FORGE_VERSION and reinstalls', function (): void {
    $world = versionsWorld();
    [$user, $server] = $this->generateTestAccount();

    $this->actingAs($user)->postJson($this->link($server, '/minecraft/versions/install'), ['software' => 'forge', 'version' => '1.21.4-54.1.0'])
        ->assertBadRequest();

    $egg = Egg::factory()->create(['name' => 'Forge Enhanced']);
    EggVariable::factory()->create(['egg_id' => $egg->id, 'env_variable' => 'SERVER_JARFILE', 'default_value' => 'server.jar', 'user_viewable' => 1, 'user_editable' => 1, 'rules' => 'required|regex:/^([\w\d._-]+)(\.jar)$/']);
    $forge = EggVariable::factory()->create(['egg_id' => $egg->id, 'env_variable' => 'FORGE_VERSION', 'default_value' => '', 'user_viewable' => 1, 'user_editable' => 1, 'rules' => 'nullable|regex:/^[0-9\.\-]+$/']);
    $server->forceFill(['egg_id' => $egg->id])->save();

    $id = $this->actingAs($user)->postJson($this->link($server, '/minecraft/versions/install'), ['software' => 'forge', 'version' => '1.21.4-54.1.0'])->assertAccepted()->json('attributes.id');

    // The server is installing now, so the page is locked; the progress is still recorded.
    expect(PluginInstallProgress::get($server, $id))->toMatchArray(['status' => 'completed', 'file_name' => '1.21.4-54.1.0']);
    expect(ServerVariable::query()->where('server_id', $server->id)->where('variable_id', $forge->id)->value('variable_value'))->toBe('1.21.4-54.1.0')
        ->and($world->requestsTo('*/reinstall'))->toHaveCount(1)
        ->and($world->pulled)->toBe([])
        ->and(Server::query()->findOrFail($server->id)->status)->toBe(Server::STATUS_INSTALLING);
});

test('installs are rate limited', function (): void {
    versionsWorld();
    [$user, $server] = $this->generateTestAccount();

    for ($i = 0; $i < 3; $i++) {
        $this->actingAs($user)->postJson($this->link($server, '/minecraft/versions/install'), ['software' => 'paper', 'version' => '1.21.4'])->assertAccepted();
    }

    $this->actingAs($user)->postJson($this->link($server, '/minecraft/versions/install'), ['software' => 'paper', 'version' => '1.21.4'])->assertTooManyRequests();
});

test('NeoForge versions map to their Minecraft version', function (string $version, ?string $minecraft): void {
    expect(MinecraftVersionCatalog::neoforgeMinecraft($version))->toBe($minecraft);
})->with([
    ['21.4.100-beta', '1.21.4'],
    ['21.0.5', '1.21'],
    ['20.2.3-beta', '1.20.2'],
    ['26.3.0.103', '26.3'],
    ['0.25w14craftmine.3-beta', null],
]);
