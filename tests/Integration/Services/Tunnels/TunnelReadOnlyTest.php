<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Services\Tunnels\TunnelReadOnlyTest;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Enum\TunnelStatus;
use Pterodactyl\Jobs\Tunnels\DeletePlayitTunnelJob;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Billing\ServerTunnel;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Tunnels\PlayitClient;
use Pterodactyl\Services\Tunnels\TunnelService;
use Pterodactyl\Tests\Integration\Api\Admin\AdminApiIntegrationTestCase;

uses(AdminApiIntegrationTestCase::class);

const AGENT = '5b9d1b0e-3c1f-4e8a-9a51-1f0e7d2c6b44';
const SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
const TUNNEL = '0c8f1f4e-6f7b-4a2e-9a1d-2f6e3c1b7a90';
const CREATE = 'https://api.playit.gg/v1/tunnels/create';

/** What playit answers a create with a read-only agent key, as seen live. */
function readOnlyAnswer(): array
{
    return ['status' => 'error', 'data' => ['type' => 'auth', 'message' => 'NotAllowedWithReadOnly']];
}

/** A tunnel as `/v1/agents/rundata` lists it: the local port is a string in `agent_config.fields`. */
function runTunnel(int $port, ?string $display = 'abc-def.joinmc.link', string $ip = '127.0.0.1', ?string $disabled = null): array
{
    return [
        'id' => TUNNEL,
        'name' => 'survival',
        'display_address' => $display,
        'disabled_reason' => $disabled,
        'port_type' => 'tcp',
        'agent_config' => ['fields' => [
            ['name' => 'local_ip', 'value' => $ip],
            ['name' => 'local_port', 'value' => (string) $port],
        ]],
    ];
}

function runData(array ...$tunnels): array
{
    return ['status' => 'success', 'data' => ['agent_id' => AGENT, 'tunnels' => $tunnels, 'pending' => []]];
}

function serverOn(Node $node, int $port, string $ip = '0.0.0.0'): Server
{
    $allocation = Allocation::factory()->create(['node_id' => $node->id, 'ip' => $ip, 'port' => $port]);

    return test()->createServerModel(['node_id' => $node->id, 'allocation_id' => $allocation->id]);
}

/** Set the agent after the servers exist, so nothing is provisioned for them on creation. */
function attachAgent(Node $node, ?string $mode = null): Node
{
    $node->forceFill(['playit_agent_id' => AGENT, 'playit_secret' => SECRET, 'playit_mode' => $mode])->save();

    return $node;
}

function creates(): int
{
    return Http::recorded(fn (Request $request): bool => $request->url() === CREATE)->count();
}

test('a read-only agent key marks the node read-only and creation stops being retried', function (): void {
    Http::fake([
        'api.playit.gg/v1/tunnels/create' => Http::response(readOnlyAnswer(), 403),
        'api.playit.gg/v1/agents/rundata' => Http::response(runData()),
    ]);
    $node = attachAgent(Node::factory()->withLocation()->create());

    $server = serverOn($node, 25569);

    $tunnel = ServerTunnel::query()->where('server_id', $server->id)->sole();
    expect($node->refresh()->playit_mode)->toBe(Node::PLAYIT_READ_ONLY)
        ->and($node->isPlayitReadOnly())->toBeTrue()
        ->and($tunnel->status)->toBe(TunnelStatus::Failed)
        ->and($tunnel->playit_tunnel_id)->toBeNull()
        ->and($tunnel->last_error)->toBe(TunnelService::READ_ONLY)
        ->and(creates())->toBe(1);

    // The scheduler only reads the agent's tunnels now; it never tries to create one again.
    $this->artisan('p:tunnels:sync')->assertSuccessful();
    $this->artisan('p:tunnels:sync')->assertSuccessful();
    resolve(TunnelService::class)->sync($tunnel);
    resolve(TunnelService::class)->provision(serverOn($node, 25570));

    expect(creates())->toBe(1)
        ->and($tunnel->refresh()->last_error)->toBe(TunnelService::READ_ONLY);
});

