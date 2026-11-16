<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Services\Tunnels\PlayitAgentSwitchTest;

use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Enum\PlayitClaimStatus;
use Pterodactyl\Enum\TunnelStatus;
use Pterodactyl\Models\ActivityLog;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\ApiKey;
use Pterodactyl\Models\Billing\PlayitAgentClaim;
use Pterodactyl\Models\Billing\PlayitOldTunnel;
use Pterodactyl\Models\Billing\ServerTunnel;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Tunnels\PlayitAgentSwitchService;
use Pterodactyl\Services\Tunnels\PlayitClient;
use Pterodactyl\Services\Tunnels\TunnelService;
use Pterodactyl\Tests\Integration\Api\Admin\AdminApiIntegrationTestCase;

uses(AdminApiIntegrationTestCase::class);

const OLD_AGENT = '5b9d1b0e-3c1f-4e8a-9a51-1f0e7d2c6b44';
const OLD_SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
const NEW_AGENT = '7c1e2d3f-4a5b-4c6d-8e9f-0a1b2c3d4e5f';
const NEW_SECRET = 'fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210';
const OLD_TUNNEL = '0c8f1f4e-6f7b-4a2e-9a1d-2f6e3c1b7a90';
const STRAY_TUNNEL = '1d9f2a5b-7c8d-4e3f-8a2b-3c4d5e6f7a81';
const NEW_TUNNEL = '2e0a3b6c-8d9e-4f4a-9b3c-4d5e6f7a8b92';

function claimAnswer(string $answer): array
{
    return ['status' => 'success', 'data' => $answer];
}

function fail(string $reason): array
{
    return ['status' => 'fail', 'data' => $reason];
}

function exchangeOk(): array
{
    return ['status' => 'success', 'data' => ['secret_key' => NEW_SECRET]];
}

function runDataFor(string $agentId, array $tunnels = []): array
{
    return ['status' => 'success', 'data' => [
        'agent_id' => $agentId,
        'tunnels' => $tunnels,
        'pending' => [],
        'notices' => [],
        'permissions' => ['is_self_managed' => $agentId === NEW_AGENT, 'has_premium' => false, 'account_status' => 'verified'],
    ]];
}

function runTunnel(string $id, int $port, string $display): array
{
    return [
        'id' => $id,
        'name' => 'tunnel-'.$port,
        'display_address' => $display,
        'disabled_reason' => null,
        'port_type' => 'tcp',
        'agent_config' => ['fields' => [
            ['name' => 'local_ip', 'value' => '127.0.0.1'],
            ['name' => 'local_port', 'value' => (string) $port],
        ]],
    ];
}

function isNewKey(Request $request): bool
{
    return $request->header('Authorization') === ['Agent-Key '.NEW_SECRET];
}

/**
 * playit as the panel sees it during a switch: the claim is accepted at once, run data answers
 * per key, and tunnels can be created with the new key.
 */
function fakePlayit(string $create = 'ok', array $oldTunnels = []): void
{
    Http::fake(fn (Request $request) => match (parse_url($request->url(), PHP_URL_PATH)) {
        '/claim/setup' => Http::response(claimAnswer(PlayitClient::CLAIM_ACCEPTED)),
        '/claim/exchange' => Http::response(exchangeOk()),
        '/v1/agents/rundata' => isNewKey($request)
            ? Http::response(runDataFor(NEW_AGENT, $create === 'ok' ? [runTunnel(NEW_TUNNEL, 25565, 'new-addr.joinmc.link')] : []))
            : Http::response(runDataFor(OLD_AGENT, $oldTunnels)),
        '/v1/tunnels/create' => match ($create) {
            'ok' => Http::response(['status' => 'success', 'data' => ['id' => NEW_TUNNEL]]),
            default => Http::response(fail($create)),
        },
        '/tunnels/delete' => isNewKey($request)
            ? Http::response(['status' => 'success', 'data' => null])
            : Http::response(['status' => 'error', 'data' => ['type' => 'auth', 'message' => PlayitClient::READ_ONLY]], 403),
        default => Http::response(['status' => 'error', 'data' => ['type' => 'path-not-found', 'message' => ['path' => 'x']]], 404),
    });
}

function freshNode(): Node
{
    return Node::factory()->withLocation()->create();
}

function serverOn(Node $node, int $port): Server
{
    $allocation = Allocation::factory()->create(['node_id' => $node->id, 'ip' => '0.0.0.0', 'port' => $port]);

    return test()->createServerModel(['node_id' => $node->id, 'allocation_id' => $allocation->id]);
}

function attachOldAgent(Node $node): Node
{
    $node->forceFill(['playit_agent_id' => OLD_AGENT, 'playit_secret' => OLD_SECRET, 'playit_mode' => Node::PLAYIT_READ_ONLY])->save();

    return $node;
}

function remoteAs(Node $node): void
{
    test()->withHeader('Authorization', 'Bearer '.$node->daemon_token_id.'.'.decrypt($node->daemon_token));
}

