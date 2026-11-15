<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Admin\Tunnels\TunnelImportTest;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Enum\TunnelStatus;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\ApiKey;
use Pterodactyl\Models\Billing\ServerTunnel;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Tunnels\TunnelService;
use Pterodactyl\Tests\Integration\Api\Admin\AdminApiIntegrationTestCase;

uses(AdminApiIntegrationTestCase::class);

// These tests cover the older tunnel list: the v1 run data call is answered "not found", so the
// client falls back to `/tunnels/list` (TunnelReadOnlyTest covers run data itself).
beforeEach(function (): void {
    Http::fake(['api.playit.gg/v1/agents/rundata' => Http::response(['status' => 'error', 'data' => ['type' => 'path-not-found', 'message' => ['path' => '/v1/agents/rundata']]], 404)]);
});

const AGENT = '5b9d1b0e-3c1f-4e8a-9a51-1f0e7d2c6b44';
const SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
const TUNNEL_A = '0c8f1f4e-6f7b-4a2e-9a1d-2f6e3c1b7a90';
const TUNNEL_B = '1c8f1f4e-6f7b-4a2e-9a1d-2f6e3c1b7a91';

/** A node whose agent is set only after its servers exist, so nothing is provisioned for them. */
function attachAgent(Node $node): Node
{
    $node->forceFill(['playit_agent_id' => AGENT, 'playit_secret' => SECRET])->save();

    return $node;
}

/** @return array<string, mixed> */
function handMade(string $id, int $port, string $ip = '127.0.0.1', ?array $alloc = null, string $agent = AGENT): array
{
    return [
        'id' => $id,
        'tunnel_type' => 'minecraft-java',
        'name' => 'hand-made-'.$port,
        'port_type' => 'tcp',
        'port_count' => 1,
        'alloc' => $alloc ?? ['status' => 'allocated', 'data' => [
            'id' => $id, 'ip_hostname' => '147.185.221.1', 'assigned_domain' => 'xyz.joinmc.link',
            'assigned_srv' => null, 'port_start' => 41234, 'port_end' => 41235,
        ]],
        'origin' => ['type' => 'agent', 'data' => ['agent_id' => $agent, 'agent_name' => 'node', 'local_ip' => $ip, 'local_port' => $port]],
    ];
}

function fakePlayit(array ...$tunnels): void
{
    Http::fake([
        'api.playit.gg/tunnels/list' => Http::response(['status' => 'success', 'data' => ['tunnels' => $tunnels, 'tcp_alloc' => [], 'udp_alloc' => []]]),
        'api.playit.gg/v1/tunnels/create' => Http::response(['status' => 'success', 'data' => ['id' => 'c0ffee00-0000-4000-8000-000000000001']]),
        'api.playit.gg/tunnels/delete' => Http::response(['status' => 'success', 'data' => null]),
    ]);
}

/** A server on the node whose primary allocation is ip:port. */
function serverOn(Node $node, int $port, string $ip = '0.0.0.0'): Server
{
    $allocation = Allocation::factory()->create(['node_id' => $node->id, 'ip' => $ip, 'port' => $port]);

    return test()->createServerModel(['node_id' => $node->id, 'allocation_id' => $allocation->id]);
}

function deletesSent(): int
{
    return Http::recorded(fn (Request $request): bool => $request->url() === 'https://api.playit.gg/tunnels/delete')->count();
}

test('a hand-made tunnel is linked to the server on its port', function (): void {
    $node = Node::factory()->withLocation()->create();
    $server = serverOn($node, 25565);
    attachAgent($node);
    fakePlayit(handMade(TUNNEL_A, 25565));

    $this->postJson(route('api.admin.tunnels.import'))
        ->assertOk()
        ->assertJsonPath('imported', 1)
        ->assertJsonPath('skipped', 0)
        ->assertJsonPath('errors', [])
        ->assertJsonPath('results.0.result', 'imported')
        ->assertJsonPath('results.0.playit_tunnel_id', TUNNEL_A)
        ->assertJsonPath('results.0.server_ids', [$server->id]);

    $tunnel = ServerTunnel::query()->where('server_id', $server->id)->sole();
    expect($tunnel)
        ->status->toBe(TunnelStatus::Active)
        ->playit_tunnel_id->toBe(TUNNEL_A)
        ->address->toBe('xyz.joinmc.link:41234')
        ->allocation_id->toBe($server->allocation_id)
        ->imported_at->not->toBeNull()
        ->and(resolve(TunnelService::class)->displayAddress($server))->toBe('xyz.joinmc.link:41234');

    // Only the agent's tunnels were read (run data, then the list); nothing was created or changed on playit.
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.playit.gg/tunnels/list'
        && $request['agent_id'] === AGENT
        && $request->header('Authorization') === ['Agent-Key '.SECRET]);

    $this->getJson(route('api.admin.tunnels'))->assertJsonPath('data.0.attributes.imported_at', fn (?string $at): bool => $at !== null);
});