test('the scheduler links a dashboard tunnel by its local port and shows its display address', function (): void {
    $node = Node::factory()->withLocation()->create();
    $server = serverOn($node, 25569);
    $other = serverOn($node, 25570);
    attachAgent($node, Node::PLAYIT_READ_ONLY);
    // The row left behind by the failed creation is taken over.
    ServerTunnel::query()->create(['server_id' => $server->id, 'allocation_id' => $server->allocation_id, 'status' => TunnelStatus::Failed, 'last_error' => TunnelService::READ_ONLY]);
    Http::fake(['api.playit.gg/v1/agents/rundata' => Http::response(runData(runTunnel(25569)))]);

    $this->artisan('p:tunnels:sync')->assertSuccessful();

    $tunnel = ServerTunnel::query()->where('server_id', $server->id)->sole();
    expect($tunnel)
        ->status->toBe(TunnelStatus::Active)
        ->playit_tunnel_id->toBe(TUNNEL)
        ->address->toBe('abc-def.joinmc.link')
        ->allocation_id->toBe($server->allocation_id)
        ->imported_at->not->toBeNull()
        ->and(resolve(TunnelService::class)->displayAddress($server))->toBe('abc-def.joinmc.link')
        ->and(ServerTunnel::query()->where('server_id', $other->id)->exists())->toBeFalse()
        ->and(creates())->toBe(0);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.playit.gg/v1/agents/rundata'
        && $request->data() === []
        && $request->header('Authorization') === ['Agent-Key '.SECRET]);

    // Running it again links nothing twice and keeps the address.
    $this->artisan('p:tunnels:sync')->assertSuccessful();
    expect(ServerTunnel::query()->where('playit_tunnel_id', TUNNEL)->count())->toBe(1)
        ->and($tunnel->refresh()->address)->toBe('abc-def.joinmc.link');
});

test('a local IP picks the server on that IP when several use the port', function (): void {
    $node = Node::factory()->withLocation()->create();
    $first = serverOn($node, 25565, '10.0.0.5');
    $second = serverOn($node, 25565, '10.0.0.6');
    attachAgent($node, Node::PLAYIT_READ_ONLY);
    Http::fake(['api.playit.gg/v1/agents/rundata' => Http::response(runData(runTunnel(25565, ip: '10.0.0.6')))]);

    $this->artisan('p:tunnels:sync')->assertSuccessful();

    expect(ServerTunnel::query()->where('server_id', $second->id)->value('playit_tunnel_id'))->toBe(TUNNEL)
        ->and(ServerTunnel::query()->where('server_id', $first->id)->exists())->toBeFalse();
});

test('a disabled or unallocated dashboard tunnel gives no address', function (): void {
    $node = Node::factory()->withLocation()->create();
    $disabled = serverOn($node, 25565);
    $waiting = serverOn($node, 25566);
    attachAgent($node, Node::PLAYIT_READ_ONLY);
    $other = [...runTunnel(25566, display: null), 'id' => '1c8f1f4e-6f7b-4a2e-9a1d-2f6e3c1b7a91'];
    Http::fake(['api.playit.gg/v1/agents/rundata' => Http::response(runData(runTunnel(25565, disabled: 'BandwidthLimit'), $other))]);

    $this->artisan('p:tunnels:sync')->assertSuccessful();

    expect(resolve(TunnelService::class)->displayAddress($disabled))->toBeNull()
        ->and(ServerTunnel::query()->where('server_id', $disabled->id)->value('last_error'))->toContain('BandwidthLimit')
        ->and(ServerTunnel::query()->where('server_id', $waiting->id)->value('status'))->toBe(TunnelStatus::Pending)
        ->and(resolve(TunnelService::class)->displayAddress($waiting))->toBeNull();
});

test('a write-capable key creates tunnels with the v1 body and reads the display address', function (): void {
    $node = Node::factory()->withLocation()->create();
    attachAgent($node);
    Http::fake([
        'api.playit.gg/v1/tunnels/create' => Http::response(['status' => 'success', 'data' => ['id' => TUNNEL]]),
        'api.playit.gg/v1/agents/rundata' => Http::response(runData(runTunnel(25565, display: '147.185.221.1:41234'))),
    ]);

    $server = serverOn($node, 25565);
    resolve(TunnelService::class)->sync(ServerTunnel::query()->where('server_id', $server->id)->sole());

    expect($node->refresh()->playit_mode)->toBeNull()
        ->and(resolve(TunnelService::class)->displayAddress($server))->toBe('147.185.221.1:41234');
    Http::assertSent(fn (Request $request): bool => $request->url() === CREATE
        && in_array($request['protocol']['type'], ['tunnel-type', 'raw-ports'], true)
        && $request['origin']['data']['config']['fields'] === [
            ['name' => 'local_ip', 'value' => '127.0.0.1'],
            ['name' => 'local_port', 'value' => '25565'],
        ]
        && $request['endpoint'] === ['type' => 'region', 'details' => ['region' => 'global', 'port' => null]]);
});

