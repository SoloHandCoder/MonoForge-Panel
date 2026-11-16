<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Services\Entrance\MinecraftEntranceTest;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Enum\TunnelStatus;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Billing\ServerTunnel;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Game;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Share\ServerPage;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Entrance\EntranceHostnames;
use Pterodactyl\Services\Entrance\MinecraftEntrance;
use Pterodactyl\Services\Entrance\ProxyProtocolApplier;
use Pterodactyl\Services\Games\GameDetector;
use Pterodactyl\Services\Servers\ServerAddress;
use Pterodactyl\Services\Tunnels\TunnelService;
use Pterodactyl\Tests\Integration\IntegrationTestCase;

use function pterodactylTestCase;

uses(IntegrationTestCase::class);
uses(DatabaseTransactions::class);

const MC_AGENT = '5b9d1b0e-3c1f-4e8a-9a51-1f0e7d2c6b44';

const ENTRANCE_TUNNEL = '7e2c6b44-3c1f-4e8a-9a51-1f0e5b9d1b0e';

beforeEach(function (): void {
    // playit: creating any tunnel gives the entrance tunnel, which has an address.
    Http::fake(fn (Request $request) => match (parse_url($request->url(), PHP_URL_PATH)) {
        '/tunnels/create', '/v1/tunnels/create' => Http::response(['status' => 'success', 'data' => ['id' => ENTRANCE_TUNNEL]]),
        '/v1/agents/rundata' => Http::response(['status' => 'success', 'data' => ['agent_id' => MC_AGENT, 'tunnels' => [
            ['id' => ENTRANCE_TUNNEL, 'display_address' => 'mc-entrance.ply.gg:41234'],
        ]]]),
        default => Http::response(['status' => 'success', 'data' => null]),
    });
});

/** A node with a playit agent and the shared entrance through playit (or the gateway). */
function entranceNode(string $mode = Node::ENTRANCE_PLAYIT, array $extra = []): Node
{
    $node = Node::factory()->withLocation()->create(['fqdn' => 'home-node.example.net']);
    $node->forceFill([
        'playit_agent_id' => MC_AGENT,
        'playit_secret' => 'agent-secret-value',
        'mc_entrance_enabled' => true,
        'mc_entrance_domain' => 'play.example.com',
        'mc_entrance_mode' => $mode,
        'mc_entrance_address' => $mode === Node::ENTRANCE_PLAYIT ? 'mc-entrance.ply.gg:41234' : null,
        'mc_entrance_tunnel_id' => $mode === Node::ENTRANCE_PLAYIT ? ENTRANCE_TUNNEL : null,
        ...($mode === Node::ENTRANCE_GATEWAY ? ['gateway_enabled' => true, 'gateway_host' => 'gw.example.com', 'gateway_ports' => '25565-25700'] : []),
        ...$extra,
    ])->save();

    return $node;
}

function mcServer(Node $node, int $port, array $attributes = []): Server
{
    $allocation = Allocation::factory()->create(['node_id' => $node->id, 'ip' => '203.0.113.20', 'port' => $port]);

    return pterodactylTestCase()->createServerModel(['node_id' => $node->id, 'allocation_id' => $allocation->id, ...$attributes])->fresh(['node', 'allocation', 'egg']);
}

function bedrockEgg(): Egg
{
    return Egg::factory()->create(['name' => 'Minecraft Bedrock Edition', 'features' => []]);
}

test('names come from the public page slug or the server name and stay unique', function (): void {
    $node = entranceNode();
    $paged = mcServer($node, 25570, ['name' => 'Some Server']);
    ServerPage::query()->forceCreate(['server_id' => $paged->id, 'slug' => 'skyblock', 'published' => true, 'description' => '', 'banner_color' => '#8b5cf6']);
    $paged->forceFill(['mc_hostname' => null])->saveQuietly();

    $first = mcServer($node, 25571, ['name' => 'Survival World!']);
    $twin = mcServer($node, 25572, ['name' => 'Survival World']);
    $reserved = mcServer($node, 25573, ['name' => 'admin']);

    expect(EntranceHostnames::for($paged->refresh()))->toBe('skyblock')
        ->and(EntranceHostnames::for($first))->toBe('survival-world')
        ->and(EntranceHostnames::for($twin))->toStartWith('survival-world-')->not->toBe('survival-world')
        ->and(EntranceHostnames::for($reserved))->toStartWith('mc-');
});