/** Start a claim, let it be accepted and hand out a switch token; returns the token. */
function readyClaim(Node $node): string
{
    test()->postJson("/api/admin/nodes/{$node->id}/playit/switch")->assertOk();
    test()->postJson("/api/admin/nodes/{$node->id}/playit/switch/check")->assertOk()->assertJsonPath('claim.status', 'ready');

    return test()->postJson("/api/admin/nodes/{$node->id}/playit/switch/token")->assertOk()->json('token');
}

beforeEach(function (): void {
    $this->logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event): void {
        $this->logged[] = $event->message.' '.json_encode($event->context);
    });
});

afterEach(function (): void {
    // The new secret never reaches a log line, an activity entry or a stored error.
    foreach ($this->logged as $line) {
        expect($line)->not->toContain(NEW_SECRET)->not->toContain(OLD_SECRET);
    }

    expect(json_encode(ActivityLog::query()->get()->toArray()))->not->toContain(NEW_SECRET)
        ->and(json_encode(DB::table('server_tunnels')->get()))->not->toContain(NEW_SECRET)
        ->and(json_encode(DB::table('playit_agent_claims')->get()))->not->toContain(NEW_SECRET);
});

test('starting a claim registers a fresh code without an agent key and shows its URL', function (): void {
    Http::fake(['api.playit.gg/claim/setup' => Http::response(claimAnswer(PlayitClient::CLAIM_WAITING_FOR_VISIT))]);
    $node = attachOldAgent(freshNode());

    $response = $this->postJson("/api/admin/nodes/{$node->id}/playit/switch")->assertOk();

    $claim = PlayitAgentClaim::query()->where('node_id', $node->id)->sole();
    expect($claim->code)->toMatch('/^[0-9a-f]{1,20}$/')
        ->and($claim->status)->toBe(PlayitClaimStatus::WaitingForVisit)
        ->and($response->json('claim.claim_url'))->toBe('https://playit.gg/claim/'.$claim->code)
        ->and($response->json('claim.status'))->toBe('waiting_for_visit')
        ->and($response->json('self_managed'))->toBeFalse()
        ->and($response->json('mode'))->toBe(Node::PLAYIT_READ_ONLY)
        ->and($response->json('script.url'))->toEndWith('/scripts/playit-switch-agent.sh')
        ->and($response->json('script.sha256'))->toMatch('/^[0-9a-f]{64}$/');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.playit.gg/claim/setup'
        && $request->data() === ['code' => $claim->code, 'agent_type' => 'self-managed', 'version' => 'playit 2.0.2']
        && ! $request->hasHeader('Authorization'));

    // The node keeps its agent until the switch finishes.
    expect($node->refresh()->playit_agent_id)->toBe(OLD_AGENT)
        ->and(ActivityLog::query()->where('event', 'admin:node.playit-claim')->exists())->toBeTrue();
});

test('the claim is polled until accepted, then exchanged for an encrypted secret and the agent id', function (): void {
    Http::fake([
        'api.playit.gg/claim/setup' => Http::sequence()
            ->push(claimAnswer(PlayitClient::CLAIM_WAITING_FOR_VISIT))
            ->push(claimAnswer(PlayitClient::CLAIM_WAITING_FOR_USER))
            ->push(claimAnswer(PlayitClient::CLAIM_ACCEPTED)),
        'api.playit.gg/claim/exchange' => Http::sequence()
            ->push(fail('NotAccepted'))
            ->push(exchangeOk()),
        'api.playit.gg/v1/agents/rundata' => Http::response(runDataFor(NEW_AGENT)),
    ]);
    $node = attachOldAgent(freshNode());

    $this->postJson("/api/admin/nodes/{$node->id}/playit/switch")->assertOk();
    $this->postJson("/api/admin/nodes/{$node->id}/playit/switch/check")->assertOk()->assertJsonPath('claim.status', 'waiting_for_user');
    $this->postJson("/api/admin/nodes/{$node->id}/playit/switch/check")->assertOk()->assertJsonPath('claim.status', 'accepted');
    $ready = $this->postJson("/api/admin/nodes/{$node->id}/playit/switch/check")->assertOk();

    $claim = PlayitAgentClaim::query()->where('node_id', $node->id)->sole();
    expect($ready->json('claim.status'))->toBe('ready')
        ->and($ready->json('claim.agent_id'))->toBe(NEW_AGENT)
        ->and($ready->json('claim.claim_url'))->toBeNull()
        ->and($ready->getContent())->not->toContain(NEW_SECRET)->not->toContain($claim->code)
        ->and($claim->secret)->toBe(NEW_SECRET)
        ->and(DB::table('playit_agent_claims')->value('secret'))->not->toBe(NEW_SECRET)
        ->and($claim->toArray())->not->toHaveKeys(['secret', 'code', 'token_hash']);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.playit.gg/claim/exchange'
        && $request->data() === ['code' => $claim->code] && ! $request->hasHeader('Authorization'));
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.playit.gg/v1/agents/rundata' && isNewKey($request));
});