test('players see address pending until the tunnel is linked', function (): void {
    $node = Node::factory()->withLocation()->create();
    $server = serverOn($node, 25569);
    $plain = serverOn(Node::factory()->withLocation()->create(), 25565);
    attachAgent($node, Node::PLAYIT_READ_ONLY);

    $this->actingAs($server->user)->getJson('/api/client/servers/'.$server->uuid)
        ->assertOk()
        ->assertJsonPath('attributes.playit_address', null)
        ->assertJsonPath('attributes.address_pending', true);

    // A node without an agent shows its allocation as before.
    $this->actingAs($plain->user)->getJson('/api/client/servers/'.$plain->uuid)
        ->assertOk()
        ->assertJsonPath('attributes.address_pending', false);

    Http::fake(['api.playit.gg/v1/agents/rundata' => Http::response(runData(runTunnel(25569)))]);
    $this->artisan('p:tunnels:sync')->assertSuccessful();

    $this->actingAs($server->user)->getJson('/api/client/servers/'.$server->uuid)
        ->assertOk()
        ->assertJsonPath('attributes.playit_address', 'abc-def.joinmc.link')
        ->assertJsonPath('attributes.address_pending', false);
});

test('the tunnels tab lists servers still without a tunnel and the read-only nodes', function (): void {
    $node = Node::factory()->withLocation()->create(['name' => 'node-ro']);
    $waiting = serverOn($node, 25569);
    $linked = serverOn($node, 25570);
    serverOn(Node::factory()->withLocation()->create(), 25565);
    attachAgent($node, Node::PLAYIT_READ_ONLY);
    ServerTunnel::query()->create(['server_id' => $linked->id, 'allocation_id' => $linked->allocation_id, 'playit_tunnel_id' => TUNNEL, 'address' => 'x.joinmc.link', 'status' => TunnelStatus::Active]);

    $this->getJson(route('api.admin.tunnels.unlinked'))
        ->assertOk()
        ->assertExactJson([
            'nodes' => [['id' => $node->id, 'name' => 'node-ro', 'read_only' => true]],
            'servers' => [[
                'server_id' => $waiting->id,
                'server_name' => $waiting->name,
                'server_identifier' => $waiting->uuidShort,
                'node_id' => $node->id,
                'node_name' => 'node-ro',
                'local_ip' => '127.0.0.1',
                'local_port' => 25569,
                'tunnel_status' => null,
            ]],
        ]);

    $this->getJson(route('api.admin.nodes.playit', $node->id))->assertOk()->assertJsonPath('read_only', true);
});

test('saving a new agent key clears read-only so creation is tried again', function (): void {
    $node = attachAgent(Node::factory()->withLocation()->create(), Node::PLAYIT_READ_ONLY);

    // Saving the same agent with a blank secret keeps it read-only.
    $this->putJson(route('api.admin.nodes.playit.update', $node->id), ['agent_id' => AGENT, 'secret' => ''])
        ->assertOk()
        ->assertJsonPath('read_only', true);

    $this->putJson(route('api.admin.nodes.playit.update', $node->id), ['agent_id' => AGENT, 'secret' => str_repeat('ab', 32)])
        ->assertOk()
        ->assertJsonPath('read_only', false);

    expect($node->refresh()->playit_mode)->toBeNull();
});

test('a non-admin cannot list servers without a tunnel', function (): void {
    $this->actingAsNonAdmin();

    $this->getJson(route('api.admin.tunnels.unlinked'))->assertForbidden();
});

test('a read-only agent key is not retried when deleting a tunnel', function (): void {
    $node = attachAgent(Node::factory()->withLocation()->create(), Node::PLAYIT_READ_ONLY);
    Http::fake(['api.playit.gg/tunnels/delete' => Http::response(readOnlyAnswer(), 403)]);
    Log::shouldReceive('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'NotAllowedWithReadOnly'));

    (new DeletePlayitTunnelJob($node->id, TUNNEL))->handle(resolve(PlayitClient::class));

    Http::assertSentCount(1);
});
