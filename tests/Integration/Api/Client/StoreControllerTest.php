<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Client\StoreControllerTest;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Enum\ShopAddonResource;
use Pterodactyl\Enum\ShopMultiplierScope;
use Pterodactyl\Models\Billing\ShopAddon;
use Pterodactyl\Models\Billing\ShopCategory;
use Pterodactyl\Models\Billing\ShopMultiplier;
use Pterodactyl\Models\Billing\ShopPeriod;
use Pterodactyl\Models\Billing\ShopPlan;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Billing\CapacityService;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

uses(ClientApiIntegrationTestCase::class);

beforeEach(function (): void {
    config()->set('monoforge.shop.enabled', true);
    config()->set('monoforge.shop.limits', ['memory_mb' => 0, 'disk_mb' => 0, 'cpu_percent' => 0]);

    $this->location = Location::factory()->create(['short' => 'de']);
    $this->home = Node::factory()->create(['location_id' => $this->location->id, 'name' => 'Home', 'public' => true]);
    $this->pro = Node::factory()->create(['location_id' => $this->location->id, 'name' => 'Pro', 'public' => true]);
    $this->hidden = Node::factory()->create(['location_id' => $this->location->id, 'name' => 'Private', 'public' => false]);
    ShopMultiplier::query()->create(['scope' => ShopMultiplierScope::Node, 'scope_id' => $this->pro->id, 'multiplier' => '1.50']);

    $this->egg = Egg::query()->firstOrFail();
    $this->category = ShopCategory::query()->create(['name' => 'Minecraft', 'slug' => 'minecraft']);
    $this->plan = fn (array $attributes = []): ShopPlan => ShopPlan::query()->create([
        'category_id' => $this->category->id,
        'name' => 'Stone',
        'price_tokens' => 500,
        'memory' => 2048,
        'disk' => 10240,
        'cpu' => 100,
        'egg_ids' => [$this->egg->id],
        ...$attributes,
    ]);
    $this->monthly = ShopPeriod::query()->create(['name' => 'Monthly', 'days' => 30, 'price_modifier_percent' => 0]);
    $this->quarterly = ShopPeriod::query()->create(['name' => 'Quarterly', 'days' => 90, 'price_modifier_percent' => -20, 'sort' => 1]);
    ShopAddon::query()->create(['key' => 'backup', 'name' => 'Backup', 'resource' => ShopAddonResource::Backups, 'amount' => 1, 'price_tokens' => 50, 'max_units' => 3]);
});

test('the catalog lists visible plans cheapest first with where they can run', function (): void {
    ($this->plan)(['name' => 'Iron', 'price_tokens' => 900]);
    ($this->plan)(['name' => 'Stone']);
    ($this->plan)(['name' => 'Secret', 'visible' => false]);
    ShopPeriod::query()->create(['name' => 'Off', 'days' => 7, 'enabled' => false]);

    $response = $this->actingAs(User::factory()->create())->getJson(route('api:client.store.catalog'))->assertOk();

    $response->assertJsonPath('enabled', true)
        ->assertJsonPath('subdomains_enabled', false)
        ->assertJsonPath('categories.0.slug', 'minecraft')
        ->assertJsonPath('categories.0.plans.0.name', 'Stone')
        ->assertJsonPath('categories.0.plans.0.from_price_tokens', 500)
        ->assertJsonPath('categories.0.plans.0.can_provision', true)
        ->assertJsonPath('categories.0.plans.0.eggs.0.id', $this->egg->id)
        ->assertJsonPath('categories.0.plans.1.name', 'Iron')
        ->assertJsonCount(2, 'categories.0.plans')
        ->assertJsonCount(2, 'periods')
        ->assertJsonPath('addons.0.key', 'backup');

    // Only the deployable nodes, cheapest first, and never an address.
    $nodes = $response->json('categories.0.plans.0.locations.0.nodes');
    expect(array_column($nodes, 'name'))->toBe(['Home', 'Pro'])
        ->and(array_column($nodes, 'multiplier'))->toBe([1, 1.5])
        ->and(array_keys($nodes[0]))->toBe(['id', 'name', 'multiplier']);
});

test('a closed store shows nothing and refuses quotes', function (): void {
    config()->set('monoforge.shop.enabled', false);
    $plan = ($this->plan)();
    $user = User::factory()->create();

    $this->actingAs($user)->getJson(route('api:client.store.catalog'))->assertOk()
        ->assertJsonPath('enabled', false)
        ->assertJsonCount(0, 'categories');
    $this->actingAs($user)->postJson(route('api:client.store.quote'), ['plan_id' => $plan->id, 'period_id' => $this->monthly->id, 'node_id' => $this->home->id])
        ->assertStatus(400);
});

test('quotes a plan on a node with a period discount, the node multiplier and add-ons', function (): void {
    $plan = ($this->plan)();

    $response = $this->actingAs(User::factory()->create())->postJson(route('api:client.store.quote'), [
        'plan_id' => $plan->id,
        'period_id' => $this->quarterly->id,
        'node_id' => $this->pro->id,
        'addons' => ['backup' => 2],
    ])->assertOk();

    // 500 x 3 x 0.8 = 1200 x 1.5 = 1800, plus 2 x 50 x 3 x 0.8 = 240
    $response->assertJsonPath('node_id', $this->pro->id)
        ->assertJsonPath('can_provision', true)
        ->assertJsonPath('quote.base_per_period', 1200)
        ->assertJsonPath('quote.multiplier', 1.5)
        ->assertJsonPath('quote.total_per_period', 2040)
        ->assertJsonPath('quote.period_days', 90)
        ->assertJsonPath('quote.resources.backups', 2);
});

