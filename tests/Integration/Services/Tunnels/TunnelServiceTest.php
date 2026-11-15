<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Services\Tunnels\TunnelServiceTest;

use Closure;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Enum\TunnelStatus;
use Pterodactyl\Models\Billing\ServerTunnel;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Tunnels\TunnelService;
use Pterodactyl\Tests\Integration\IntegrationTestCase;

uses(IntegrationTestCase::class);
uses(DatabaseTransactions::class);

// These tests cover the older tunnel list: the v1 run data call is answered "not found", so the
// client falls back to `/tunnels/list` (TunnelReadOnlyTest covers run data itself).
beforeEach(function (): void {
    Http::fake(['api.playit.gg/v1/agents/rundata' => Http::response(['status' => 'error', 'data' => ['type' => 'path-not-found', 'message' => ['path' => '/v1/agents/rundata']]], 404)]);
});

const AGENT = '5b9d1b0e-3c1f-4e8a-9a51-1f0e7d2c6b44';
const TUNNEL = '0c8f1f4e-6f7b-4a2e-9a1d-2f6e3c1b7a90';
const SECRET = 'agent-secret-value';

function playitNode(): Node
{
    $node = Node::factory()->withLocation()->create();
    $node->playit_agent_id = AGENT;
    $node->playit_secret = SECRET;
    $node->save();

    return $node;
}

/** @return array<string, mixed> */
function playitTunnel(array $alloc): array
{
    return ['id' => TUNNEL, 'tunnel_type' => 'minecraft-java', 'port_type' => 'tcp', 'alloc' => $alloc];
}

function allocated(): array
{
    return ['status' => 'allocated', 'data' => [
        'id' => TUNNEL, 'ip_hostname' => '147.185.221.1', 'assigned_domain' => 'xyz.joinmc.link',
        'assigned_srv' => null, 'port_start' => 41234, 'port_end' => 41235,
    ]];
}

function listed(array $tunnel): array
{
    return ['status' => 'success', 'data' => ['tunnels' => [$tunnel], 'tcp_alloc' => [], 'udp_alloc' => []]];
}

test('a new server on a node with an agent gets a tunnel that becomes active once allocated', function (): void {
    Http::fake([
        'api.playit.gg/v1/tunnels/create' => Http::response(['status' => 'success', 'data' => ['id' => TUNNEL]]),
        'api.playit.gg/tunnels/list' => Http::sequence()
            ->push(listed(playitTunnel(['status' => 'pending'])))
            ->push(listed(playitTunnel(allocated()))),
    ]);

    $server = $this->createServerModel(['node_id' => playitNode()->id]);

    $tunnel = ServerTunnel::query()->where('server_id', $server->id)->sole();
    expect($tunnel->status)->toBe(TunnelStatus::Pending)
        ->and($tunnel->playit_tunnel_id)->toBe(TUNNEL)
        ->and(resolve(TunnelService::class)->displayAddress($server))->toBeNull();

    resolve(TunnelService::class)->sync($tunnel);

    expect($tunnel->refresh()->status)->toBe(TunnelStatus::Active)
        ->and($tunnel->address)->toBe('xyz.joinmc.link:41234')
        ->and(resolve(TunnelService::class)->displayAddress($server))->toBe('xyz.joinmc.link:41234');

    Http::assertSent(function (Request $request) use ($server): bool {
        if ($request->url() !== 'https://api.playit.gg/v1/tunnels/create') {
            return false;
        }

        // The v1 create body.
        return $request->header('Authorization') === ['Agent-Key '.SECRET]
            && $request['protocol'] === ['type' => 'tunnel-type', 'details' => 'minecraft-java']
            && $request['origin'] === ['type' => 'agent', 'data' => ['agent_id' => AGENT, 'config' => ['fields' => [
                ['name' => 'local_ip', 'value' => $server->allocation->ip],
                ['name' => 'local_port', 'value' => (string) $server->allocation->port],
            ]]]]
            && $request['endpoint'] === ['type' => 'region', 'details' => ['region' => 'global', 'port' => null]]
            && $request['enabled'] === true;
    });
});

test('provision is idempotent', function (): void {
    Http::fake([
        'api.playit.gg/v1/tunnels/create' => Http::response(['status' => 'success', 'data' => ['id' => TUNNEL]]),
        'api.playit.gg/tunnels/list' => Http::response(listed(playitTunnel(['status' => 'pending']))),
    ]);

    $server = $this->createServerModel(['node_id' => playitNode()->id]);
    $again = resolve(TunnelService::class)->provision($server);

    expect(ServerTunnel::query()->where('server_id', $server->id)->count())->toBe(1)
        ->and($again->playit_tunnel_id)->toBe(TUNNEL);
    // Create, then the run data call that falls back to the tunnel list.
    Http::assertSentCount(3);
});

test('a playit failure never stops server creation and is kept on the tunnel', function (): void {
    Http::fake(['api.playit.gg/v1/tunnels/create' => Http::response(['status' => 'fail', 'data' => 'RequiresPlayitPremium'])]);

    $server = $this->createServerModel(['node_id' => playitNode()->id]);

    $tunnel = ServerTunnel::query()->where('server_id', $server->id)->sole();
    expect($tunnel->status)->toBe(TunnelStatus::Failed)
        ->and($tunnel->last_error)->toContain('RequiresPlayitPremium')
        ->and($tunnel->last_error)->not->toContain(SECRET)
        ->and(resolve(TunnelService::class)->displayAddress($server))->toBeNull();
});

