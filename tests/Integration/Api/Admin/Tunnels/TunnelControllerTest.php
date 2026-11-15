<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Admin\Tunnels\TunnelControllerTest;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Enum\TunnelStatus;
use Pterodactyl\Exceptions\Tunnels\PlayitException;
use Pterodactyl\Models\Billing\ServerTunnel;
use Pterodactyl\Models\Node;
use Pterodactyl\Services\Tunnels\TunnelSettings;
use Pterodactyl\Tests\Integration\Api\Admin\AdminApiIntegrationTestCase;

uses(AdminApiIntegrationTestCase::class);

const AGENT_ID = '5b9d1b0e-3c1f-4e8a-9a51-1f0e7d2c6b44';

const SECRET_KEY = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

test('the node playit secret is write-only', function (): void {
    $node = Node::factory()->withLocation()->create();

    $this->putJson(route('api.admin.nodes.playit.update', $node->id), ['agent_id' => AGENT_ID, 'secret' => SECRET_KEY])
        ->assertOk()
        ->assertExactJson(['agent_id' => AGENT_ID, 'secret_set' => true]);

    // A blank secret keeps the stored one.
    $this->putJson(route('api.admin.nodes.playit.update', $node->id), ['agent_id' => AGENT_ID, 'secret' => ''])->assertOk();
    expect($node->fresh()->playit_secret)->toBe(SECRET_KEY);

    $response = $this->getJson(route('api.admin.nodes.playit', $node->id))->assertOk();
    expect($response->getContent())->not->toContain(SECRET_KEY);

    $this->getJson(route('api.admin.nodes.view', $node->id))->assertOk()->assertDontSee(SECRET_KEY);

    // Removing the agent drops the secret too.
    $this->putJson(route('api.admin.nodes.playit.update', $node->id), ['agent_id' => null])
        ->assertOk()
        ->assertExactJson(['agent_id' => null, 'secret_set' => false]);
});

test('test connection lists the agent tunnels', function (): void {
    Http::fake(['api.playit.gg/tunnels/list' => Http::response(['status' => 'success', 'data' => ['tunnels' => [['id' => 'a'], ['id' => 'b']]]])]);
    $node = Node::factory()->withLocation()->create();
    $node->forceFill(['playit_agent_id' => AGENT_ID, 'playit_secret' => 'top-secret'])->save();

    $this->postJson(route('api.admin.nodes.playit.test', $node->id))
        ->assertOk()
        ->assertJson(['success' => true, 'tunnel_count' => 2]);

    Http::assertSent(fn (Request $request): bool => $request['agent_id'] === AGENT_ID && $request['tunnel_id'] === null);
});

test('test connection reports a rejected key', function (): void {
    Http::fake(['api.playit.gg/*' => Http::response(['status' => 'error', 'data' => ['type' => 'auth', 'message' => 'InvalidAgentKey']], 401)]);
    $node = Node::factory()->withLocation()->create();
    $node->forceFill(['playit_agent_id' => AGENT_ID, 'playit_secret' => 'wrong'])->save();

    $this->postJson(route('api.admin.nodes.playit.test', $node->id))
        ->assertOk()
        ->assertJson(['success' => false])
        ->assertJsonPath('message', PlayitException::INVALID_AGENT_KEY_MESSAGE);
});

test('other playit errors are shown as they are', function (): void {
    Http::fake(['api.playit.gg/*' => Http::response(['status' => 'fail', 'data' => 'AgentNotFound'])]);
    $node = Node::factory()->withLocation()->create();
    $node->forceFill(['playit_agent_id' => AGENT_ID, 'playit_secret' => SECRET_KEY])->save();

    $this->postJson(route('api.admin.nodes.playit.test', $node->id))
        ->assertOk()
        ->assertJsonPath('message', 'playit.gg refused the request: AgentNotFound.');
});

test('a secret and agent id pasted from playit.toml are cleaned before they are stored', function (string $secret, string $agentId): void {
    $node = Node::factory()->withLocation()->create();

    $this->putJson(route('api.admin.nodes.playit.update', $node->id), ['agent_id' => $agentId, 'secret' => $secret])
        ->assertOk()
        ->assertExactJson(['agent_id' => AGENT_ID, 'secret_set' => true]);

    $node->refresh();
    expect($node->playit_secret)->toBe(SECRET_KEY)
        ->and($node->playit_agent_id)->toBe(AGENT_ID);
})->with([
    'plain' => [SECRET_KEY, AGENT_ID],
    'whitespace' => ["  \t".SECRET_KEY." \n", ' '.AGENT_ID.' '],
    'double quotes' => ['"'.SECRET_KEY.'"', '"'.AGENT_ID.'"'],
    'single quotes' => ["'".SECRET_KEY."'", "'".AGENT_ID."'"],
    'toml line' => ['secret_key = "'.SECRET_KEY.'"', 'agent_id = "'.AGENT_ID.'"'],
    'toml line without spaces' => ['secret_key="'.SECRET_KEY.'"', 'agent_id='.AGENT_ID],
    'spaces inside the quotes and upper case' => ['  SECRET_KEY =  " '.SECRET_KEY.' " ', 'AGENT_ID = '.mb_strtoupper(AGENT_ID)],
]);