test('a rejected or expired claim stops and can be started again', function (): void {
    Http::fake(['api.playit.gg/claim/setup' => Http::sequence()
        ->push(claimAnswer(PlayitClient::CLAIM_WAITING_FOR_VISIT))
        ->push(claimAnswer(PlayitClient::CLAIM_REJECTED))
        ->push(claimAnswer(PlayitClient::CLAIM_WAITING_FOR_VISIT))
        ->push(fail('CodeExpired')),
    ]);
    $node = attachOldAgent(freshNode());

    $this->postJson("/api/admin/nodes/{$node->id}/playit/switch")->assertOk();
    $this->postJson("/api/admin/nodes/{$node->id}/playit/switch/check")->assertOk()->assertJsonPath('claim.status', 'rejected');
    // A finished claim is not polled again.
    $this->postJson("/api/admin/nodes/{$node->id}/playit/switch/check")->assertOk()->assertJsonPath('claim.status', 'rejected');
    Http::assertSentCount(2);

    $this->postJson("/api/admin/nodes/{$node->id}/playit/switch")->assertOk()->assertJsonPath('claim.status', 'waiting_for_visit');
    $this->postJson("/api/admin/nodes/{$node->id}/playit/switch/check")->assertOk()
        ->assertJsonPath('claim.status', 'expired')
        ->assertJsonPath('claim.last_error', 'playit.gg says the claim expired. Start again.');

    $this->postJson("/api/admin/nodes/{$node->id}/playit/switch/token")->assertStatus(400);
});

test('a claim not accepted in time expires without asking playit', function (): void {
    Http::fake(['api.playit.gg/claim/setup' => Http::response(claimAnswer(PlayitClient::CLAIM_WAITING_FOR_VISIT))]);
    $node = attachOldAgent(freshNode());
    $this->postJson("/api/admin/nodes/{$node->id}/playit/switch")->assertOk();

    $this->travel(PlayitAgentSwitchService::CLAIM_MINUTES + 1)->minutes();

    $this->postJson("/api/admin/nodes/{$node->id}/playit/switch/check")->assertOk()->assertJsonPath('claim.status', 'expired');
    Http::assertSentCount(1);
});

