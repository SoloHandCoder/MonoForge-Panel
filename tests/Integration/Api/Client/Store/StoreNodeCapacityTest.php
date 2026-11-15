<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Client\Store\StoreNodeCapacityTest;

use Illuminate\Support\Arr;
use Pterodactyl\Actions\Servers\CreateServer;
use Pterodactyl\Contracts\Servers\CreatesServers;
use Pterodactyl\Exceptions\Service\Billing\StorePurchaseException;
use Pterodactyl\Exceptions\Service\Deployment\NoViableNodeException;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Billing\ServerSubscription;
use Pterodactyl\Models\Billing\ShopCategory;
use Pterodactyl\Models\Billing\ShopPeriod;
use Pterodactyl\Models\Billing\ShopPlan;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Objects\DeploymentObject;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Models\UserNotice;
use Pterodactyl\Services\Billing\CapacityService;
use Pterodactyl\Services\Billing\TokenWallet;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

uses(ClientApiIntegrationTestCase::class);

/** Stands in for Wings: persists the server on the allocation the store picked, like CreateServer does. */
final class FakeServerCreation implements CreatesServers
{
    public function create(array $input, ?DeploymentObject $deployment = null): Server
    {
        $allocation = Allocation::query()->findOrFail($input['allocation_id']);
        $server = Server::factory()->create([
            ...Arr::only($input, ['external_id', 'name', 'owner_id', 'egg_id', 'image', 'startup', 'memory', 'swap', 'disk', 'io', 'cpu', 'database_limit', 'allocation_limit', 'backup_limit', 'allocation_id']),
            'node_id' => $allocation->node_id,
        ]);
        $allocation->forceFill(['server_id' => $server->id])->save();

        return $server;
    }
}

beforeEach(function (): void {
    config(['monoforge.shop.enabled' => true, 'monoforge.shop.limits' => ['memory_mb' => 0, 'disk_mb' => 0, 'cpu_percent' => 0]]);
    $this->app->instance(CreatesServers::class, new FakeServerCreation);

    $this->location = Location::factory()->create();
    // A small node whose Pterodactyl memory and disk are both fully allocated, with no overallocation.
    $this->node = Node::factory()->create([
        'location_id' => $this->location->id, 'name' => 'Full', 'shop_enabled' => true,
        'memory' => 4096, 'memory_overallocate' => 0, 'disk' => 20000, 'disk_overallocate' => 0,
    ]);
    Allocation::factory()->count(3)->create(['node_id' => $this->node->id]);
    $this->createServerModel(['node_id' => $this->node->id, 'memory' => 4096, 'disk' => 20000]);

    $this->egg = Egg::query()->where('author', 'support@pterodactyl.io')->where('name', 'Bungeecord')->firstOrFail();
    $category = ShopCategory::query()->forceCreate(['name' => 'Minecraft', 'slug' => 'minecraft-'.uniqid(), 'sort' => 0, 'visible' => true])->id;
    $this->plan = ShopPlan::query()->forceCreate([
        'category_id' => $category, 'name' => 'Starter', 'description' => '', 'visible' => true, 'sort' => 0,
        'price_tokens' => 500, 'memory' => 2048, 'swap' => 0, 'disk' => 10000, 'cpu' => 100, 'io' => 500,
        'databases' => 1, 'backups' => 1, 'allocations' => 0, 'egg_ids' => [$this->egg->id], 'node_ids' => null,
    ]);
    $this->period = ShopPeriod::query()->forceCreate(['name' => 'Monthly', 'days' => 30, 'price_modifier_percent' => 0, 'sort' => 0, 'enabled' => true]);

    $this->user = User::factory()->create();
    $this->app->make(TokenWallet::class)->credit($this->user, 2000, 'admin_adjust', 'Test balance');
});

function order(array $overrides = []): array
{
    $test = pterodactylTestCase();

    return [
        'plan_id' => $test->plan->id,
        'period_id' => $test->period->id,
        'egg_id' => $test->egg->id,
        'location_id' => $test->location->id,
        'name' => 'Survival',
        'expected_total' => 500,
        'idempotency_key' => 'key-'.uniqid().str_repeat('a', 10),
        ...$overrides,
    ];
}

function shopServerOn(Node $node, int $memory): Server
{
    $test = pterodactylTestCase();
    $server = $test->createServerModel(['node_id' => $node->id, 'memory' => $memory, 'disk' => 1000, 'cpu' => 100]);
    ServerSubscription::query()->create([
        'server_id' => $server->id, 'user_id' => $server->owner_id, 'period_days' => 30, 'price_tokens' => 500,
        'addons' => [], 'next_renewal_at' => now()->addDays(30),
    ]);

    return $server;
}

test('a purchase ignores the node memory and disk allocation, overallocation included', function (): void {
    expect(Node::query()->viableFor(2048, 10000)->get()->modelKeys())->not->toContain($this->node->id);

    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order())->assertCreated();

    expect(Server::query()->where('owner_id', $this->user->id)->sole()->node_id)->toBe($this->node->id);
});

test('a purchase still needs a free allocation and a node out of maintenance', function (): void {
    Allocation::query()->where('node_id', $this->node->id)->whereNull('server_id')->delete();

    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order())->assertStatus(409)
        ->assertJsonPath('errors.0.detail', 'There is no room for this server on the chosen location right now. Try another location or a smaller plan.');

    Allocation::factory()->create(['node_id' => $this->node->id]);
    $this->node->forceFill(['maintenance_mode' => true])->save();
    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order())->assertStatus(409);

    expect(Server::query()->where('owner_id', $this->user->id)->exists())->toBeFalse();
});