test('names follow DNS label rules and keep reserved and taken names out', function (): void {
    $server = mcServer(entranceNode(), 25570, ['name' => 'Taken']);
    $server->forceFill(['mc_hostname' => 'taken'])->saveQuietly();

    expect(EntranceHostnames::error('survival'))->toBeNull()
        ->and(EntranceHostnames::error('a1-b2'))->toBeNull()
        ->and(EntranceHostnames::error('ab'))->not->toBeNull()
        ->and(EntranceHostnames::error('-bad'))->not->toBeNull()
        ->and(EntranceHostnames::error('bad-'))->not->toBeNull()
        ->and(EntranceHostnames::error('a--b'))->not->toBeNull()
        ->and(EntranceHostnames::error('Upper'))->not->toBeNull()
        ->and(EntranceHostnames::error('dots.not.ok'))->not->toBeNull()
        ->and(EntranceHostnames::error(str_repeat('a', 41)))->not->toBeNull()
        ->and(EntranceHostnames::error('www'))->toContain('reserved')
        ->and(EntranceHostnames::error('play'))->toContain('reserved')
        ->and(EntranceHostnames::error('mail'))->toContain('reserved')
        ->and(EntranceHostnames::error('taken'))->toContain('Another server')
        ->and(EntranceHostnames::error('taken', $server))->toBeNull();
});

test('a routed server joins by name, with the playit port until SRV records exist', function (): void {
    $node = entranceNode();
    $server = mcServer($node, 25570, ['name' => 'Survival']);
    $addresses = resolve(ServerAddress::class);

    expect($addresses->main($server))->toBe('survival.play.example.com:41234')
        ->and($addresses->source($server))->toBe(ServerAddress::SOURCE_ENTRANCE);

    $node->forceFill(['mc_entrance_srv' => true])->save();
    expect($addresses->main($server->fresh(['node', 'egg']) ?? $server))->toBe('survival.play.example.com');

    // No tunnel address yet: pending, never the node's own address.
    $node->forceFill(['mc_entrance_address' => null])->save();
    $fresh = $server->fresh(['node', 'egg']) ?? $server;
    expect($addresses->main($fresh))->toBeNull()->and($addresses->pending($fresh))->toBeTrue();
});

test('behind the gateway on 25565 the address has no port, and Bedrock or other games are not routed', function (): void {
    $node = entranceNode(Node::ENTRANCE_GATEWAY);
    $addresses = resolve(ServerAddress::class);

    expect($addresses->main(mcServer($node, 25570, ['name' => 'Lobby'])))->toBe('lobby.play.example.com')
        ->and(MinecraftEntrance::isRouted(mcServer($node, 25571, ['egg_id' => bedrockEgg()->id])))->toBeFalse()
        ->and($addresses->main(mcServer($node, 25572, ['egg_id' => Egg::factory()->create(['name' => 'Nginx website'])->id])))->toBe('gw.example.com:25572');
});

test('routed servers get no main playit tunnel, while extra ports keep theirs', function (): void {
    Game::query()->where('slug', GameDetector::MINECRAFT)->sole()
        ->forceFill(['extra_ports' => [['key' => 'voice', 'name' => 'Voice chat', 'protocol' => 'udp', 'port' => 24454, 'allocation' => 1]]])->save();
    $node = entranceNode();
    $server = mcServer($node, 25570);
    Allocation::factory()->create(['node_id' => $node->id, 'port' => 24454, 'server_id' => $server->id]);

    expect(resolve(TunnelService::class)->provisionAutomatic($server->fresh(['node', 'egg']) ?? $server))->toBeNull();
    $this->artisan('p:tunnels:sync')->assertSuccessful();

    $keys = ServerTunnel::query()->where('server_id', $server->id)->pluck('port_key')->all();
    expect($keys)->toBe(['voice']);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'tunnels/create') && ($request['origin']['data']['local_port'] ?? null) === 25570);
});

test('an existing main tunnel of a routed server is left alone and marked replaced', function (): void {
    $server = mcServer(entranceNode(), 25570);
    $tunnel = ServerTunnel::query()->create(['server_id' => $server->id, 'allocation_id' => $server->allocation_id, 'status' => TunnelStatus::Failed, 'last_error' => 'x']);

    resolve(TunnelService::class)->sync($tunnel);
    expect($tunnel->refresh()->status)->toBe(TunnelStatus::Failed);

    $this->actingAs(User::factory()->create(['root_admin' => true]));
    $this->getJson('/api/admin/tunnels')->assertOk()->assertJsonPath('data.0.attributes.replaced_by_entrance', true);
    $this->postJson('/api/admin/tunnels', ['server_id' => $server->id])->assertUnprocessable();
});