test('an unreachable or error-answering playit marks the tunnel failed', function (mixed $response, string $error): void {
    Http::fake(['api.playit.gg/*' => $response]);

    $server = $this->createServerModel(['node_id' => playitNode()->id]);

    expect(ServerTunnel::query()->where('server_id', $server->id)->sole()->last_error)->toContain($error);
})->with([
    'connection' => [fn (): Closure => fn () => throw new ConnectionException('timed out'), 'Could not reach playit.gg'],
    'auth error' => [fn () => Http::response(['status' => 'error', 'data' => ['type' => 'auth', 'message' => 'InvalidAgentKey']], 401), 'auth: InvalidAgentKey'],
    'html' => [fn () => Http::response('<html>bad gateway</html>', 502), 'HTTP 502'],
]);

test('the scheduler retries failed tunnels', function (): void {
    Http::fake([
        'api.playit.gg/v1/tunnels/create' => Http::sequence()
            ->push(['status' => 'fail', 'data' => 'AgentNotFound'])
            ->push(['status' => 'success', 'data' => ['id' => TUNNEL]]),
        'api.playit.gg/tunnels/list' => Http::response(listed(playitTunnel(allocated()))),
    ]);

    $server = $this->createServerModel(['node_id' => playitNode()->id]);
    expect(ServerTunnel::query()->where('server_id', $server->id)->sole()->status)->toBe(TunnelStatus::Failed);

    $this->artisan('p:tunnels:sync')->assertSuccessful();

    expect(ServerTunnel::query()->where('server_id', $server->id)->sole())
        ->status->toBe(TunnelStatus::Active)
        ->last_error->toBeNull();
});

test('a tunnel playit dropped is forgotten and created again', function (): void {
    Http::fake([
        'api.playit.gg/v1/tunnels/create' => Http::response(['status' => 'success', 'data' => ['id' => TUNNEL]]),
        'api.playit.gg/tunnels/list' => Http::sequence()
            ->push(['status' => 'success', 'data' => ['tunnels' => [], 'tcp_alloc' => [], 'udp_alloc' => []]])
            ->push(listed(playitTunnel(allocated()))),
    ]);

    $server = $this->createServerModel(['node_id' => playitNode()->id]);
    $tunnel = ServerTunnel::query()->where('server_id', $server->id)->sole();
    expect($tunnel->status)->toBe(TunnelStatus::Failed)->and($tunnel->playit_tunnel_id)->toBeNull();

    expect(resolve(TunnelService::class)->sync($tunnel)->status)->toBe(TunnelStatus::Active);
});

test('deleting the server deletes its tunnel on playit', function (): void {
    Http::fake([
        'api.playit.gg/v1/tunnels/create' => Http::response(['status' => 'success', 'data' => ['id' => TUNNEL]]),
        'api.playit.gg/tunnels/list' => Http::response(listed(playitTunnel(allocated()))),
        'api.playit.gg/tunnels/delete' => Http::response(['status' => 'fail', 'data' => 'TunnelNotFound']),
    ]);

    $server = $this->createServerModel(['node_id' => playitNode()->id]);
    $server->allocations()->update(['server_id' => null]);
    $server->delete();

    expect(ServerTunnel::query()->where('server_id', $server->id)->exists())->toBeFalse();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.playit.gg/tunnels/delete'
        && $request['tunnel_id'] === TUNNEL
        && $request->header('Authorization') === ['Agent-Key '.SECRET]);
});

test('release marks the tunnel deleted and provision brings it back', function (): void {
    Http::fake([
        'api.playit.gg/v1/tunnels/create' => Http::response(['status' => 'success', 'data' => ['id' => TUNNEL]]),
        'api.playit.gg/tunnels/list' => Http::response(listed(playitTunnel(allocated()))),
        'api.playit.gg/tunnels/delete' => Http::response(['status' => 'success', 'data' => null]),
    ]);

    $server = $this->createServerModel(['node_id' => playitNode()->id]);
    $service = resolve(TunnelService::class);
    $service->release($server);

    expect(ServerTunnel::query()->where('server_id', $server->id)->sole())
        ->status->toBe(TunnelStatus::Deleted)
        ->playit_tunnel_id->toBeNull();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.playit.gg/tunnels/delete');

    expect($service->provision($server)->refresh()->status)->toBe(TunnelStatus::Active);
});

test('servers on nodes without an agent get no tunnel and call nothing', function (): void {
    Http::fake();

    $server = $this->createServerModel();

    expect(ServerTunnel::query()->where('server_id', $server->id)->exists())->toBeFalse();
    expect(resolve(TunnelService::class)->provision($server))
        ->status->toBe(TunnelStatus::Failed)
        ->last_error->toBe(TunnelService::NOT_CONFIGURED);
    Http::assertNothingSent();
});

test('the agent secret is encrypted at rest and hidden from serialisation', function (): void {
    $node = playitNode();

    expect(DB::table('nodes')->where('id', $node->id)->value('playit_secret'))->not->toBe(SECRET)
        ->and($node->fresh()->playit_secret)->toBe(SECRET)
        ->and($node->fresh()->toArray())->not->toHaveKey('playit_secret');
});

test('the client server resource carries the playit address', function (): void {
    Http::fake([
        'api.playit.gg/v1/tunnels/create' => Http::response(['status' => 'success', 'data' => ['id' => TUNNEL]]),
        'api.playit.gg/tunnels/list' => Http::response(listed(playitTunnel(allocated()))),
    ]);

    $server = $this->createServerModel(['node_id' => playitNode()->id]);
    resolve(TunnelService::class)->sync(ServerTunnel::query()->where('server_id', $server->id)->sole());

    /** @var Server $server */
    $this->actingAs($server->user)
        ->getJson('/api/client/servers/'.$server->uuid)
        ->assertOk()
        ->assertJsonPath('attributes.playit_address', 'xyz.joinmc.link:41234');
});