test('the secret is kept when reading the agent id fails right after the exchange', function (): void {
    Http::fake([
        'api.playit.gg/claim/setup' => Http::response(claimAnswer(PlayitClient::CLAIM_ACCEPTED)),
        'api.playit.gg/claim/exchange' => Http::response(exchangeOk()),
        'api.playit.gg/v1/agents/rundata' => Http::sequence()
            ->push(['status' => 'error', 'data' => ['type' => 'internal', 'message' => 'x']], 500)
            ->push(runDataFor(NEW_AGENT)),
    ]);
    $node = attachOldAgent(freshNode());
    $this->postJson("/api/admin/nodes/{$node->id}/playit/switch")->assertOk();

    $this->postJson("/api/admin/nodes/{$node->id}/playit/switch/check")->assertOk()
        ->assertJsonPath('claim.status', 'accepted')
        ->assertJsonPath('claim.last_error', 'playit.gg returned an error: internal.');
    $this->postJson("/api/admin/nodes/{$node->id}/playit/switch/check")->assertOk()->assertJsonPath('claim.status', 'ready');

    // playit hands a secret out once: the exchange is not repeated.
    Http::assertSentCount(4);
    expect(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/claim/exchange'))->count())->toBe(1);
});

test('the switch token works once, only for its node, only with the daemon token and only for 15 minutes', function (): void {
    fakePlayit();
    $node = attachOldAgent(freshNode());
    $other = Node::factory()->withLocation()->create();

    $response = $this->postJson("/api/admin/nodes/{$node->id}/playit/switch/token");
    expect($response->status())->toBe(400);

    $token = readyClaim($node);
    expect($token)->toMatch('/^[0-9a-f]{48}$/')
        ->and($this->getJson("/api/admin/nodes/{$node->id}/playit/switch")->getContent())->not->toContain($token)
        ->and(PlayitAgentClaim::query()->where('node_id', $node->id)->value('token_hash'))->toBe(hash('sha256', $token));

    // Without the node's daemon token.
    $this->flushHeaders();
    $this->postJson('/api/remote/playit/agent-secret', ['token' => $token])->assertUnauthorized();
    // Another node's daemon token.
    remoteAs($other);
    $this->postJson('/api/remote/playit/agent-secret', ['token' => $token])->assertNotFound();
    // A wrong token.
    remoteAs($node);
    $this->postJson('/api/remote/playit/agent-secret', ['token' => str_repeat('a', 48)])->assertNotFound();

    $fetched = $this->postJson('/api/remote/playit/agent-secret', ['token' => $token])->assertOk();
    expect($fetched->json())->toBe(['secret_key' => NEW_SECRET, 'agent_id' => NEW_AGENT])
        ->and($fetched->headers->get('Cache-Control'))->toContain('no-store');

    // Used.
    $this->postJson('/api/remote/playit/agent-secret', ['token' => $token])->assertNotFound();

    // A new token expires after 15 minutes.
    $this->flushHeaders();
    $this->actingAs($this->getAdminUser());
    $second = $this->postJson("/api/admin/nodes/{$node->id}/playit/switch/token")->assertOk()->json('token');
    $this->travel(PlayitAgentSwitchService::TOKEN_MINUTES + 1)->minutes();
    remoteAs($node);
    $this->postJson('/api/remote/playit/agent-secret', ['token' => $second])->assertNotFound();

    expect(ActivityLog::query()->where('event', 'admin:node.playit-secret-fetched')->count())->toBe(1)
        ->and(ActivityLog::query()->where('event', 'admin:node.playit-token')->count())->toBe(2);
});

test('the script reporting a switch moves the node to the new agent and creates tunnels for every server', function (): void {
    $node = freshNode();
    $linked = serverOn($node, 25565);
    $without = serverOn($node, 25566);
    attachOldAgent($node);
    ServerTunnel::query()->create([
        'server_id' => $linked->id, 'allocation_id' => $linked->allocation_id, 'playit_tunnel_id' => OLD_TUNNEL,
        'address' => 'old-addr.joinmc.link', 'status' => TunnelStatus::Active, 'imported_at' => now(),
    ]);
    fakePlayit('ok', [runTunnel(OLD_TUNNEL, 25565, 'old-addr.joinmc.link'), runTunnel(STRAY_TUNNEL, 30000, 'stray.joinmc.link')]);

    $token = readyClaim($node);
    $this->flushHeaders();
    remoteAs($node);
    $this->postJson('/api/remote/playit/agent-secret', ['token' => $token])->assertOk();
    $this->postJson('/api/remote/playit/agent-switch', ['token' => $token, 'outcome' => 'switched'])->assertOk()->assertJsonPath('success', true);

    $node->refresh();
    expect($node->playit_agent_id)->toBe(NEW_AGENT)
        ->and($node->playit_secret)->toBe(NEW_SECRET)
        ->and($node->isPlayitSelfManaged())->toBeTrue()
        ->and($node->playit_previous_agent_id)->toBe(OLD_AGENT)
        ->and($node->playit_previous_secret)->toBe(OLD_SECRET)
        ->and($node->playit_previous_mode)->toBe(Node::PLAYIT_READ_ONLY)
        ->and($node->playit_switched_at)->not->toBeNull()
        ->and(PlayitAgentClaim::query()->where('node_id', $node->id)->value('status'))->toBe(PlayitClaimStatus::Switched);

    // Both servers got a tunnel on the new agent, made with the new key.
    foreach ([$linked, $without] as $server) {
        $tunnel = ServerTunnel::query()->where('server_id', $server->id)->sole();
        expect($tunnel->playit_tunnel_id)->toBe(NEW_TUNNEL)->and($tunnel->imported_at)->toBeNull();
    }

    expect(ServerTunnel::query()->where('server_id', $linked->id)->value('address'))->toBe('new-addr.joinmc.link');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.playit.gg/v1/tunnels/create'
        && isNewKey($request) && $request->data()['origin']['data']['agent_id'] === NEW_AGENT);

    // The old agent's tunnels are listed for removal: the linked one with its server, and the stray one.
    $old = PlayitOldTunnel::query()->where('node_id', $node->id)->orderBy('id')->get();
    expect($old->pluck('playit_tunnel_id')->all())->toBe([OLD_TUNNEL, STRAY_TUNNEL])
        ->and($old->first()->server_id)->toBe($linked->id)
        ->and($old->first()->address)->toBe('old-addr.joinmc.link')
        ->and($old->last()->server_id)->toBeNull()
        ->and($old->last()->local_port)->toBe(30000)
        ->and($old->every(fn (PlayitOldTunnel $tunnel): bool => $tunnel->agent_id === OLD_AGENT))->toBeTrue();

    $this->flushHeaders();
    $this->actingAs($this->getAdminUser());
    $state = $this->getJson("/api/admin/nodes/{$node->id}/playit/switch")->assertOk();
    expect($state->json('self_managed'))->toBeTrue()
        ->and($state->json('can_undo'))->toBeTrue()
        ->and($state->json('switched_at'))->not->toBeNull()
        ->and($state->json('claim.report'))->toBe('switched')
        ->and($state->json('old_tunnels'))->toHaveCount(2)
        ->and($state->json('old_tunnels.0.server_name'))->toBe($linked->name)
        ->and($state->getContent())->not->toContain(NEW_SECRET)->not->toContain(OLD_SECRET)
        ->and(ActivityLog::query()->where('event', 'admin:node.playit-switch-reported')->exists())->toBeTrue();

    // A second report changes nothing.
    remoteAs($node);
    $this->postJson('/api/remote/playit/agent-switch', ['token' => $token, 'outcome' => 'switched'])->assertOk();
    expect(PlayitOldTunnel::query()->where('node_id', $node->id)->count())->toBe(2)
        ->and($node->refresh()->playit_previous_agent_id)->toBe(OLD_AGENT);
});

