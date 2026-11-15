<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Http\Controllers\Base\PublicStorePlansTest;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Pterodactyl\Enum\ShopMultiplierScope;
use Pterodactyl\Http\Controllers\Base\PublicStorePlansController;
use Pterodactyl\Models\Billing\ShopCategory;
use Pterodactyl\Models\Billing\ShopMultiplier;
use Pterodactyl\Models\Billing\ShopPeriod;
use Pterodactyl\Models\Billing\ShopPlan;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Node;
use Pterodactyl\Services\Billing\ShopSettings;
use Pterodactyl\Tests\Integration\Http\HttpTestCase;

uses(HttpTestCase::class);

beforeEach(function (): void {
    Cache::forget(PublicStorePlansController::CACHE_KEY);
    config()->set('monoforge.shop.enabled', true);
    config()->set('monoforge.shop.limits', ['memory_mb' => 0, 'disk_mb' => 0, 'cpu_percent' => 0]);
    config()->set('monoforge.ai.enabled', false);

    $this->location = Location::factory()->create(['short' => 'de-fra', 'long' => 'Secret DC']);
    $this->cheap = Node::factory()->create(['location_id' => $this->location->id, 'name' => 'Cheap Node', 'fqdn' => 'cheap.internal.example', 'public' => true, 'shop_enabled' => true]);
    $this->pro = Node::factory()->create(['location_id' => $this->location->id, 'name' => 'Pro Node', 'fqdn' => 'pro.internal.example', 'public' => true, 'shop_enabled' => true]);
    $this->private = Node::factory()->create(['location_id' => $this->location->id, 'name' => 'Private Node', 'public' => false, 'shop_enabled' => true]);
    $this->offShop = Node::factory()->create(['location_id' => $this->location->id, 'name' => 'Off Shop Node', 'public' => true, 'shop_enabled' => false]);
    ShopMultiplier::query()->create(['scope' => ShopMultiplierScope::Node, 'scope_id' => $this->cheap->id, 'multiplier' => '0.80']);
    ShopMultiplier::query()->create(['scope' => ShopMultiplierScope::Node, 'scope_id' => $this->pro->id, 'multiplier' => '1.50']);

    $this->egg = Egg::query()->firstOrFail();
    $this->category = ShopCategory::query()->create(['name' => 'Minecraft', 'slug' => 'minecraft', 'description' => 'Java edition.']);
    $this->plan = fn (array $attributes = []): ShopPlan => ShopPlan::query()->create([
        'category_id' => $this->category->id,
        'name' => 'Stone',
        'description' => 'Small survival.',
        'price_tokens' => 500,
        'memory' => 2048,
        'disk' => 10240,
        'cpu' => 100,
        'databases' => 1,
        'backups' => 2,
        'egg_ids' => [$this->egg->id],
        ...$attributes,
    ]);
    $this->monthly = ShopPeriod::query()->create(['name' => 'Monthly', 'days' => 30, 'price_modifier_percent' => 0]);
    $this->quarterly = ShopPeriod::query()->create(['name' => 'Quarterly', 'days' => 90, 'price_modifier_percent' => -20, 'sort' => 1]);
});

test('lists the plans on sale with their resources, games and price per period without signing in', function (): void {
    ($this->plan)(['name' => 'Iron', 'price_tokens' => 900]);
    ($this->plan)();
    ($this->plan)(['name' => 'Secret', 'visible' => false]);
    ShopPeriod::query()->create(['name' => 'Off', 'days' => 7, 'enabled' => false]);

    $response = $this->getJson(route('api.public.store.plans'))->assertOk();
    $this->assertGuest();

    $response->assertJsonPath('enabled', true)
        ->assertJsonPath('assistant_enabled', false)
        ->assertJsonPath('periods', [
            ['id' => $this->monthly->id, 'name' => 'Monthly', 'days' => 30],
            ['id' => $this->quarterly->id, 'name' => 'Quarterly', 'days' => 90],
        ])
        ->assertJsonPath('categories.0.name', 'Minecraft')
        ->assertJsonPath('categories.0.slug', 'minecraft')
        ->assertJsonCount(2, 'categories.0.plans')
        ->assertJsonPath('categories.0.plans.1.name', 'Iron')
        ->assertJsonPath('categories.0.plans.0.name', 'Stone')
        ->assertJsonPath('categories.0.plans.0.resources', ['memory' => 2048, 'disk' => 10240, 'cpu' => 100, 'databases' => 1, 'backups' => 2])
        ->assertJsonPath('categories.0.plans.0.games', [$this->egg->name])
        ->assertJsonPath('categories.0.plans.0.available', true)
        // On the cheapest node (x0.8): 500 x 0.8 = 400 a month, 500 x 3 x 0.8 = 1200 x 0.8 = 960 a quarter.
        ->assertJsonPath('categories.0.plans.0.prices', [
            ['period_id' => $this->monthly->id, 'days' => 30, 'tokens' => 400],
            ['period_id' => $this->quarterly->id, 'days' => 90, 'tokens' => 960],
        ]);

    expect(array_keys($response->json('categories.0.plans.0')))
        ->toBe(['id', 'name', 'description', 'resources', 'games', 'available', 'prices']);
});

test('never mentions a node, a location or an address', function (): void {
    ($this->plan)();

    $body = (string) $this->getJson(route('api.public.store.plans'))->assertOk()->getContent();

    foreach (['Cheap Node', 'Pro Node', 'Private Node', 'Off Shop Node', 'internal.example', 'Secret DC', 'de-fra', 'node', 'location', 'multiplier'] as $needle) {
        expect(mb_strtolower($body))->not->toContain(mb_strtolower($needle));
    }
});

