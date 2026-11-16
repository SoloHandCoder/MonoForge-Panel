<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Admin\Nodes\GatewayControllerTest;

use Pterodactyl\Models\ActivityLog;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Node;
use Pterodactyl\Tests\Integration\Api\Admin\AdminApiIntegrationTestCase;

uses(AdminApiIntegrationTestCase::class);

function gatewayBody(array $overrides = []): array
{
    return ['enabled' => true, 'host' => 'play.example.com', 'ports' => '25565-25700', ...$overrides];
}

test('the gateway card starts off with the script defaults and its commands', function (): void {
    $node = Node::factory()->withLocation()->create();

    $this->getJson("/api/admin/nodes/{$node->id}/gateway")->assertOk()
        ->assertJsonPath('enabled', false)
        ->assertJsonPath('active', false)
        ->assertJsonPath('host', null)
        ->assertJsonPath('ports', '25565-25700')
        ->assertJsonPath('ranges', [[25565, 25700]])
        ->assertJsonPath('commands.vps_init', 'sudo bash gateway.sh vps-init --ports 25565-25700')
        ->assertJsonPath('commands.status', 'sudo bash gateway.sh status');
});

test('the gateway is saved, normalized and logged', function (): void {
    $node = Node::factory()->withLocation()->create();

    $response = $this->putJson("/api/admin/nodes/{$node->id}/gateway", gatewayBody(['host' => ' Play.Example.COM. ', 'ports' => '25565-25700, 7777']))->assertOk()
        ->assertJsonPath('enabled', true)
        ->assertJsonPath('active', true)
        ->assertJsonPath('host', 'play.example.com')
        ->assertJsonPath('ports', '25565-25700,7777')
        ->assertJsonPath('ranges', [[25565, 25700], [7777, 7777]])
        ->assertJsonPath('commands.vps_init', 'sudo bash gateway.sh vps-init --ports 25565-25700,7777');

    expect($response->json('commands.download'))->toContain('/scripts/gateway.sh')
        ->and($node->refresh()->hasGateway())->toBeTrue()
        ->and(ActivityLog::query()->where('event', 'admin:node.gateway')->exists())->toBeTrue();

    // Turned off, the host and ports are kept for later.
    $this->putJson("/api/admin/nodes/{$node->id}/gateway", ['enabled' => false])->assertOk()
        ->assertJsonPath('active', false)
        ->assertJsonPath('host', 'play.example.com');
});

dataset('invalid gateways', [
    'the SSH port' => [['ports' => '20-30'], 'ports'],
    'a range over WireGuard' => [['ports' => '51000-52000'], 'ports'],
    'the WireGuard port' => [['ports' => '51820'], 'ports'],
    'a privileged port' => [['ports' => '80'], 'ports'],
    'eight ranges' => [['ports' => '2000,2001,2002,2003,2004,2005,2006,2007'], 'ports'],
    'a reversed range' => [['ports' => '25700-25565'], 'ports'],
    'a port over 65535' => [['ports' => '25565-70000'], 'ports'],
    'garbage ports' => [['ports' => '25565-'], 'ports'],
    'no ports' => [['ports' => null], 'ports'],
    'a URL' => [['host' => 'https://play.example.com'], 'host'],
    'a host with a port' => [['host' => 'play.example.com:25565'], 'host'],
    'a private IP' => [['host' => '192.168.1.10'], 'host'],
    'IPv6' => [['host' => '2001:db8::1'], 'host'],
    'a single label' => [['host' => 'localhost'], 'host'],
    'no host' => [['host' => null], 'host'],
]);

test('invalid hosts and port lists are refused', function (array $overrides, string $field): void {
    $node = Node::factory()->withLocation()->create();

    $this->putJson("/api/admin/nodes/{$node->id}/gateway", gatewayBody($overrides))->assertUnprocessable()
        ->assertJsonPath('errors.0.meta.source_field', $field);

    expect($node->refresh()->gateway_enabled)->toBeFalse();
})->with('invalid gateways');

test("the node's own address is never accepted as the public host", function (): void {
    $node = Node::factory()->withLocation()->create(['fqdn' => 'home.example.net']);

    $this->putJson("/api/admin/nodes/{$node->id}/gateway", gatewayBody(['host' => 'home.example.net']))->assertUnprocessable();
    $this->putJson("/api/admin/nodes/{$node->id}/gateway", gatewayBody(['host' => '203.0.113.5']))->assertOk();
});

test('the check lists forwarded and not forwarded allocations without touching the network', function (): void {
    $node = Node::factory()->withLocation()->create();
    $node->forceFill(['gateway_enabled' => true, 'gateway_host' => 'play.example.com', 'gateway_ports' => '25565-25600'])->save();
    foreach ([25565, 25566, 25567, 30000] as $port) {
        Allocation::factory()->create(['node_id' => $node->id, 'port' => $port]);
    }

    $server = $this->createServerModel(['node_id' => $node->id, 'allocation_id' => Allocation::factory()->create(['node_id' => $node->id, 'port' => 24454])->id]);

    $this->postJson("/api/admin/nodes/{$node->id}/gateway/check")->assertOk()
        ->assertJsonPath('forwarded.total', 3)
        ->assertJsonPath('forwarded.ports', '25565-25567')
        ->assertJsonPath('not_forwarded.total', 2)
        ->assertJsonPath('not_forwarded.assigned', 1)
        ->assertJsonPath('not_forwarded.ports', '24454, 30000')
        ->assertJsonPath('servers_not_forwarded.0.server_id', $server->id)
        ->assertJsonPath('servers_not_forwarded.0.primary', true);

    // Ports typed in but not saved yet.
    $this->postJson("/api/admin/nodes/{$node->id}/gateway/check", ['ports' => '24000-31000'])->assertOk()
        ->assertJsonPath('not_forwarded.total', 0);
    $this->postJson("/api/admin/nodes/{$node->id}/gateway/check", ['ports' => '22'])->assertUnprocessable();
});

test('only administrators who may manage nodes can see or change the gateway', function (): void {
    $node = Node::factory()->withLocation()->create();
    $this->actingAsNonAdmin();

    $this->getJson("/api/admin/nodes/{$node->id}/gateway")->assertForbidden();
    $this->putJson("/api/admin/nodes/{$node->id}/gateway", gatewayBody())->assertForbidden();
    $this->postJson("/api/admin/nodes/{$node->id}/gateway/check")->assertForbidden();
});

test('the gateway script is served without signing in', function (): void {
    auth()->logout();

    $response = $this->get('/scripts/gateway.sh')->assertOk();

    expect($response->headers->get('Content-Disposition'))->toContain('gateway.sh');
});