test('a report needs a token whose secret was fetched recently', function (): void {
    fakePlayit();
    $node = attachOldAgent(freshNode());
    $token = readyClaim($node);
    $this->flushHeaders();
    remoteAs($node);

    // Not fetched yet.
    $this->postJson('/api/remote/playit/agent-switch', ['token' => $token, 'outcome' => 'switched'])->assertNotFound();
    $this->postJson('/api/remote/playit/agent-switch', ['token' => $token, 'outcome' => 'other'])->assertUnprocessable();
    $this->postJson('/api/remote/playit/agent-secret', ['token' => $token])->assertOk();

    $this->travel(PlayitAgentSwitchService::REPORT_HOURS + 1)->hours();
    $this->postJson('/api/remote/playit/agent-switch', ['token' => $token, 'outcome' => 'switched'])->assertNotFound();
    $this->postJson('/api/remote/playit/agent-status', ['token' => $token])->assertNotFound();

    expect($node->refresh()->playit_agent_id)->toBe(OLD_AGENT);
});

test('until the agent runs with the new key, tunnels wait for it and the status check says so', function (): void {
    $node = freshNode();
    serverOn($node, 25565);
    attachOldAgent($node);
    fakePlayit(PlayitClient::AGENT_VERSION_TOO_OLD);

    $token = readyClaim($node);
    $this->flushHeaders();
    remoteAs($node);
    $this->postJson('/api/remote/playit/agent-secret', ['token' => $token])->assertOk();
    $this->postJson('/api/remote/playit/agent-switch', ['token' => $token, 'outcome' => 'switched'])->assertOk();

    $tunnel = ServerTunnel::query()->sole();
    expect($tunnel->status)->toBe(TunnelStatus::Failed)
        ->and($tunnel->last_error)->toBe(TunnelService::AGENT_NOT_RUNNING)
        ->and($node->refresh()->isPlayitSelfManaged())->toBeTrue();

    $creates = fn (): int => Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/v1/tunnels/create'))->count();
    $before = $creates();

    $status = $this->postJson('/api/remote/playit/agent-status', ['token' => $token])->assertOk();
    expect($status->json())->toBe([
        'tunnels' => 1,
        'created' => 0,
        'active' => 0,
        'waiting_for_agent' => 1,
        'last_error' => TunnelService::AGENT_NOT_RUNNING,
    ])->and($creates())->toBe($before + 1);

    // The probe tries creating at most every PROBE_SECONDS.
    $this->postJson('/api/remote/playit/agent-status', ['token' => $token])->assertOk();
    expect($creates())->toBe($before + 1);
});

test('the status check reports created tunnels once playit accepts them', function (): void {
    $node = freshNode();
    serverOn($node, 25565);
    attachOldAgent($node);
    fakePlayit();

    $token = readyClaim($node);
    $this->flushHeaders();
    remoteAs($node);
    $this->postJson('/api/remote/playit/agent-secret', ['token' => $token])->assertOk();
    $this->postJson('/api/remote/playit/agent-switch', ['token' => $token, 'outcome' => 'switched'])->assertOk();

    $this->postJson('/api/remote/playit/agent-status', ['token' => $token])->assertOk()
        ->assertJsonPath('created', 1)
        ->assertJsonPath('active', 1)
        ->assertJsonPath('waiting_for_agent', 0);
});

test('a rollback report puts the old agent and its tunnels back and deletes the new ones', function (): void {
    $node = freshNode();
    $server = serverOn($node, 25565);
    attachOldAgent($node);
    ServerTunnel::query()->create([
        'server_id' => $server->id, 'allocation_id' => $server->allocation_id, 'playit_tunnel_id' => OLD_TUNNEL,
        'address' => 'old-addr.joinmc.link', 'status' => TunnelStatus::Active, 'imported_at' => now(),
    ]);
    fakePlayit();

    $token = readyClaim($node);
    $this->flushHeaders();
    remoteAs($node);
    $this->postJson('/api/remote/playit/agent-secret', ['token' => $token])->assertOk();
    $this->postJson('/api/remote/playit/agent-switch', ['token' => $token, 'outcome' => 'switched'])->assertOk();
    expect(ServerTunnel::query()->sole()->playit_tunnel_id)->toBe(NEW_TUNNEL);

    $this->postJson('/api/remote/playit/agent-switch', ['token' => $token, 'outcome' => 'rolled_back'])->assertOk();

    $node->refresh();
    $tunnel = ServerTunnel::query()->sole();
    expect($node->playit_agent_id)->toBe(OLD_AGENT)
        ->and($node->playit_secret)->toBe(OLD_SECRET)
        ->and($node->playit_mode)->toBe(Node::PLAYIT_READ_ONLY)
        ->and($node->playit_previous_agent_id)->toBeNull()
        ->and($node->playit_switched_at)->toBeNull()
        ->and($tunnel->playit_tunnel_id)->toBe(OLD_TUNNEL)
        ->and($tunnel->address)->toBe('old-addr.joinmc.link')
        ->and($tunnel->status)->toBe(TunnelStatus::Active)
        ->and($tunnel->imported_at)->not->toBeNull()
        ->and(PlayitOldTunnel::query()->count())->toBe(0)
        ->and(PlayitAgentClaim::query()->value('status'))->toBe(PlayitClaimStatus::Ready);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.playit.gg/tunnels/delete'
        && isNewKey($request) && $request->data() === ['tunnel_id' => NEW_TUNNEL]);
});

