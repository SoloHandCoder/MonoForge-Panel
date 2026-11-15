<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Client\ServerResourcesControllerTest;

use ArrayObject;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Subuser;
use Pterodactyl\Models\User;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

uses(ClientApiIntegrationTestCase::class);

/**
 * Fakes Wings for every node: a node whose fqdn is listed in $down refuses connections,
 * the others report $details for each server. Returns the server uuids Wings was asked for.
 *
 * @param  list<string>  $down
 * @param  array<string, mixed>  $details
 * @return ArrayObject<int, string>
 */
function fakeWings(array $details = ['state' => 'running'], array $down = []): ArrayObject
{
    $asked = new ArrayObject;
    Http::fake(function (Request $request) use ($details, $down, $asked): ?PromiseInterface {
        $host = parse_url($request->url(), PHP_URL_HOST);
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        if (! preg_match('#^/api/servers/([^/]+)$#', $path, $matches)) {
            return null;
        }

        $asked[] = $matches[1];

        return in_array($host, $down, true) ? Http::failedConnection()($request) : Http::response($details);
    });

    return $asked;
}

function resourcesUrl(string ...$identifiers): string
{
    return '/api/client/resources?servers='.implode(',', $identifiers);
}

test('resources of many servers are returned keyed by the identifier they were requested with', function (): void {
    $asked = fakeWings(['state' => 'running', 'utilization' => ['memory_bytes' => 1048576, 'cpu_absolute' => 12.5, 'disk_bytes' => 5, 'network' => ['rx_bytes' => 1, 'tx_bytes' => 2], 'uptime' => 30]]);
    [$user, $first] = $this->generateTestAccount();
    $second = $this->createServerModel(['user_id' => $user->id, 'node_id' => $first->node_id]);

    $response = $this->actingAs($user)->getJson(resourcesUrl($first->uuid, $second->uuidShort));

    $response->assertOk()
        ->assertJsonPath('object', 'server_resources')
        ->assertJsonPath("data.{$first->uuid}.object", 'stats')
        ->assertJsonPath("data.{$first->uuid}.attributes.current_state", 'running')
        ->assertJsonPath("data.{$first->uuid}.attributes.resources.memory_bytes", 1048576)
        ->assertJsonPath("data.{$first->uuid}.attributes.resources.cpu_absolute", 12.5)
        ->assertJsonPath("data.{$second->uuidShort}.object", 'stats');
    expect($asked->getArrayCopy())->toEqualCanonicalizing([$first->uuid, $second->uuid]);
});

test('the batch entry matches what the single resources endpoint returns', function (): void {
    fakeWings(['state' => 'starting', 'utilization' => ['memory_bytes' => 7, 'disk_bytes' => 9]]);
    [$user, $server] = $this->generateTestAccount();

    $single = $this->actingAs($user)->getJson("/api/client/servers/{$server->uuid}/resources")->assertOk()->json();
    $batch = $this->actingAs($user)->getJson(resourcesUrl($server->uuid))->assertOk()->json("data.{$server->uuid}");

    expect($batch)->toBe($single);
});

test('servers the user cannot access are silently left out', function (): void {
    $asked = fakeWings();
    [$user, $mine] = $this->generateTestAccount();
    [, $theirs] = $this->generateTestAccount();

    $response = $this->actingAs($user)->getJson(resourcesUrl($mine->uuid, $theirs->uuid, $theirs->uuidShort, 'not-a-server'));

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath("data.{$mine->uuid}.object", 'stats');
    expect($asked->getArrayCopy())->toBe([$mine->uuid]);
});

test('a subuser reads resources with or without any permission, as on the single endpoint', function (array $permissions): void {
    fakeWings();
    $server = $this->createServerModel();
    $user = User::factory()->create();
    Subuser::query()->create(['user_id' => $user->id, 'server_id' => $server->id, 'permissions' => $permissions]);

    $this->actingAs($user)->getJson("/api/client/servers/{$server->uuid}/resources")->assertOk();
    $this->actingAs($user)->getJson(resourcesUrl($server->uuid))
        ->assertOk()
        ->assertJsonPath("data.{$server->uuid}.object", 'stats');
})->with([
    'with a permission' => [[Permissions::WebsocketConnect->value]],
    'without permissions' => [[]],
]);

test('a former subuser no longer sees the server', function (): void {
    fakeWings();
    [$user, $server] = $this->generateTestAccount([Permissions::WebsocketConnect->value]);
    Subuser::query()->where('user_id', $user->id)->delete();

    $this->actingAs($user)->getJson(resourcesUrl($server->uuid))->assertOk()->assertJsonCount(0, 'data');
});

test('a root admin reads the resources of servers owned by others', function (): void {
    fakeWings();
    $admin = User::factory()->create(['root_admin' => true]);
    [, $server] = $this->generateTestAccount();

    $this->actingAs($admin)->getJson(resourcesUrl($server->uuid))
        ->assertOk()
        ->assertJsonPath("data.{$server->uuid}.object", 'stats');
});