test('a location quote picks its cheapest allowed node', function (): void {
    $plan = ($this->plan)();

    $this->actingAs(User::factory()->create())->postJson(route('api:client.store.quote'), [
        'plan_id' => $plan->id, 'period_id' => $this->monthly->id, 'location_id' => $this->location->id,
    ])->assertOk()->assertJsonPath('node_id', $this->home->id)->assertJsonPath('quote.total_per_period', 500);
});

test('the location multiplier applies when the node has none', function (): void {
    ShopMultiplier::query()->create(['scope' => ShopMultiplierScope::Location, 'scope_id' => $this->location->id, 'multiplier' => '1.20']);
    $plan = ($this->plan)();

    $this->actingAs(User::factory()->create())->postJson(route('api:client.store.quote'), [
        'plan_id' => $plan->id, 'period_id' => $this->monthly->id, 'node_id' => $this->home->id,
    ])->assertOk()->assertJsonPath('quote.multiplier', 1.2)->assertJsonPath('quote.total_per_period', 600);

    // The node's own multiplier still wins.
    $this->actingAs(User::factory()->create())->postJson(route('api:client.store.quote'), [
        'plan_id' => $plan->id, 'period_id' => $this->monthly->id, 'node_id' => $this->pro->id,
    ])->assertOk()->assertJsonPath('quote.total_per_period', 750);
});

test('refuses quotes the store does not sell', function (array $body): void {
    $plan = ($this->plan)(['node_ids' => [$this->home->id]]);
    $disabled = ShopPeriod::query()->create(['name' => 'Off', 'days' => 7, 'enabled' => false]);

    $body = array_replace(['plan_id' => $plan->id, 'period_id' => $this->monthly->id, 'node_id' => $this->home->id], $body);
    $body = array_map(fn ($value) => match ($value) {
        'disabled' => $disabled->id,
        'pro' => $this->pro->id,
        'hidden' => $this->hidden->id,
        default => $value,
    }, $body);

    $this->actingAs(User::factory()->create())->postJson(route('api:client.store.quote'), $body)->assertStatus(400);
})->with([
    'a node the plan does not allow' => [['node_id' => 'pro']],
    'a disabled period' => [['period_id' => 'disabled']],
    'too many add-on units' => [['addons' => ['backup' => 4]]],
    'an unknown add-on' => [['addons' => ['gpu' => 1]]],
]);

test('a plan without a node list only runs on deployable nodes', function (): void {
    $plan = ($this->plan)();

    $this->actingAs(User::factory()->create())->postJson(route('api:client.store.quote'), [
        'plan_id' => $plan->id, 'period_id' => $this->monthly->id, 'node_id' => $this->hidden->id,
    ])->assertStatus(400);
});

test('capacity counts only active and suspended shop servers', function (): void {
    // Task T owns server_subscriptions; a temporary stand-in keeps this test inside its transaction.
    $created = ! Schema::hasTable('server_subscriptions');
    if ($created) {
        expect((new CapacityService())->used())->toBe(['memory' => 0, 'disk' => 0, 'cpu' => 0]);
        DB::statement('CREATE TEMPORARY TABLE server_subscriptions (id INT AUTO_INCREMENT PRIMARY KEY, server_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, period_days INT NOT NULL, price_tokens INT NOT NULL, addons JSON NULL, status VARCHAR(16) NOT NULL, next_renewal_at DATETIME NOT NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL)');
    }

    $active = $this->createServerModel(['memory' => 4096, 'disk' => 20000, 'cpu' => 200]);
    $suspended = $this->createServerModel(['memory' => 2048, 'disk' => 10000, 'cpu' => 100]);
    $cancelled = $this->createServerModel(['memory' => 8192, 'disk' => 50000, 'cpu' => 400]);
    $this->createServerModel(['memory' => 16384, 'disk' => 90000, 'cpu' => 800]);
    // The columns of Task T's table that have no default.
    $subscription = fn ($server, string $status): array => [
        'server_id' => $server->id,
        'user_id' => $server->owner_id,
        'period_days' => 30,
        'price_tokens' => 500,
        'addons' => '[]',
        'status' => $status,
        'next_renewal_at' => now()->addDays(30),
        'created_at' => now(),
        'updated_at' => now(),
    ];
    DB::table('server_subscriptions')->insert([
        $subscription($active, 'active'),
        $subscription($suspended, 'suspended'),
        $subscription($cancelled, 'cancelled'),
    ]);

    config()->set('monoforge.shop.limits', ['memory_mb' => 8192, 'disk_mb' => 0, 'cpu_percent' => 400]);
    $capacity = new CapacityService();

    expect($capacity->used())->toBe(['memory' => 6144, 'disk' => 30000, 'cpu' => 300])
        ->and($capacity->remaining())->toBe(['memory' => 2048, 'disk' => null, 'cpu' => 100])
        ->and($capacity->canProvision(['memory' => 2048, 'disk' => 999999, 'cpu' => 100]))->toBeTrue()
        ->and($capacity->canProvision(['memory' => 2049, 'disk' => 10, 'cpu' => 100]))->toBeFalse();

    // A plan that no longer fits shows as unavailable in the catalog.
    ($this->plan)(['memory' => 4096]);
    $this->actingAs(User::factory()->create())->getJson(route('api:client.store.catalog'))->assertOk()
        ->assertJsonPath('categories.0.plans.0.can_provision', false);

    if ($created) {
        DB::statement('DROP TEMPORARY TABLE server_subscriptions');
    }
});