test('an administrator can finish and undo the switch by hand, each behind a session', function (): void {
    $node = freshNode();
    serverOn($node, 25565);
    attachOldAgent($node);
    fakePlayit();
    readyClaim($node);

    $this->postJson("/api/admin/nodes/{$node->id}/playit/switch/undo")->assertStatus(400);

    $this->postJson("/api/admin/nodes/{$node->id}/playit/switch/finish")->assertOk()
        ->assertJsonPath('self_managed', true)
        ->assertJsonPath('claim.status', 'switched');
    expect($node->refresh()->playit_agent_id)->toBe(NEW_AGENT)
        ->and(ActivityLog::query()->where('event', 'admin:node.playit-switch')->exists())->toBeTrue();

    $this->postJson("/api/admin/nodes/{$node->id}/playit/switch/undo")->assertOk()
        ->assertJsonPath('self_managed', false)
        ->assertJsonPath('can_undo', false)
        ->assertJsonPath('mode', Node::PLAYIT_READ_ONLY);
    expect($node->refresh()->playit_agent_id)->toBe(OLD_AGENT)
        ->and(ServerTunnel::query()->sole()->last_error)->toBe(TunnelService::READ_ONLY)
        ->and(ActivityLog::query()->where('event', 'admin:node.playit-undo')->exists())->toBeTrue();
});

test('old tunnels are removed with the old key, or left for the dashboard when it is read-only', function (): void {
    $node = freshNode();
    serverOn($node, 25565);
    attachOldAgent($node);
    fakePlayit('ok', [runTunnel(OLD_TUNNEL, 25565, 'old-addr.joinmc.link')]);
    readyClaim($node);
    $this->postJson("/api/admin/nodes/{$node->id}/playit/switch/finish")->assertOk();

    // The old key is read-only (fakePlayit refuses deletes with it).
    $this->postJson("/api/admin/nodes/{$node->id}/playit/old-tunnels/remove")->assertOk()
        ->assertJsonPath('removed', 0)
        ->assertJsonPath('remaining', 1)
        ->assertJsonPath('manual', true);

    $this->postJson("/api/admin/nodes/{$node->id}/playit/old-tunnels/dismiss")->assertOk()
        ->assertJsonPath('old_tunnels', [])
        ->assertJsonPath('old_tunnels_removed', 1);
});

test('old tunnels are deleted on playit when the old key may delete them', function (): void {
    $node = freshNode();
    attachOldAgent($node);
    Http::fake(fn (Request $request) => match (parse_url($request->url(), PHP_URL_PATH)) {
        '/claim/setup' => Http::response(claimAnswer(PlayitClient::CLAIM_ACCEPTED)),
        '/claim/exchange' => Http::response(exchangeOk()),
        '/v1/agents/rundata' => isNewKey($request) ? Http::response(runDataFor(NEW_AGENT)) : Http::response(runDataFor(OLD_AGENT, [runTunnel(OLD_TUNNEL, 25565, 'a.joinmc.link')])),
        '/tunnels/delete' => Http::response(['status' => 'success', 'data' => null]),
        default => Http::response(fail('Unexpected')),
    });
    readyClaim($node);
    $this->postJson("/api/admin/nodes/{$node->id}/playit/switch/finish")->assertOk();

    $this->postJson("/api/admin/nodes/{$node->id}/playit/old-tunnels/remove")->assertOk()
        ->assertJsonPath('removed', 1)
        ->assertJsonPath('remaining', 0)
        ->assertJsonPath('manual', false);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.playit.gg/tunnels/delete'
        && $request->header('Authorization') === ['Agent-Key '.OLD_SECRET] && $request->data() === ['tunnel_id' => OLD_TUNNEL]);
    expect(PlayitOldTunnel::query()->sole()->removed_at)->not->toBeNull();
});