test('the node fetches only its own installed, unsuspended Minecraft servers, all on localhost', function (): void {
    $node = entranceNode();
    $routed = mcServer($node, 25570, ['name' => 'Routed']);
    mcServer($node, 25571, ['name' => 'Suspended', 'status' => Server::STATUS_SUSPENDED]);
    mcServer($node, 25572, ['name' => 'Installing', 'status' => Server::STATUS_INSTALLING]);
    mcServer($node, 25573, ['name' => 'Website', 'egg_id' => Egg::factory()->create(['name' => 'Nginx website'])->id]);
    $other = mcServer(entranceNode(), 25574, ['name' => 'Elsewhere']);

    $response = $this->withHeader('Authorization', "Bearer {$node->daemon_token_id}.".$node->getDecryptedKey())
        ->getJson('/api/remote/minecraft-routes')->assertOk()
        ->assertJsonPath('enabled', true)
        ->assertJsonPath('routes', 1)
        ->assertJsonPath('gate.config.bind', '127.0.0.1:25500')
        ->assertJsonPath('gate.config.lite.enabled', true);

    $routes = $response->json('gate.config.lite.routes');
    expect($routes)->toHaveCount(2)
        ->and($routes[0]['host'])->toBe(EntranceHostnames::for($routed).'.play.example.com')
        ->and($routes[0]['backend'])->toBe('127.0.0.1:25570')
        ->and($routes[0])->not->toHaveKey('proxyProtocol')
        ->and($routes[1]['host'])->toBe('*');
    foreach ($routes as $route) {
        expect($route['backend'])->toStartWith('127.0.0.1:');
    }

    expect((string) $response->getContent())->not->toContain('203.0.113.20')->not->toContain('home-node.example.net')
        ->not->toContain($other->uuid)->not->toContain('elsewhere');
    expect($node->refresh()->mc_entrance_synced_at)->not->toBeNull();
});

test('the routes need a valid daemon token', function (): void {
    $node = entranceNode();

    $this->getJson('/api/remote/minecraft-routes')->assertUnauthorized();
    $this->withHeader('Authorization', "Bearer {$node->daemon_token_id}.wrong-token")->getJson('/api/remote/minecraft-routes')->assertForbidden();
});

test('PROXY protocol is only sent to servers marked as reading it', function (): void {
    $node = entranceNode(extra: ['mc_entrance_proxy_protocol' => true]);
    $server = mcServer($node, 25570);
    $server->forceFill(['mc_proxy_protocol_at' => now()])->saveQuietly();
    mcServer($node, 25571);

    $routes = resolve(MinecraftEntrance::class)->gateConfig($node)['config']['lite']['routes'];

    expect($routes[0]['proxyProtocol'] ?? false)->toBeTrue()
        ->and($routes[1])->not->toHaveKey('proxyProtocol');
});

test('paper-global.yml gets proxy-protocol and keeps everything else', function (): void {
    $yaml = "proxies:\n  velocity:\n    enabled: false\nmisc:\n  max-joins-per-tick: 5\n";

    $changed = ProxyProtocolApplier::enable($yaml);

    expect($changed)->toContain('proxy-protocol: true')->toContain('max-joins-per-tick: 5')->toContain('velocity:')
        ->and(ProxyProtocolApplier::enable(''))->toContain('proxy-protocol: true')
        ->and(ProxyProtocolApplier::enable('proxies: [broken'))->toBeNull()
        ->and(ProxyProtocolApplier::enable('just a string'))->toBeNull();
});