test('a secret that does not look like a key is refused with a clear message', function (string $secret): void {
    $node = Node::factory()->withLocation()->create();

    $response = $this->putJson(route('api.admin.nodes.playit.update', $node->id), ['agent_id' => AGENT_ID, 'secret' => $secret])
        ->assertUnprocessable();

    expect($response->getContent())->toContain('secret_key value')
        ->and($node->refresh()->playit_secret)->toBeNull();
})->with([
    'too short' => ['abc123'],
    'two pairs of quotes' => ['""'.SECRET_KEY.'""'],
    'spaces inside' => ['0123456789abcdef 0123456789abcdef'],
    'the agent id line' => ['agent_id = "'.AGENT_ID.'"'],
    'a whole toml file' => ['secret_key = "'.SECRET_KEY."\"\nagent_id = \"".AGENT_ID.'"'],
]);

test('an agent id that is not a uuid is refused', function (): void {
    $node = Node::factory()->withLocation()->create();

    $this->putJson(route('api.admin.nodes.playit.update', $node->id), ['agent_id' => 'agent_id = nope', 'secret' => SECRET_KEY])
        ->assertUnprocessable();
});

test('lists tunnels and retries a failed one', function (): void {
    Http::fake([
        'api.playit.gg/tunnels/create' => Http::response(['status' => 'success', 'data' => ['id' => 'c0ffee00-0000-4000-8000-000000000001']]),
        'api.playit.gg/tunnels/list' => Http::response(['status' => 'success', 'data' => ['tunnels' => [[
            'id' => 'c0ffee00-0000-4000-8000-000000000001',
            'tunnel_type' => null,
            'alloc' => ['status' => 'allocated', 'data' => ['assigned_domain' => 'abc.ply.gg', 'port_start' => 1234]],
        ]]]]),
    ]);
    $node = Node::factory()->withLocation()->create();
    $node->forceFill(['playit_agent_id' => AGENT_ID, 'playit_secret' => 'top-secret'])->save();
    $server = $this->createServerModel(['node_id' => $node->id]);
    $tunnel = ServerTunnel::query()->where('server_id', $server->id)->sole();
    $tunnel->forceFill(['status' => TunnelStatus::Failed, 'last_error' => 'boom', 'playit_tunnel_id' => null])->save();

    $this->getJson(route('api.admin.tunnels', ['status' => 'failed']))
        ->assertOk()
        ->assertJsonPath('data.0.attributes.server_id', $server->id)
        ->assertJsonPath('data.0.attributes.last_error', 'boom');

    $this->postJson(route('api.admin.tunnels.retry', $tunnel->id))
        ->assertOk()
        ->assertJsonPath('attributes.status', 'active')
        ->assertJsonPath('attributes.address', 'abc.ply.gg:1234');
});

test('stores the subdomain settings and checks names against them', function (): void {
    $this->putJson(route('api.admin.tunnels.settings.update'), [
        'subdomains_enabled' => true,
        'base_domain' => 'MonoForge.net',
        'blocklist' => ['WWW', 'panel', ' '],
    ])->assertOk()->assertExactJson([
        'subdomains_enabled' => true,
        'base_domain' => 'monoforge.net',
        'blocklist' => ['www', 'panel'],
    ]);

    expect(TunnelSettings::subdomainProblem('www'))->toBe('That name is reserved.')
        ->and(TunnelSettings::subdomainProblem('-bad'))->not->toBeNull()
        ->and(TunnelSettings::subdomainProblem('survival'))->toBeNull();

    $this->putJson(route('api.admin.tunnels.settings.update'), ['subdomains_enabled' => true, 'base_domain' => '', 'blocklist' => []])
        ->assertUnprocessable();
});

test('non-admins cannot reach the tunnel endpoints', function (): void {
    $node = Node::factory()->withLocation()->create();
    $this->actingAsNonAdmin();

    $this->getJson(route('api.admin.tunnels'))->assertForbidden();
    $this->getJson(route('api.admin.tunnels.settings'))->assertForbidden();
    $this->getJson(route('api.admin.nodes.playit', $node->id))->assertForbidden();
    $this->putJson(route('api.admin.nodes.playit.update', $node->id), ['agent_id' => null])->assertForbidden();
});