test('the scheduler gives servers without a tunnel one on a self-managed node', function (): void {
    $node = freshNode();
    $server = serverOn($node, 25565);
    $node->forceFill(['playit_agent_id' => NEW_AGENT, 'playit_secret' => NEW_SECRET, 'playit_mode' => Node::PLAYIT_SELF_MANAGED])->save();
    // The agent has no tunnel yet when the sync links dashboard tunnels, so none is linked.
    $created = false;
    Http::fake(function (Request $request) use (&$created) {
        if (str_ends_with($request->url(), '/v1/tunnels/create')) {
            $created = true;

            return Http::response(['status' => 'success', 'data' => ['id' => NEW_TUNNEL]]);
        }

        return Http::response(runDataFor(NEW_AGENT, $created ? [runTunnel(NEW_TUNNEL, 25565, 'new-addr.joinmc.link')] : []));
    });

    $this->artisan('p:tunnels:sync')->assertSuccessful();

    $tunnel = ServerTunnel::query()->where('server_id', $server->id)->sole();
    expect($created)->toBeTrue()
        ->and($tunnel->playit_tunnel_id)->toBe(NEW_TUNNEL)
        ->and($tunnel->imported_at)->toBeNull()
        ->and($tunnel->status)->toBe(TunnelStatus::Active);
});

test('a tunnel to plain ports is created with the agent api_client ReqTunnelsCreate body', function (): void {
    Http::fake(['api.playit.gg/tunnels/create' => Http::response(['status' => 'success', 'data' => ['id' => NEW_TUNNEL]])]);

    $id = resolve(PlayitClient::class)->createTunnel(NEW_SECRET, NEW_AGENT, [
        'name' => 'survival-voice', 'tunnel_type' => null, 'port_type' => 'udp', 'local_ip' => '127.0.0.1', 'local_port' => 24454,
    ]);

    expect($id)->toBe(NEW_TUNNEL);
    Http::assertSentCount(1);
    $sent = Http::recorded()->sole()[0];
    expect($sent->url())->toBe('https://api.playit.gg/tunnels/create')
        ->and($sent->body())->toBe('{"name":"survival-voice","tunnel_type":null,"port_type":"udp","port_count":1,"origin":{"type":"agent","data":{"agent_id":"'.NEW_AGENT.'","local_ip":"127.0.0.1","local_port":24454}},"enabled":true,"alloc":null,"firewall_id":null,"proxy_protocol":null}');
});

test('a game tunnel keeps the v1 tunnel-type body', function (): void {
    Http::fake(['api.playit.gg/v1/tunnels/create' => Http::response(['status' => 'success', 'data' => ['id' => NEW_TUNNEL]])]);

    resolve(PlayitClient::class)->createTunnel(NEW_SECRET, NEW_AGENT, [
        'name' => 'survival', 'tunnel_type' => 'minecraft-java', 'port_type' => 'tcp', 'local_ip' => '127.0.0.1', 'local_port' => 25565,
    ]);

    $sent = Http::recorded()->sole()[0];
    expect($sent->url())->toBe('https://api.playit.gg/v1/tunnels/create')
        ->and($sent->data()['protocol'])->toBe(['type' => 'tunnel-type', 'details' => 'minecraft-java']);
});

test('a plain-port body playit refuses as malformed is sent again in the v1 agent shape', function (): void {
    Http::fake([
        'api.playit.gg/tunnels/create' => Http::response(['status' => 'error', 'data' => ['type' => 'validation', 'message' => 'failed to parse body']], 400),
        'api.playit.gg/v1/tunnels/create' => Http::response(['status' => 'success', 'data' => ['id' => NEW_TUNNEL]]),
    ]);

    $id = resolve(PlayitClient::class)->createTunnel(NEW_SECRET, NEW_AGENT, [
        'name' => 'survival', 'tunnel_type' => null, 'port_type' => 'udp', 'local_ip' => '127.0.0.1', 'local_port' => 19132,
    ]);

    expect($id)->toBe(NEW_TUNNEL);
    $second = Http::recorded()->last()[0];
    expect($second->data())->toMatchArray([
        'ports' => ['type' => 'custom-udp', 'details' => 1],
        'enabled' => true,
        'alloc' => null,
        'name' => 'survival',
        'firewall_id' => null,
    ])->and($second->data()['origin']['data']['config']['fields'][1])->toBe(['name' => 'local_port', 'value' => '19132']);
});

test('the switch endpoints need a root administrator session', function (): void {
    fakePlayit();
    $node = attachOldAgent(freshNode());

    $this->actingAsNonAdmin();
    $this->getJson("/api/admin/nodes/{$node->id}/playit/switch")->assertForbidden();
    $this->postJson("/api/admin/nodes/{$node->id}/playit/switch")->assertForbidden();

    $this->app['auth']->forgetGuards();
    $admin = $this->getAdminUser();
    $key = ApiKey::factory()->create(['user_id' => $admin->id, 'key_type' => ApiKey::TYPE_ACCOUNT]);
    $this->withHeader('Accept', 'application/vnd.pterodactyl.v1+json')
        ->withHeader('Authorization', 'Bearer '.$key->identifier.decrypt($key->token));
    $this->postJson("/api/admin/nodes/{$node->id}/playit/switch")->assertForbidden();
    // The state (with the claim URL while it waits) is session-only too.
    $this->getJson("/api/admin/nodes/{$node->id}/playit/switch")->assertForbidden();
    $this->postJson("/api/admin/nodes/{$node->id}/playit/switch/reconcile")->assertForbidden();

    expect(PlayitAgentClaim::query()->exists())->toBeFalse();
    Http::assertNothingSent();
});