test('a plan that can only run on private or non-shop nodes is not listed', function (): void {
    ($this->plan)(['name' => 'Hidden away', 'node_ids' => [$this->private->id, $this->offShop->id]]);
    ($this->plan)(['name' => 'Pinned', 'node_ids' => [$this->pro->id]]);

    $response = $this->getJson(route('api.public.store.plans'))->assertOk();

    expect(Arr::pluck($response->json('categories.0.plans'), 'name'))->toBe(['Pinned'])
        // Priced on the only node it may use (x1.5).
        ->and($response->json('categories.0.plans.0.prices.0.tokens'))->toBe(750);
});

test('a hidden category and a plan without a game are left out', function (): void {
    ($this->plan)(['name' => 'No game', 'egg_ids' => []]);
    $hidden = ShopCategory::query()->create(['name' => 'Staff', 'slug' => 'staff', 'visible' => false]);
    ($this->plan)(['name' => 'Staff plan', 'category_id' => $hidden->id]);

    $this->getJson(route('api.public.store.plans'))->assertOk()->assertJsonCount(0, 'categories');
});

test('a plan the store has no capacity left for is shown as unavailable', function (): void {
    config()->set('monoforge.shop.limits', ['memory_mb' => 1024, 'disk_mb' => 0, 'cpu_percent' => 0]);
    ($this->plan)();

    $this->getJson(route('api.public.store.plans'))->assertOk()->assertJsonPath('categories.0.plans.0.available', false);
});

test('a plan stays on sale while one of its shop nodes has room under its own shop limits', function (): void {
    ($this->plan)();
    $this->cheap->forceFill(['shop_limit_memory_mb' => 1024])->save();

    $this->getJson(route('api.public.store.plans'))->assertOk()->assertJsonPath('categories.0.plans.0.available', true);
});

test('a plan whose every shop node is full under its own shop limits is shown as unavailable', function (): void {
    $plan = ($this->plan)();
    $this->cheap->forceFill(['shop_limit_memory_mb' => 1024])->save();
    $this->pro->forceFill(['shop_limit_memory_mb' => 1024])->save();

    $this->getJson(route('api.public.store.plans'))->assertOk()
        ->assertJsonPath('categories.0.plans.0.id', $plan->id)
        ->assertJsonPath('categories.0.plans.0.available', false);
});

test('a closed store returns empty lists', function (): void {
    config()->set('monoforge.shop.enabled', false);
    config()->set('monoforge.ai.enabled', true);
    ($this->plan)();

    $this->getJson(route('api.public.store.plans'))->assertOk()
        ->assertJsonPath('enabled', false)
        ->assertJsonPath('assistant_enabled', true)
        ->assertJsonCount(0, 'periods')
        ->assertJsonCount(0, 'categories');
});

test('the answer is cached for a minute and sets no cookie', function (): void {
    ($this->plan)();

    $response = $this->getJson(route('api.public.store.plans'))->assertOk()
        ->assertHeader('Cache-Control', 'max-age=60, public');
    expect($response->headers->getCookies())->toBe([]);

    // A write that bypasses the models (raw SQL, a migration) shows once the minute is up.
    ShopPlan::query()->update(['name' => 'Renamed in SQL']);
    $this->getJson(route('api.public.store.plans'))->assertOk()->assertJsonPath('categories.0.plans.0.name', 'Stone');

    $this->travel(61)->seconds();
    $this->getJson(route('api.public.store.plans'))->assertOk()->assertJsonPath('categories.0.plans.0.name', 'Renamed in SQL');
});

test('an admin change to the shop shows on the homepage at once', function (Closure $change, Closure $expect): void {
    ($this->plan)();
    $this->getJson(route('api.public.store.plans'))->assertOk()->assertJsonCount(1, 'categories.0.plans');
    expect(Cache::has(PublicStorePlansController::CACHE_KEY))->toBeTrue();

    $change->call($this);

    $expect->call($this, $this->getJson(route('api.public.store.plans'))->assertOk());
})->with([
    'a new plan' => [
        function (): void {
            ($this->plan)(['name' => 'Added later', 'price_tokens' => 9000]);
        },
        fn (TestResponse $response): TestResponse => $response->assertJsonCount(2, 'categories.0.plans'),
    ],
    'a hidden plan' => [
        function (): void {
            ShopPlan::query()->firstOrFail()->update(['visible' => false]);
        },
        fn (TestResponse $response): TestResponse => $response->assertJsonCount(0, 'categories'),
    ],
    'a disabled period' => [
        function (): void {
            $this->quarterly->update(['enabled' => false]);
        },
        fn (TestResponse $response): TestResponse => $response->assertJsonCount(1, 'periods'),
    ],
    'a node taken out of the shop' => [
        function (): void {
            $this->cheap->forceFill(['shop_enabled' => false])->save();
            $this->pro->forceFill(['shop_enabled' => false])->save();
        },
        fn (TestResponse $response): TestResponse => $response->assertJsonCount(0, 'categories'),
    ],
    'the store closed in its settings' => [
        function (): void {
            ShopSettings::store(['enabled' => false, 'grace_days' => 3, 'low_balance_days' => 3, 'limits' => ['memory' => 0, 'disk' => 0, 'cpu' => 0]]);
        },
        fn (TestResponse $response): TestResponse => $response->assertJsonPath('enabled', false),
    ],
]);
