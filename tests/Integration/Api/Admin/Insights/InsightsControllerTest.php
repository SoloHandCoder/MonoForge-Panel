<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Admin\Insights\InsightsControllerTest;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Tests\Integration\Api\Admin\AdminApiIntegrationTestCase;

uses(AdminApiIntegrationTestCase::class);

/**
 * One reachable node with a running and a stopped server, and one node Wings never answers for.
 *
 * A third node's token cannot be decrypted, and on the reachable node one server's Wings
 * reading has no disk and another server never answers.
 *
 * @return array{online: Node, offline: Node, broken: Node, busy: Server, idle: Server, partial: Server, silent: Server, stranded: Server}
 */
function insightsWorld(AdminApiIntegrationTestCase $test): array
{
    $location = Location::factory()->create();
    $online = Node::factory()->create(['location_id' => $location->id, 'name' => 'Alpha', 'fqdn' => 'alpha-node.example.test', 'memory' => 4096, 'disk' => 10240]);
    $offline = Node::factory()->create(['location_id' => $location->id, 'name' => 'Bravo', 'fqdn' => 'bravo-node.example.test']);
    $broken = Node::factory()->create(['location_id' => $location->id, 'name' => 'Charlie', 'fqdn' => 'charlie-node.example.test']);
    Node::query()->whereKey($broken->id)->update(['daemon_token' => 'not-an-encrypted-token']);

    $busy = $test->createServerModel(['node_id' => $online->id, 'name' => 'Busy', 'cpu' => 400, 'memory' => 2048, 'disk' => 5120]);
    $idle = $test->createServerModel(['node_id' => $online->id, 'name' => 'Idle', 'cpu' => 0, 'memory' => 1024, 'disk' => 0]);
    $partial = $test->createServerModel(['node_id' => $online->id, 'name' => 'Partial']);
    $silent = $test->createServerModel(['node_id' => $online->id, 'name' => 'Silent']);
    $stranded = $test->createServerModel(['node_id' => $offline->id, 'name' => 'Stranded']);

    Http::fake(function (HttpRequest $request) use ($busy, $idle, $partial): PromiseInterface {
        $host = parse_url($request->url(), PHP_URL_HOST);
        $path = parse_url($request->url(), PHP_URL_PATH);

        if ($host !== 'alpha-node.example.test') {
            return (Http::failedConnection())($request);
        }

        return match ($path) {
            '/api/system' => Http::response(['architecture' => 'amd64', 'cpu_count' => 21, 'kernel_version' => '6.8.0', 'os' => 'linux', 'version' => '1.0.0']),
            "/api/servers/{$busy->uuid}" => Http::response(['state' => 'running', 'utilization' => ['cpu_absolute' => 250.04, 'memory_bytes' => 1_073_741_824, 'disk_bytes' => 3_221_225_472]]),
            "/api/servers/{$idle->uuid}" => Http::response(['state' => 'offline', 'utilization' => ['cpu_absolute' => 0, 'memory_bytes' => 0, 'disk_bytes' => 1_048_576]]),
            "/api/servers/{$partial->uuid}" => Http::response(['state' => 'starting', 'utilization' => ['cpu_absolute' => 5]]),
            default => Http::response(['error' => 'not found'], 404),
        };
    });

    return ['online' => $online, 'offline' => $offline, 'broken' => $broken, 'busy' => $busy, 'idle' => $idle, 'partial' => $partial, 'silent' => $silent, 'stranded' => $stranded];
}

/** Every key anywhere in a decoded JSON document. */
function jsonKeys(mixed $json): array
{
    return collect(Arr::dot(is_array($json) ? $json : []))->keys()
        ->flatMap(fn (string $key): array => explode('.', $key))
        ->unique()->values()->all();
}