test('the switch script is served without signing in', function (): void {
    $this->app['auth']->forgetGuards();
    $this->flushSession();

    $response = $this->get('/scripts/playit-switch-agent.sh')->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/x-shellscript')
        ->and(file_get_contents(PlayitAgentSwitchService::scriptFile()))->toContain('--rollback');
});

test('a claimed agent that is not self-managed is never used', function (): void {
    Http::fake([
        'api.playit.gg/claim/setup' => Http::response(claimAnswer(PlayitClient::CLAIM_ACCEPTED)),
        'api.playit.gg/claim/exchange' => Http::response(exchangeOk()),
        'api.playit.gg/v1/agents/rundata' => Http::response(runDataFor(OLD_AGENT)),
    ]);
    $node = attachOldAgent(freshNode());

    $this->postJson("/api/admin/nodes/{$node->id}/playit/switch")->assertOk();
    $this->postJson("/api/admin/nodes/{$node->id}/playit/switch/check")
        ->assertOk()
        ->assertJsonPath('claim.status', 'rejected')
        ->assertJsonPath('claim.agent_id', null)
        ->assertJsonPath('claim.last_error', PlayitAgentSwitchService::NOT_SELF_MANAGED);

    expect(PlayitAgentClaim::query()->sole()->secret)->toBeNull();
    $this->postJson("/api/admin/nodes/{$node->id}/playit/switch/token")->assertStatus(400);
});

test('check status applies a switch report the panel never acted on, and says whether the agent is online', function (): void {
    $node = freshNode();
    serverOn($node, 25565);
    attachOldAgent($node);
    fakePlayit();

    $token = readyClaim($node);
    $this->flushHeaders();
    remoteAs($node);
    $this->postJson('/api/remote/playit/agent-secret', ['token' => $token])->assertOk();
    // The report reached the panel, but the panel did not finish (as if its answer was lost first).
    PlayitAgentClaim::query()->where('node_id', $node->id)->update(['report' => PlayitAgentClaim::REPORT_SWITCHED, 'reported_at' => now()]);
    $this->flushHeaders();

    $this->postJson("/api/admin/nodes/{$node->id}/playit/switch/reconcile")
        ->assertOk()
        ->assertJsonPath('reconcile.action', 'finished')
        ->assertJsonPath('reconcile.agent_online', true)
        ->assertJsonPath('self_managed', true);
    expect($node->refresh()->playit_agent_id)->toBe(NEW_AGENT);
    $this->assertActivityLogged('admin:node.playit-reconcile');

    // Nothing more to fix the second time.
    $this->postJson("/api/admin/nodes/{$node->id}/playit/switch/reconcile")->assertOk()->assertJsonPath('reconcile.action', 'none');
});

test('check status undoes a switch the node rolled back', function (): void {
    $node = freshNode();
    serverOn($node, 25565);
    attachOldAgent($node);
    fakePlayit();

    $token = readyClaim($node);
    $this->flushHeaders();
    remoteAs($node);
    $this->postJson('/api/remote/playit/agent-secret', ['token' => $token])->assertOk();
    $this->postJson('/api/remote/playit/agent-switch', ['token' => $token, 'outcome' => 'switched'])->assertOk();
    PlayitAgentClaim::query()->where('node_id', $node->id)->update(['report' => PlayitAgentClaim::REPORT_ROLLED_BACK]);
    $this->flushHeaders();

    $this->postJson("/api/admin/nodes/{$node->id}/playit/switch/reconcile")
        ->assertOk()
        ->assertJsonPath('reconcile.action', 'undone')
        ->assertJsonPath('self_managed', false);
    expect($node->refresh()->playit_agent_id)->toBe(OLD_AGENT);
});

test('the old agent key is dropped 30 days after the switch', function (): void {
    $node = attachOldAgent(freshNode());
    $node->forceFill(['playit_previous_secret' => OLD_SECRET, 'playit_previous_agent_id' => OLD_AGENT, 'playit_switched_at' => now()->subDays(29)])->save();

    $this->artisan('p:tunnels:prune-old-secrets')->assertSuccessful();
    expect($node->refresh()->playit_previous_secret)->toBe(OLD_SECRET);

    $node->forceFill(['playit_switched_at' => now()->subDays(31)])->save();
    $this->artisan('p:tunnels:prune-old-secrets')->assertSuccessful();
    expect($node->refresh()->playit_previous_secret)->toBeNull();
});