test('an SRV name is used as the address and an unallocated tunnel waits as pending', function (): void {
    $node = Node::factory()->withLocation()->create();
    $java = serverOn($node, 25565);
    $other = serverOn($node, 25566);
    attachAgent($node);

    $srv = ['status' => 'allocated', 'data' => ['assigned_domain' => 'abc.ply.gg', 'assigned_srv' => 'survival.joinmc.link', 'port_start' => 5000]];
    fakePlayit(handMade(TUNNEL_A, 25565, alloc: $srv), handMade(TUNNEL_B, 25566, alloc: ['status' => 'pending']));

    $this->postJson(route('api.admin.tunnels.import'))->assertOk()->assertJsonPath('imported', 2);

    expect(ServerTunnel::query()->where('server_id', $java->id)->sole())
        ->status->toBe(TunnelStatus::Active)
        ->address->toBe('survival.joinmc.link')
        ->and(ServerTunnel::query()->where('server_id', $other->id)->sole())
        ->status->toBe(TunnelStatus::Pending)
        ->address->toBeNull();
});

test('running the import again links nothing twice', function (): void {
    $node = Node::factory()->withLocation()->create();
    $server = serverOn($node, 25565);
    attachAgent($node);
    fakePlayit(handMade(TUNNEL_A, 25565));

    $this->postJson(route('api.admin.tunnels.import'))->assertJsonPath('imported', 1);
    $first = ServerTunnel::query()->where('server_id', $server->id)->sole();

    $this->postJson(route('api.admin.tunnels.import'))
        ->assertOk()
        ->assertJsonPath('imported', 0)
        ->assertJsonPath('skipped', 1)
        ->assertJsonPath('results.0.result', 'already_linked')
        ->assertJsonPath('results.0.reason', sprintf('Already linked to server #%d.', $server->id));

    expect(ServerTunnel::query()->where('server_id', $server->id)->sole()->imported_at?->toAtomString())
        ->toBe($first->imported_at?->toAtomString());
});

test('a server that already has a tunnel of its own is skipped', function (): void {
    $node = Node::factory()->withLocation()->create();
    $server = serverOn($node, 25565);
    attachAgent($node);
    ServerTunnel::query()->create(['server_id' => $server->id, 'allocation_id' => $server->allocation_id, 'playit_tunnel_id' => TUNNEL_B, 'address' => 'own.ply.gg:1', 'status' => TunnelStatus::Active]);
    fakePlayit(handMade(TUNNEL_A, 25565));

    $this->postJson(route('api.admin.tunnels.import'))
        ->assertOk()
        ->assertJsonPath('imported', 0)
        ->assertJsonPath('results.0.result', 'server_has_tunnel')
        ->assertJsonPath('results.0.server_ids', [$server->id]);

    expect(ServerTunnel::query()->where('server_id', $server->id)->sole()->playit_tunnel_id)->toBe(TUNNEL_B);
});

test('a failed tunnel the panel never made on playit is replaced by the imported one', function (): void {
    $node = Node::factory()->withLocation()->create();
    $server = serverOn($node, 25565);
    attachAgent($node);
    ServerTunnel::query()->create(['server_id' => $server->id, 'status' => TunnelStatus::Failed, 'last_error' => TunnelService::NOT_CONFIGURED]);
    fakePlayit(handMade(TUNNEL_A, 25565));

    $this->postJson(route('api.admin.tunnels.import'))->assertOk()->assertJsonPath('imported', 1);

    expect(ServerTunnel::query()->where('server_id', $server->id)->sole())
        ->status->toBe(TunnelStatus::Active)
        ->last_error->toBeNull()
        ->playit_tunnel_id->toBe(TUNNEL_A);
});

test('a tunnel to a port no server uses is skipped', function (): void {
    $node = Node::factory()->withLocation()->create();
    serverOn($node, 25565);
    attachAgent($node);
    fakePlayit(handMade(TUNNEL_A, 25600));

    $this->postJson(route('api.admin.tunnels.import'))
        ->assertOk()
        ->assertJsonPath('imported', 0)
        ->assertJsonPath('skipped', 1)
        ->assertJsonPath('results.0.result', 'no_match')
        ->assertJsonPath('results.0.reason', sprintf('No server on %s uses port 25600.', $node->name));

    expect(ServerTunnel::query()->count())->toBe(0);
});

