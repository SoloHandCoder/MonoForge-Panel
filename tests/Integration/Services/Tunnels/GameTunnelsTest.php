<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Services\Tunnels\GameTunnelsTest;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Enum\TunnelStatus;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Billing\ServerTunnel;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Game;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Games\GameDetector;
use Pterodactyl\Services\Tunnels\TunnelService;
use Pterodactyl\Tests\Integration\IntegrationTestCase;

uses(IntegrationTestCase::class);
uses(DatabaseTransactions::class);

const AGENT = '5b9d1b0e-3c1f-4e8a-9a51-1f0e7d2c6b44';
const MAIN_TUNNEL = '0c8f1f4e-6f7b-4a2e-9a1d-2f6e3c1b7a90';
const VOICE_TUNNEL = '1d9f2f5e-7f8b-4b3e-8b2d-3f7e4c2b8b01';

function gameNode(): Node
{
    $node = Node::factory()->withLocation()->create();
    $node->forceFill(['playit_agent_id' => AGENT, 'playit_secret' => 'agent-secret-value'])->save();

    return $node;
}

/**
 * playit: typed tunnels are made with the v1 API, plain ports with `/tunnels/create`, and run
 * data lists both with an address.
 */
function fakeGamePlayit(): void
{
    Http::fake(fn (Request $request) => match (parse_url($request->url(), PHP_URL_PATH)) {
        '/v1/tunnels/create' => Http::response(['status' => 'success', 'data' => ['id' => MAIN_TUNNEL]]),
        '/tunnels/create' => Http::response(['status' => 'success', 'data' => ['id' => VOICE_TUNNEL]]),
        '/v1/agents/rundata' => Http::response(['status' => 'success', 'data' => ['agent_id' => AGENT, 'tunnels' => [
            ['id' => MAIN_TUNNEL, 'display_address' => 'xyz.joinmc.link'],
            ['id' => VOICE_TUNNEL, 'display_address' => 'voice.ply.gg:41000'],
        ]]]),
        '/tunnels/delete' => Http::response(['status' => 'success', 'data' => null]),
        default => Http::response(['status' => 'error', 'data' => ['type' => 'path-not-found', 'message' => ['path' => 'x']]], 404),
    });
}

/** A server on the node, with an extra allocation on `$extraPort` when given. */
function gameServer(Node $node, ?Egg $egg = null, ?int $extraPort = null): Server
{
    $primary = Allocation::factory()->create(['node_id' => $node->id, 'ip' => '0.0.0.0', 'port' => 25565]);
    $attributes = ['node_id' => $node->id, 'allocation_id' => $primary->id];
    if ($egg instanceof Egg) {
        $attributes['egg_id'] = $egg->id;
    }

    if ($extraPort !== null) {
        // Added before the server exists, so the Created event already sees it.
        $attributes['allocation_id'] = $primary->id;
    }

    $server = test()->createServerModel($attributes);

    if ($extraPort !== null) {
        Allocation::factory()->create(['node_id' => $node->id, 'ip' => '0.0.0.0', 'port' => $extraPort, 'server_id' => $server->id]);
    }

    return $server;
}

function websiteEgg(): Egg
{
    return Egg::factory()->create(['name' => 'Nginx website', 'features' => []]);
}

test('a server whose egg no game claims gets no automatic tunnel', function (): void {
    fakeGamePlayit();

    $server = gameServer(gameNode(), websiteEgg());

    expect(ServerTunnel::query()->where('server_id', $server->id)->exists())->toBeFalse();
    Http::assertNothingSent();
});

test('a game with automatic tunnels switched off gets none either', function (): void {
    fakeGamePlayit();
    Game::query()->where('slug', GameDetector::MINECRAFT)->update(['auto_tunnels' => false]);

    $server = gameServer(gameNode());

    expect(ServerTunnel::query()->where('server_id', $server->id)->exists())->toBeFalse();
});

test('an egg claimed by a custom game with automatic tunnels gets a plain tunnel', function (): void {
    fakeGamePlayit();
    $egg = websiteEgg();
    $game = Game::factory()->create(['auto_tunnels' => true]);
    $egg->forceFill(['game_id' => $game->id])->save();

    $server = gameServer(gameNode(), $egg);

    $tunnel = ServerTunnel::query()->where('server_id', $server->id)->sole();
    expect($tunnel->port_key)->toBe(ServerTunnel::MAIN)->and($tunnel->playit_tunnel_id)->toBe(VOICE_TUNNEL);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.playit.gg/tunnels/create' && $request['port_type'] === 'both');
});

test('an extra UDP tunnel is created for the allocation matching the voice chat port', function (): void {
    fakeGamePlayit();
    $node = gameNode();
    $server = gameServer($node, extraPort: 24454);

    // The extra allocation arrived after the server was created: the scheduler adds its tunnel.
    $this->artisan('p:tunnels:sync')->assertSuccessful();

    $voice = ServerTunnel::query()->where('server_id', $server->id)->where('port_key', 'voice')->sole();
    expect($voice->label)->toBe('Voice chat')
        ->and($voice->protocol)->toBe('udp')
        ->and($voice->allocation?->port)->toBe(24454)
        ->and($voice->playit_tunnel_id)->toBe(VOICE_TUNNEL);

    resolve(TunnelService::class)->sync($voice);
    resolve(TunnelService::class)->sync(ServerTunnel::query()->where('server_id', $server->id)->where('port_key', ServerTunnel::MAIN)->sole());

    expect($voice->refresh()->status)->toBe(TunnelStatus::Active);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.playit.gg/tunnels/create'
        && $request['port_type'] === 'udp'
        && $request['origin']['data']['local_port'] === 24454
        && $request['tunnel_type'] === null);

    $tunnels = resolve(TunnelService::class);
    expect($tunnels->displayAddress($server->fresh()))->toBe('xyz.joinmc.link')
        ->and($tunnels->extraAddresses($server))->toBe([['key' => 'voice', 'label' => 'Voice chat', 'address' => 'voice.ply.gg:41000', 'protocol' => 'udp']]);
});