test('live usage maps Wings readings and reports an unreachable node as offline', function (): void {
    $world = insightsWorld($this);
    $onNode = [$world['busy'], $world['idle'], $world['partial'], $world['silent']];

    $response = $this->getJson(route('api.admin.insights.usage'))->assertOk();

    $response->assertJsonPath('nodes.0.name', 'Alpha');
    $response->assertJsonPath('nodes.0.status', 'online');
    $response->assertJsonPath('nodes.0.cpu.used', 255);
    $response->assertJsonPath('nodes.0.cpu.capacity', 2100);
    $response->assertJsonPath('nodes.0.memory.used_bytes', 1_073_741_824);
    $response->assertJsonPath('nodes.0.memory.allocated_bytes', array_sum(array_map(fn (Server $server): int => $server->memory, $onNode)) * 1_048_576);
    $response->assertJsonPath('nodes.0.memory.capacity_bytes', 4096 * 1_048_576);
    $response->assertJsonPath('nodes.0.disk.used_bytes', 3_221_225_472 + 1_048_576);
    $response->assertJsonPath('nodes.0.servers_online', 1);
    $response->assertJsonPath('nodes.0.servers_total', 4);
    $response->assertJsonPath('nodes.0.servers.0.id', $world['busy']->id);
    $response->assertJsonPath('nodes.0.servers.0.state', 'running');
    $response->assertJsonPath('nodes.0.servers.0.cpu', 250);
    $response->assertJsonPath('nodes.0.servers.0.cpu_limit', 400);
    $response->assertJsonPath('nodes.0.servers.1.cpu_limit', null);
    $response->assertJsonPath('nodes.0.servers.1.disk_limit_bytes', null);
    // Wings left memory and disk out of this reading: unknown, not zero.
    $response->assertJsonPath('nodes.0.servers.2.state', 'starting');
    $response->assertJsonPath('nodes.0.servers.2.cpu', 5);
    $response->assertJsonPath('nodes.0.servers.2.memory_bytes', null);
    $response->assertJsonPath('nodes.0.servers.2.disk_bytes', null);
    // Wings never answered for this server; the node stays online.
    $response->assertJsonPath('nodes.0.servers.3.id', $world['silent']->id);
    $response->assertJsonPath('nodes.0.servers.3.state', 'unknown');
    $response->assertJsonPath('nodes.0.servers.3.cpu', null);
    $response->assertJsonPath('nodes.0.servers.3.disk_bytes', null);

    $response->assertJsonPath('nodes.1.name', 'Bravo');
    $response->assertJsonPath('nodes.1.status', 'offline');
    $response->assertJsonPath('nodes.1.cpu.capacity', null);
    $response->assertJsonPath('nodes.1.disk.used_bytes', null);
    $response->assertJsonPath('nodes.1.servers.0.id', $world['stranded']->id);
    $response->assertJsonPath('nodes.1.servers.0.state', 'offline');

    // A node whose token cannot be decrypted is offline rather than an error.
    $response->assertJsonPath('nodes.2.name', 'Charlie');
    $response->assertJsonPath('nodes.2.status', 'offline');
});

test('live usage reuses a recent reading instead of asking Wings again', function (): void {
    insightsWorld($this);

    $this->getJson(route('api.admin.insights.usage'))->assertOk();
    $sent = Http::recorded()->count();
    $this->getJson(route('api.admin.insights.storage'))->assertOk();
    $this->getJson(route('api.admin.insights.usage'))->assertOk();

    expect($sent)->toBeGreaterThanOrEqual(5)
        ->and(Http::recorded()->count())->toBe($sent);
});

test('storage lists the largest servers per node and the panel itself', function (): void {
    $world = insightsWorld($this);

    $response = $this->getJson(route('api.admin.insights.storage'))->assertOk();

    $response->assertJsonPath('nodes.0.used_bytes', 3_221_225_472 + 1_048_576);
    $response->assertJsonPath('nodes.0.allocated_bytes', ($world['busy']->disk + $world['idle']->disk + $world['partial']->disk + $world['silent']->disk) * 1_048_576);
    $response->assertJsonPath('nodes.0.capacity_bytes', 10240 * 1_048_576);
    $response->assertJsonPath('nodes.0.servers.0.id', $world['busy']->id);
    $response->assertJsonPath('nodes.0.servers.0.used_bytes', 3_221_225_472);
    $response->assertJsonPath('nodes.0.servers.0.limit_bytes', 5120 * 1_048_576);
    $response->assertJsonPath('nodes.0.servers.1.id', $world['idle']->id);
    $response->assertJsonPath('nodes.0.servers.2.used_bytes', null);
    $response->assertJsonPath('nodes.0.servers.3.used_bytes', null);
    $response->assertJsonPath('nodes.1.status', 'offline');
    $response->assertJsonPath('nodes.1.used_bytes', null);
    $response->assertJsonPath('nodes.1.servers.0.used_bytes', null);

    expect($response->json('website.database_bytes'))->toBeInt()->toBeGreaterThan(0)
        ->and($response->json('website.storage_bytes'))->toBeInt()
        ->and($response->json('website.storage_approximate'))->toBeBool()
        ->and($response->json('website.local_backups_count'))->toBe(0);
});

test('insights never expose node addresses', function (string $routeName): void {
    $world = insightsWorld($this);

    $response = $this->getJson(route($routeName))->assertOk();

    expect(jsonKeys($response->json()))->not->toContain('fqdn', 'ip', 'ip_address', 'scheme', 'daemon_listen', 'uuid')
        ->and($response->getContent())->not->toContain($world['online']->fqdn, $world['offline']->fqdn, $world['broken']->fqdn);
})->with(['api.admin.insights.usage', 'api.admin.insights.storage']);

test('insights are for administrators only', function (string $routeName): void {
    $this->actingAsNonAdmin();

    $this->assertAccessDeniedJson($this->getJson(route($routeName)));
})->with(['api.admin.insights.usage', 'api.admin.insights.storage']);