test('a purchase is refused at the node shop limit with the capacity message', function (): void {
    // Room for exactly one 2 GiB server on this node.
    $this->node->forceFill(['shop_limit_memory_mb' => 3000])->save();

    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order())->assertCreated();
    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order())
        ->assertStatus(409)
        ->assertJsonPath('errors.0.detail', "The server currently can't provide servers, we hit our limit!");

    expect(Server::query()->where('owner_id', $this->user->id)->count())->toBe(1)
        ->and(resolve(TokenWallet::class)->balance($this->user->refresh()))->toBe(1500);
});

test('the disk and CPU node limits count too, and a node in the same location with room is used instead', function (): void {
    $this->node->forceFill(['shop_limit_cpu_percent' => 150])->save();
    shopServerOn($this->node, 512);
    $other = Node::factory()->create(['location_id' => $this->location->id, 'shop_enabled' => true, 'shop_limit_disk_mb' => 15000]);
    Allocation::factory()->create(['node_id' => $other->id]);

    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order())->assertCreated();
    expect(Server::query()->where('owner_id', $this->user->id)->sole()->node_id)->toBe($other->id);

    // Both nodes are full now: the CPU limit on one, the disk limit on the other.
    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order(['node_id' => $other->id]))
        ->assertStatus(409)->assertJsonPath('errors.0.detail', StorePurchaseException::CAPACITY_MESSAGE);
    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order())
        ->assertStatus(409)->assertJsonPath('errors.0.detail', StorePurchaseException::CAPACITY_MESSAGE);
});

test('the shop-wide limit is still enforced when the node has room', function (): void {
    $this->node->forceFill(['shop_limit_memory_mb' => 100000])->save();
    config(['monoforge.shop.limits.memory_mb' => 1024]);

    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order())
        ->assertStatus(409)
        ->assertJsonPath('errors.0.detail', StorePurchaseException::CAPACITY_MESSAGE);

    expect(Server::query()->where('owner_id', $this->user->id)->exists())->toBeFalse();
});

test('the catalog and the quote mark a node at its shop limit', function (): void {
    $roomy = Node::factory()->create(['location_id' => $this->location->id, 'name' => 'Roomy', 'shop_enabled' => true]);
    $this->node->forceFill(['shop_limit_memory_mb' => 2048])->save();
    shopServerOn($this->node, 1024);

    $response = $this->actingAs($this->user)->getJson(route('api:client.store.catalog'))->assertOk()
        ->assertJsonPath('categories.0.plans.0.can_provision', true)
        ->assertJsonPath('categories.0.plans.0.locations.0.can_provision', true);
    $nodes = collect($response->json('categories.0.plans.0.locations.0.nodes'))->keyBy('name');
    expect($nodes['Full']['can_provision'])->toBeFalse()
        ->and($nodes['Roomy']['can_provision'])->toBeTrue();

    $quote = ['plan_id' => $this->plan->id, 'period_id' => $this->period->id, 'egg_id' => $this->egg->id];
    $this->postJson(route('api:client.store.quote'), [...$quote, 'node_id' => $this->node->id])->assertOk()->assertJsonPath('can_provision', false);
    // In the location, the node with room is quoted.
    $this->postJson(route('api:client.store.quote'), [...$quote, 'location_id' => $this->location->id])->assertOk()
        ->assertJsonPath('node_id', $roomy->id)->assertJsonPath('can_provision', true);
});

test('a location whose every node is at its shop limit is sold out in the catalog', function (): void {
    $this->node->forceFill(['shop_limit_memory_mb' => 2048])->save();
    shopServerOn($this->node, 1024);

    $response = $this->actingAs($this->user)->getJson(route('api:client.store.catalog'))->assertOk()
        ->assertJsonPath('categories.0.plans.0.can_provision', false)
        ->assertJsonPath('categories.0.plans.0.locations.0.can_provision', false)
        ->assertJsonPath('categories.0.plans.0.locations.0.nodes.0.can_provision', false);
    expect($response->json('categories.0.plans.0.locations.0.nodes.0.id'))->toBe($this->node->id);
});

test('a server sold past the node limit after all is kept and the root admins are told', function (): void {
    $admin = User::factory()->create(['root_admin' => true]);
    $this->node->forceFill(['shop_limit_memory_mb' => 1024])->save();
    // A stale view of the node under the lock says yes, and the server is created anyway.
    $this->app->instance(CapacityService::class, new class extends CapacityService
    {
        public function canProvisionOn(Node $node, array $resources): bool
        {
            return true;
        }
    });

    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order())->assertCreated();

    $notice = UserNotice::query()->where('user_id', $admin->id)->where('title', 'Store over capacity')->sole();
    expect($notice->body)->toContain('past the shop limits of node "Full"')
        ->and(Server::query()->where('owner_id', $this->user->id)->exists())->toBeTrue();
});

test('an admin-created server still uses the node memory and disk allocation checks', function (): void {
    $deployment = (new DeploymentObject)->setLocations([$this->location->id])->setPorts([]);

    expect(fn () => resolve(CreateServer::class)->create([
        'name' => 'Admin server', 'owner_id' => $this->user->id, 'egg_id' => $this->egg->id,
        'image' => 'ghcr.io/pterodactyl/yolks:java_17', 'startup' => 'java -jar server.jar',
        'memory' => 1024, 'swap' => 0, 'disk' => 1000, 'io' => 500, 'cpu' => 100,
    ], $deployment))->toThrow(NoViableNodeException::class);
});