test('an extra port can match the second allocation by position', function (): void {
    $game = Game::query()->where('slug', GameDetector::MINECRAFT)->sole();
    $game->forceFill(['extra_ports' => [['key' => 'map', 'name' => 'Web map', 'protocol' => 'tcp', 'port' => null, 'allocation' => 1]]])->save();
    $node = gameNode();
    $server = gameServer($node, extraPort: 8100);

    $planned = resolve(TunnelService::class)->plannedExtras($server, $game->refresh());

    expect($planned)->toHaveKey('map')->and($planned['map']['protocol'])->toBe('tcp');
});

test('a server without a matching allocation gets no extra tunnel', function (): void {
    fakeGamePlayit();
    $server = gameServer(gameNode(), extraPort: 30000);

    $this->artisan('p:tunnels:sync')->assertSuccessful();

    expect(ServerTunnel::query()->where('server_id', $server->id)->pluck('port_key')->all())->toBe([ServerTunnel::MAIN]);
});

test('deleting a server removes all of its tunnels on playit', function (): void {
    fakeGamePlayit();
    $server = gameServer(gameNode(), extraPort: 24454);
    $this->artisan('p:tunnels:sync')->assertSuccessful();
    expect(ServerTunnel::query()->where('server_id', $server->id)->whereNotNull('playit_tunnel_id')->count())->toBe(2);

    resolve(TunnelService::class)->release($server, deleteImported: true);

    expect(ServerTunnel::query()->where('server_id', $server->id)->where('status', '!=', TunnelStatus::Deleted)->count())->toBe(0);
    foreach ([MAIN_TUNNEL, VOICE_TUNNEL] as $id) {
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.playit.gg/tunnels/delete' && $request['tunnel_id'] === $id);
    }
});

test('the scheduler retries a pending extra tunnel', function (): void {
    fakeGamePlayit();
    $server = gameServer(gameNode(), extraPort: 24454);
    $allocation = Allocation::query()->where('server_id', $server->id)->where('port', 24454)->sole();
    $voice = ServerTunnel::query()->create([
        'server_id' => $server->id, 'port_key' => 'voice', 'label' => 'Voice chat', 'protocol' => 'udp',
        'allocation_id' => $allocation->id, 'status' => TunnelStatus::Failed, 'last_error' => 'playit.gg did not answer.',
    ]);

    $this->artisan('p:tunnels:sync')->assertSuccessful();

    expect($voice->refresh()->playit_tunnel_id)->toBe(VOICE_TUNNEL)
        ->and($voice->status)->toBe(TunnelStatus::Active);
});

test('a failed tunnel of a server that is not a game is parked instead of retried', function (): void {
    fakeGamePlayit();
    $server = gameServer(gameNode(), websiteEgg());
    $tunnel = ServerTunnel::query()->create(['server_id' => $server->id, 'allocation_id' => $server->allocation_id, 'status' => TunnelStatus::Failed, 'last_error' => 'playit.gg returned an error: validation: failed to parse body.']);

    $this->artisan('p:tunnels:sync')->assertSuccessful();
    $this->artisan('p:tunnels:sync')->assertSuccessful();

    expect($tunnel->refresh()->hold_reason)->toBe(ServerTunnel::HOLD_NOT_A_GAME)
        ->and($tunnel->last_error)->toBe(TunnelService::NOT_A_GAME)
        ->and($tunnel->status)->toBe(TunnelStatus::Failed);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'tunnels/create'));
});

test('the tunnel limit gets a clear message and a back-off', function (): void {
    Http::fake([
        'api.playit.gg/v1/tunnels/create' => Http::response(['status' => 'fail', 'data' => 'AccountUnassignedTunnelLimitReached']),
        'api.playit.gg/*' => Http::response(['status' => 'success', 'data' => ['agent_id' => AGENT, 'tunnels' => []]]),
    ]);

    $server = gameServer(gameNode());
    $tunnel = ServerTunnel::query()->where('server_id', $server->id)->sole();

    expect($tunnel->status)->toBe(TunnelStatus::Failed)
        ->and($tunnel->last_error)->toBe(TunnelService::TUNNEL_LIMIT)
        ->and($tunnel->hold_reason)->toBe(ServerTunnel::HOLD_TUNNEL_LIMIT)
        ->and($tunnel->retry_after?->isFuture())->toBeTrue();

    $creates = fn (): int => Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/v1/tunnels/create'))->count();
    $before = $creates();
    $this->artisan('p:tunnels:sync')->assertSuccessful();
    expect($creates())->toBe($before);

    $this->travelTo(CarbonImmutable::now()->addHours(TunnelService::TUNNEL_LIMIT_BACKOFF_HOURS + 1));
    $this->artisan('p:tunnels:sync')->assertSuccessful();
    expect($creates())->toBeGreaterThan($before);
});