test('a free allocation on the port is not a match', function (): void {
    $node = attachAgent(Node::factory()->withLocation()->create());
    Allocation::factory()->create(['node_id' => $node->id, 'ip' => '0.0.0.0', 'port' => 25565]);
    fakePlayit(handMade(TUNNEL_A, 25565));

    $this->postJson(route('api.admin.tunnels.import'))->assertOk()->assertJsonPath('results.0.result', 'no_match');
});

test('a port several servers share is ambiguous unless the local IP tells them apart', function (): void {
    $node = Node::factory()->withLocation()->create();
    $first = serverOn($node, 25565, '10.0.0.1');
    $second = serverOn($node, 25565, '10.0.0.2');
    attachAgent($node);
    fakePlayit(handMade(TUNNEL_A, 25565), handMade(TUNNEL_B, 25565, '10.0.0.2'));

    $response = $this->postJson(route('api.admin.tunnels.import'))
        ->assertOk()
        ->assertJsonPath('imported', 1)
        ->assertJsonPath('skipped', 1)
        ->assertJsonPath('results.0.result', 'ambiguous')
        ->assertJsonPath('results.1.result', 'imported')
        ->assertJsonPath('results.1.server_ids', [$second->id]);

    expect($response->json('results.0.server_ids'))->toEqualCanonicalizing([$first->id, $second->id])
        ->and($response->json('results.0.reason'))->toContain('2 servers')
        ->and(ServerTunnel::query()->where('server_id', $first->id)->exists())->toBeFalse()
        ->and(ServerTunnel::query()->where('server_id', $second->id)->sole()->playit_tunnel_id)->toBe(TUNNEL_B);
});

test("a tunnel to a server's additional port is not linked as its address", function (): void {
    $node = Node::factory()->withLocation()->create();
    $server = serverOn($node, 25565);
    Allocation::factory()->create(['node_id' => $node->id, 'ip' => '0.0.0.0', 'port' => 24454, 'server_id' => $server->id]);
    attachAgent($node);
    fakePlayit(handMade(TUNNEL_A, 24454));

    $this->postJson(route('api.admin.tunnels.import'))
        ->assertOk()
        ->assertJsonPath('imported', 0)
        ->assertJsonPath('results.0.result', 'no_match')
        ->assertJsonPath('results.0.server_ids', [$server->id])
        ->assertJsonPath('results.0.reason', fn (string $reason): bool => str_contains($reason, 'additional port'));

    expect(ServerTunnel::query()->where('server_id', $server->id)->exists())->toBeFalse();
});

test('a real local IP matches a server bound to every address', function (): void {
    $node = Node::factory()->withLocation()->create();
    $server = serverOn($node, 25565);
    attachAgent($node);
    fakePlayit(handMade(TUNNEL_A, 25565, '192.168.1.20'));

    $this->postJson(route('api.admin.tunnels.import'))->assertOk()->assertJsonPath('results.0.server_ids', [$server->id]);
});

test('managed tunnels and tunnels of another agent are skipped', function (): void {
    $node = Node::factory()->withLocation()->create();
    serverOn($node, 25565);
    attachAgent($node);
    $managed = [...handMade(TUNNEL_A, 25565), 'origin' => ['type' => 'managed', 'data' => ['agent_id' => AGENT, 'agent_name' => 'node']]];
    fakePlayit($managed, handMade(TUNNEL_B, 25565, agent: '99999999-3c1f-4e8a-9a51-1f0e7d2c6b44'));

    $this->postJson(route('api.admin.tunnels.import'))
        ->assertOk()
        ->assertJsonPath('imported', 0)
        ->assertJsonPath('results.0.result', 'unsupported')
        ->assertJsonPath('results.1.result', 'unsupported');
});

