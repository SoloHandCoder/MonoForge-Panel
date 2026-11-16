<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Services\Gateway\GameGatewayTest;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Data\ServerState;
use Pterodactyl\Enum\TunnelStatus;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Billing\ServerTunnel;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Game;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Share\ServerPage;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Games\GameDetector;
use Pterodactyl\Services\Servers\ServerAddress;
use Pterodactyl\Services\Tunnels\TunnelService;
use Pterodactyl\Tests\Integration\IntegrationTestCase;

use function pterodactylTestCase;

uses(IntegrationTestCase::class);
uses(DatabaseTransactions::class);

const GW_AGENT = '5b9d1b0e-3c1f-4e8a-9a51-1f0e7d2c6b44';

/** A node with a playit agent and, unless switched off, the game gateway forwarding 25565-25700. */
function gatewayNode(bool $gateway = true, string $ports = '25565-25700'): Node
{
    $node = Node::factory()->withLocation()->create(['fqdn' => 'home-node.example.net']);
    $node->forceFill([
        'playit_agent_id' => GW_AGENT,
        'playit_secret' => 'agent-secret-value',
        'gateway_enabled' => $gateway,
        'gateway_host' => 'play.example.com',
        'gateway_ports' => $ports,
    ])->save();

    return $node;
}

/** A server on the node's port; Bungeecord (the default egg) counts as Minecraft Java. */
function serverOnPort(Node $node, int $port, ?Egg $egg = null, ?int $extraPort = null): Server
{
    $primary = Allocation::factory()->create(['node_id' => $node->id, 'ip' => '203.0.113.20', 'port' => $port]);
    $attributes = ['node_id' => $node->id, 'allocation_id' => $primary->id];
    if ($egg instanceof Egg) {
        $attributes['egg_id'] = $egg->id;
    }

    $server = pterodactylTestCase()->createServerModel($attributes);
    if ($extraPort !== null) {
        Allocation::factory()->create(['node_id' => $node->id, 'ip' => '203.0.113.20', 'port' => $extraPort, 'server_id' => $server->id]);
    }

    return $server->fresh(['node', 'allocation', 'egg']) ?? $server;
}

function plainEgg(): Egg
{
    return Egg::factory()->create(['name' => 'Nginx website', 'features' => []]);
}

function voiceChat(): void
{
    Game::query()->where('slug', GameDetector::MINECRAFT)->sole()
        ->forceFill(['extra_ports' => [['key' => 'voice', 'name' => 'Voice chat', 'protocol' => 'udp', 'port' => 24454, 'allocation' => 1]]])->save();
}

/**
 * What the fake playit.gg answers: an error by default, so a call shows up as a failure.
 *
 * @param  array<string, mixed>|null  $set
 * @return array<string, mixed>|null
 */
function playitReply(?array $set = null, bool $reset = false): ?array
{
    static $reply = null;
    if ($reset || $set !== null) {
        $reply = $set;
    }

    return $reply;
}

beforeEach(function (): void {
    playitReply(reset: true);
    Http::fake(fn () => playitReply() === null
        ? Http::response(['status' => 'error', 'data' => 'should not be called'], 500)
        : Http::response(playitReply()));
});

test('a Minecraft Java server on 25565 joins the gateway host without a port', function (): void {
    $server = serverOnPort(gatewayNode(), 25565);
    $addresses = resolve(ServerAddress::class);

    expect($addresses->main($server))->toBe('play.example.com')
        ->and($addresses->source($server))->toBe(ServerAddress::SOURCE_GATEWAY)
        ->and($addresses->pending($server))->toBeFalse()
        ->and(resolve(TunnelService::class)->displayAddress($server))->toBe('play.example.com');
});

test('another forwarded port keeps the port, and a non-Minecraft game keeps 25565', function (): void {
    $node = gatewayNode();
    $addresses = resolve(ServerAddress::class);

    expect($addresses->main(serverOnPort($node, 25577)))->toBe('play.example.com:25577')
        ->and($addresses->main(serverOnPort($node, 25565, plainEgg())))->toBe('play.example.com:25565');
});

test('a port the gateway does not forward has no address and shows pending', function (): void {
    $server = serverOnPort(gatewayNode(), 30000);
    // A live playit tunnel is never shown on a gateway node.
    ServerTunnel::query()->create(['server_id' => $server->id, 'allocation_id' => $server->allocation_id, 'address' => 'xyz.joinmc.link', 'status' => TunnelStatus::Active]);
    $addresses = resolve(ServerAddress::class);

    expect($addresses->main($server))->toBeNull()
        ->and($addresses->source($server))->toBeNull()
        ->and($addresses->pending($server))->toBeTrue();
});

test('with the gateway off the playit tunnel is the address again', function (): void {
    $server = serverOnPort(gatewayNode(false), 25565);
    ServerTunnel::query()->updateOrCreate(['server_id' => $server->id, 'port_key' => ServerTunnel::MAIN], ['allocation_id' => $server->allocation_id, 'address' => 'xyz.joinmc.link', 'status' => TunnelStatus::Active]);
    $addresses = resolve(ServerAddress::class);

    expect($addresses->main($server))->toBe('xyz.joinmc.link')
        ->and($addresses->source($server))->toBe(ServerAddress::SOURCE_PLAYIT);

    // A node with the gateway switched on but no host is not in gateway mode.
    $server->node->forceFill(['gateway_enabled' => true, 'gateway_host' => null])->save();
    expect($addresses->main($server->fresh(['node']) ?? $server))->toBe('xyz.joinmc.link');
});