test('administrators save the entrance; the proxy port may not be an allocation and the gateway must forward the entrance', function (): void {
    $node = Node::factory()->withLocation()->create();
    $node->forceFill(['playit_agent_id' => MC_AGENT, 'playit_secret' => 'agent-secret-value'])->save();
    Allocation::factory()->create(['node_id' => $node->id, 'port' => 25500]);
    $this->actingAs(User::factory()->create(['root_admin' => true]));
    $body = ['enabled' => true, 'domain' => 'play.example.com', 'mode' => 'playit', 'proxy_port' => 25500];

    $this->putJson("/api/admin/nodes/{$node->id}/minecraft-entrance", $body)->assertUnprocessable()->assertJsonPath('errors.0.meta.source_field', 'proxy_port');
    $this->putJson("/api/admin/nodes/{$node->id}/minecraft-entrance", [...$body, 'domain' => '10.0.0.1', 'proxy_port' => 25501])->assertUnprocessable();
    $this->putJson("/api/admin/nodes/{$node->id}/minecraft-entrance", [...$body, 'mode' => 'gateway', 'proxy_port' => 25501])->assertUnprocessable()->assertJsonPath('errors.0.meta.source_field', 'mode');

    $this->putJson("/api/admin/nodes/{$node->id}/minecraft-entrance", [...$body, 'proxy_port' => 25501])->assertOk()
        ->assertJsonPath('active', true)
        ->assertJsonPath('tunnel_address', 'mc-entrance.ply.gg:41234')
        ->assertJsonPath('public_port', 41234)
        ->assertJsonPath('dns.0.type', 'CNAME')
        ->assertJsonPath('dns.0.name', '*.play.example.com')
        ->assertJsonPath('dns.0.value', 'mc-entrance.ply.gg')
        ->assertJsonPath('commands.install', 'sudo bash mc-router.sh install');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'tunnels/create') && ($request['origin']['data']['local_port'] ?? null) === 25501);

    // Behind the gateway, the entrance port has to be forwarded.
    $node->forceFill(['gateway_enabled' => true, 'gateway_host' => 'gw.example.com', 'gateway_ports' => '25600-25700'])->save();
    $this->putJson("/api/admin/nodes/{$node->id}/minecraft-entrance", [...$body, 'mode' => 'gateway', 'entrance_port' => 25565])->assertUnprocessable()->assertJsonPath('errors.0.meta.source_field', 'entrance_port');
    $this->putJson("/api/admin/nodes/{$node->id}/minecraft-entrance", [...$body, 'mode' => 'gateway', 'entrance_port' => 25600])->assertOk()
        ->assertJsonPath('listen_port', 25600)
        ->assertJsonPath('public_port', 25600)
        ->assertJsonPath('dns.0.value', 'gw.example.com');
});

test('only node administrators can see or change the entrance', function (): void {
    $node = entranceNode();
    $this->actingAs(User::factory()->create());

    $this->getJson("/api/admin/nodes/{$node->id}/minecraft-entrance")->assertForbidden();
    $this->putJson("/api/admin/nodes/{$node->id}/minecraft-entrance", ['enabled' => false])->assertForbidden();
    $this->postJson("/api/admin/nodes/{$node->id}/minecraft-entrance/proxy-protocol")->assertForbidden();
});

test('owners change the name; reserved, taken and badly formed names are refused', function (): void {
    $node = entranceNode();
    $server = mcServer($node, 25570, ['name' => 'Survival']);
    $taken = mcServer($node, 25571, ['name' => 'Other']);
    $taken->forceFill(['mc_hostname' => 'other'])->saveQuietly();
    $url = "/api/client/servers/{$server->uuid}/network/minecraft-hostname";

    $this->actingAs($server->user)->getJson($url)->assertOk()
        ->assertJsonPath('routed', true)
        ->assertJsonPath('hostname', 'survival')
        ->assertJsonPath('address', 'survival.play.example.com:41234');

    foreach (['www', 'other', 'Bad_Name', 'x'] as $name) {
        $this->actingAs($server->user)->putJson($url, ['hostname' => $name])->assertUnprocessable();
    }

    $this->actingAs($server->user)->putJson($url, ['hostname' => 'my-smp'])->assertOk()
        ->assertJsonPath('address', 'my-smp.play.example.com:41234');
    expect($server->refresh()->mc_hostname)->toBe('my-smp');

    // A subuser without the rename permission may look but not change it; a stranger sees nothing.
    [$subuser, $shared] = $this->generateTestAccount([Permissions::WebsocketConnect->value]);
    $this->actingAs($subuser)->getJson("/api/client/servers/{$shared->uuid}/network/minecraft-hostname")->assertOk();
    $this->actingAs($subuser)->putJson("/api/client/servers/{$shared->uuid}/network/minecraft-hostname", ['hostname' => 'nope-name'])->assertForbidden();
    $this->actingAs(User::factory()->create())->getJson($url)->assertNotFound();
});