test('only the chosen node is imported, and an agent playit refuses is reported', function (): void {
    $chosen = Node::factory()->withLocation()->create();
    $other = Node::factory()->withLocation()->create();
    serverOn($chosen, 25565);
    serverOn($other, 25565);
    attachAgent($chosen);
    $other->forceFill(['playit_agent_id' => AGENT, 'playit_secret' => 'ffffffffffffffffffffffffffffffff'])->save();
    Http::fake(fn (Request $request) => $request->header('Authorization') === ['Agent-Key '.SECRET]
        ? Http::response(['status' => 'success', 'data' => ['tunnels' => [handMade(TUNNEL_A, 25565)]]])
        : Http::response(['status' => 'error', 'data' => ['type' => 'auth', 'message' => 'InvalidAgentKey']], 401));

    $this->postJson(route('api.admin.tunnels.import', ['node' => $chosen->id]))
        ->assertOk()
        ->assertJsonPath('imported', 1)
        ->assertJsonCount(1, 'results')
        ->assertJsonPath('results.0.node_id', $chosen->id);

    $this->postJson(route('api.admin.tunnels.import', ['node' => $other->id]))
        ->assertOk()
        ->assertJsonPath('imported', 0)
        ->assertJsonPath('errors.0.node_id', $other->id)
        ->assertJsonPath('errors.0.message', fn (string $message): bool => str_contains($message, 'secret_key'));

    $this->postJson(route('api.admin.tunnels.import', ['node' => 999999]))->assertUnprocessable();
});

test('release leaves an imported tunnel on playit', function (): void {
    $node = Node::factory()->withLocation()->create();
    $server = serverOn($node, 25565);
    attachAgent($node);
    fakePlayit(handMade(TUNNEL_A, 25565));
    $this->postJson(route('api.admin.tunnels.import'))->assertJsonPath('imported', 1);

    resolve(TunnelService::class)->release($server);

    expect(deletesSent())->toBe(0)
        ->and(ServerTunnel::query()->where('server_id', $server->id)->sole())
        ->status->toBe(TunnelStatus::Deleted)
        ->imported_at->toBeNull();
});

test('a new primary port leaves the imported tunnel on playit and gives the server a new one', function (): void {
    $node = Node::factory()->withLocation()->create();
    $server = serverOn($node, 25565);
    attachAgent($node);
    fakePlayit(handMade(TUNNEL_A, 25565));
    $this->postJson(route('api.admin.tunnels.import'))->assertJsonPath('imported', 1);

    $allocation = Allocation::factory()->create(['node_id' => $node->id, 'ip' => '0.0.0.0', 'port' => 25570, 'server_id' => $server->id]);
    $server->update(['allocation_id' => $allocation->id]);

    expect(deletesSent())->toBe(0)
        ->and(ServerTunnel::query()->where('server_id', $server->id)->sole())
        ->allocation_id->toBe($allocation->id)
        ->imported_at->toBeNull();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.playit.gg/v1/tunnels/create'
        && $request['origin']['data']['config']['fields'][1] === ['name' => 'local_port', 'value' => '25570']);
});

test('the admin recreating an imported tunnel deletes it on playit', function (): void {
    $node = Node::factory()->withLocation()->create();
    $server = serverOn($node, 25565);
    attachAgent($node);
    fakePlayit(handMade(TUNNEL_A, 25565));
    $this->postJson(route('api.admin.tunnels.import'))->assertJsonPath('imported', 1);
    $tunnel = ServerTunnel::query()->where('server_id', $server->id)->sole();

    $this->postJson(route('api.admin.tunnels.recreate', $tunnel->id))->assertOk();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.playit.gg/tunnels/delete' && $request['tunnel_id'] === TUNNEL_A);
    expect($tunnel->refresh()->imported_at)->toBeNull();
});

test('deleting the server deletes its imported tunnel on playit too', function (): void {
    $node = Node::factory()->withLocation()->create();
    $server = serverOn($node, 25565);
    attachAgent($node);
    fakePlayit(handMade(TUNNEL_A, 25565));
    $this->postJson(route('api.admin.tunnels.import'))->assertJsonPath('imported', 1);

    $server->allocations()->update(['server_id' => null]);
    $server->delete();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.playit.gg/tunnels/delete' && $request['tunnel_id'] === TUNNEL_A);
});

test('the import needs the servers permission and a panel session', function (): void {
    fakePlayit();
    $this->actingAsNonAdmin();
    $this->postJson(route('api.admin.tunnels.import'))->assertForbidden();

    $admin = User::factory()->create(['root_admin' => true]);
    $key = ApiKey::factory()->create(['user_id' => $admin->id, 'key_type' => ApiKey::TYPE_ACCOUNT]);
    Auth::forgetGuards();
    $this->withHeader('Accept', 'application/vnd.pterodactyl.v1+json')
        ->withHeader('Authorization', 'Bearer '.$key->identifier.decrypt($key->token));

    $this->postJson(route('api.admin.tunnels.import'))->assertForbidden();
    Http::assertNothingSent();
});