test('an unreachable node gives its servers an error entry without failing the others', function (): void {
    [$user, $online] = $this->generateTestAccount();
    $offline = $this->createServerModel(['user_id' => $user->id]);
    $alsoOffline = $this->createServerModel(['user_id' => $user->id, 'node_id' => $offline->node_id]);
    $asked = fakeWings(down: [$offline->node->fqdn]);

    $response = $this->actingAs($user)->getJson(resourcesUrl($online->uuid, $offline->uuid, $alsoOffline->uuid));

    $response->assertOk()
        ->assertJsonPath("data.{$online->uuid}.object", 'stats')
        ->assertJsonPath("data.{$offline->uuid}.object", 'error')
        ->assertJsonPath("data.{$offline->uuid}.attributes.code", 'daemon_error')
        ->assertJsonPath("data.{$offline->uuid}.attributes.status", 504)
        ->assertJsonPath("data.{$alsoOffline->uuid}.attributes.code", 'daemon_error');
    expect($asked)->toHaveCount(3);

    // The failure is remembered briefly, so the next poll does not wait on the node again.
    $this->actingAs($user)->getJson(resourcesUrl($offline->uuid))->assertOk()->assertJsonPath("data.{$offline->uuid}.attributes.code", 'daemon_error');
    expect($asked)->toHaveCount(3);

    $this->travel(11)->seconds();
    $this->actingAs($user)->getJson(resourcesUrl($offline->uuid))->assertOk();
    expect($asked)->toHaveCount(4);
});

test('a Wings error response is reported per server', function (): void {
    [$user, $server] = $this->generateTestAccount();
    Http::fake(fn (): PromiseInterface => Http::response(['error' => 'boom'], 500));

    $this->actingAs($user)->getJson(resourcesUrl($server->uuid))
        ->assertOk()
        ->assertJsonPath("data.{$server->uuid}.object", 'error')
        ->assertJsonPath("data.{$server->uuid}.attributes.code", 'daemon_error');
});

test('servers in a state that cannot be read get an error entry and Wings is not asked', function (array $attributes, string $code): void {
    $asked = fakeWings();
    [$user, $server] = $this->generateTestAccount();
    $server->forceFill($attributes)->save();

    $this->actingAs($user)->getJson(resourcesUrl($server->uuid))
        ->assertOk()
        ->assertJsonPath("data.{$server->uuid}.object", 'error')
        ->assertJsonPath("data.{$server->uuid}.attributes.code", $code)
        ->assertJsonPath("data.{$server->uuid}.attributes.status", 409);
    expect($asked)->toHaveCount(0);
})->with([
    'suspended' => [['status' => Server::STATUS_SUSPENDED], 'suspended'],
    'installing' => [['status' => Server::STATUS_INSTALLING], 'installing'],
    'restoring a backup' => [['status' => Server::STATUS_RESTORING_BACKUP], 'restoring_backup'],
]);

test('a server on a node under maintenance gets an error entry', function (): void {
    $asked = fakeWings();
    [$user, $server] = $this->generateTestAccount();
    Node::query()->whereKey($server->node_id)->update(['maintenance_mode' => true]);

    $this->actingAs($user)->getJson(resourcesUrl($server->uuid))
        ->assertOk()
        ->assertJsonPath("data.{$server->uuid}.attributes.code", 'node_maintenance');
    expect($asked)->toHaveCount(0);
});

test('readings are cached and shared with the single resources endpoint', function (): void {
    $asked = fakeWings(['state' => 'running']);
    [$user, $server] = $this->generateTestAccount();

    $this->actingAs($user)->getJson("/api/client/servers/{$server->uuid}/resources")->assertOk();
    $this->actingAs($user)->getJson(resourcesUrl($server->uuid))->assertOk()->assertJsonPath("data.{$server->uuid}.attributes.current_state", 'running');
    $this->actingAs($user)->getJson(resourcesUrl($server->uuid))->assertOk();
    expect($asked)->toHaveCount(1);

    $other = $this->createServerModel(['user_id' => $user->id, 'node_id' => $server->node_id]);
    $this->actingAs($user)->getJson(resourcesUrl($other->uuid))->assertOk();
    $this->actingAs($user)->getJson("/api/client/servers/{$other->uuid}/resources")->assertOk();
    expect($asked)->toHaveCount(2);

    $this->travel(21)->seconds();
    $this->actingAs($user)->getJson(resourcesUrl($server->uuid, $other->uuid))->assertOk();
    expect($asked)->toHaveCount(4);
});

test('at most 50 servers can be requested at once', function (): void {
    fakeWings();
    [$user, $server] = $this->generateTestAccount();
    $fifty = [$server->uuid, ...array_map(fn (int $i): string => sprintf('%08x', $i), range(1, 49))];

    $this->actingAs($user)->getJson(resourcesUrl(...$fifty))->assertOk()->assertJsonCount(1, 'data');
    // Repeats count once.
    $this->actingAs($user)->getJson(resourcesUrl(...$fifty, ...$fifty))->assertOk();
    $this->actingAs($user)->getJson(resourcesUrl(...[...$fifty, 'ffffffff']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.meta.source_field', 'servers');
});

test('the servers parameter is required', function (): void {
    [$user] = $this->generateTestAccount();

    $this->actingAs($user)->getJson('/api/client/resources')->assertUnprocessable();
});

test('an empty result is a JSON object', function (): void {
    [$user] = $this->generateTestAccount();

    $response = $this->actingAs($user)->getJson(resourcesUrl('ffffffff'))->assertOk();

    expect($response->getContent())->toBe('{"object":"server_resources","data":{}}');
});

test('the request makes a constant number of queries however many servers are asked for', function (): void {
    fakeWings();
    [$user, $server] = $this->generateTestAccount();
    $servers = [$server];
    for ($i = 0; $i < 9; $i++) {
        $servers[] = $this->createServerModel(['user_id' => $user->id]);
    }

    $count = function (array $list) use ($user): int {
        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user)->getJson(resourcesUrl(...array_map(fn (Server $s): string => $s->uuid, $list)))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($count($servers))->toBe($count([$server]));
});

test('servers can be requested by their realtime identifier', function (): void {
    fakeWings();
    [$user, $server] = $this->generateTestAccount();

    $this->actingAs($user)->getJson(resourcesUrl($server->identifier))
        ->assertOk()
        ->assertJsonPath("data.{$server->identifier}.object", 'stats');
});