test('extra ports from Admin > Games map to forwarded allocations only', function (): void {
    voiceChat();
    $node = gatewayNode(ports: '25565-25600,24454');
    $addresses = resolve(ServerAddress::class);

    expect($addresses->extras(serverOnPort($node, 25565, extraPort: 24454)))
        ->toBe([['key' => 'voice', 'label' => 'Voice chat', 'address' => 'play.example.com:24454', 'protocol' => 'udp']]);

    $other = gatewayNode(ports: '25565-25600');
    expect($addresses->extras(serverOnPort($other, 25566, extraPort: 24454)))->toBe([]);
});

test('no playit tunnel is created for a server on a gateway node', function (): void {
    $server = serverOnPort(gatewayNode(), 25565);

    expect(ServerTunnel::query()->where('server_id', $server->id)->exists())->toBeFalse()
        ->and(resolve(TunnelService::class)->provisionAutomatic($server))->toBeNull()
        ->and(resolve(TunnelService::class)->provisionMissing($server->node))->toBe(0);
    Http::assertNothingSent();
});

test('existing tunnels of a gateway node are neither synced nor retried, and resume when it is off', function (): void {
    $node = gatewayNode();
    $server = serverOnPort($node, 25565);
    $tunnel = ServerTunnel::query()->create(['server_id' => $server->id, 'allocation_id' => $server->allocation_id, 'playit_tunnel_id' => '0c8f1f4e-6f7b-4a2e-9a1d-2f6e3c1b7a90', 'status' => TunnelStatus::Failed, 'last_error' => 'playit.gg did not answer.']);

    $this->artisan('p:tunnels:sync')->assertSuccessful();
    resolve(TunnelService::class)->sync($tunnel);
    resolve(TunnelService::class)->recreate($tunnel);

    expect($tunnel->refresh()->status)->toBe(TunnelStatus::Failed)
        ->and($tunnel->playit_tunnel_id)->toBe('0c8f1f4e-6f7b-4a2e-9a1d-2f6e3c1b7a90');
    Http::assertNothingSent();

    $node->forceFill(['gateway_enabled' => false])->save();
    playitReply(['status' => 'success', 'data' => ['agent_id' => GW_AGENT, 'tunnels' => [['id' => '0c8f1f4e-6f7b-4a2e-9a1d-2f6e3c1b7a90', 'display_address' => 'xyz.joinmc.link']]]]);
    resolve(TunnelService::class)->sync($tunnel->refresh());

    expect($tunnel->refresh()->status)->toBe(TunnelStatus::Active);
});

test('the admin tunnels list marks tunnels of a gateway node as replaced', function (): void {
    $server = serverOnPort(gatewayNode(), 25565);
    ServerTunnel::query()->create(['server_id' => $server->id, 'allocation_id' => $server->allocation_id, 'address' => 'xyz.joinmc.link', 'status' => TunnelStatus::Active]);
    $this->actingAs(User::factory()->create(['root_admin' => true]));

    $this->getJson('/api/admin/tunnels')->assertOk()->assertJsonPath('data.0.attributes.replaced_by_gateway', true);
    $this->postJson('/api/admin/tunnels', ['server_id' => $server->id])->assertUnprocessable()
        ->assertJsonPath('errors.0.detail', TunnelService::GATEWAY);
});

test('player-facing payloads never show the node address on a gateway node', function (): void {
    Cache::flush();
    $this->app->instance(ReadsServerState::class, new readonly class implements ReadsServerState
    {
        public function read(Server $server): ServerState
        {
            return new ServerState('offline', false, 0, 0, 0, 0, 0, 0);
        }
    });
    voiceChat();
    $server = serverOnPort(gatewayNode(ports: '25565-25600,24454'), 25570, extraPort: 24454);
    ServerPage::query()->forceCreate(['server_id' => $server->id, 'slug' => 'gwpage', 'published' => true, 'description' => '', 'banner_color' => '#8b5cf6']);

    $client = $this->actingAs($server->user)->getJson('/api/client/servers/'.$server->uuid)->assertOk()
        ->assertJsonPath('attributes.playit_address', 'play.example.com:25570')
        ->assertJsonPath('attributes.address_source', 'gateway')
        ->assertJsonPath('attributes.address_pending', false)
        ->assertJsonPath('attributes.extra_addresses.0.address', 'play.example.com:24454');
    $addresses = json_encode([$client->json('attributes.playit_address'), $client->json('attributes.extra_addresses')]);
    expect($addresses)->not->toContain('home-node.example.net')->not->toContain('203.0.113.20');

    $public = $this->withHeaders(['Origin' => mb_rtrim(config()->string('app.url'), '/'), 'X-Requested-With' => 'XMLHttpRequest'])
        ->getJson('/api/public/servers/gwpage')->assertOk()->assertJsonPath('address', 'play.example.com:25570');
    expect((string) $public->getContent())->not->toContain('home-node.example.net')->not->toContain('203.0.113.20');
});

test('a deployment prefers the allocations the gateway forwards', function (): void {
    $node = gatewayNode(ports: '25600');
    Allocation::factory()->count(5)->create(['node_id' => $node->id, 'port' => 30000]);
    Allocation::factory()->create(['node_id' => $node->id, 'port' => 25600]);

    $first = Allocation::query()->unassigned()->onNodes([$node->id])->forwardedFirst([$node->id])->inRandomOrder()->first();

    expect($first?->port)->toBe(25600);
});
