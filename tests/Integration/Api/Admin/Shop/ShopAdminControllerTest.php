<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Admin\Shop\ShopAdminControllerTest;

use Illuminate\Http\Response;
use Pterodactyl\Models\Billing\ShopCategory;
use Pterodactyl\Models\Billing\ShopPlan;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Setting;
use Pterodactyl\Tests\Integration\Api\Admin\AdminApiIntegrationTestCase;

uses(AdminApiIntegrationTestCase::class);

function shopCategory(array $attributes = []): ShopCategory
{
    static $count = 0;
    $count++;

    return ShopCategory::query()->create(['name' => "Category {$count}", 'slug' => "category-{$count}", ...$attributes]);
}

/**
 * @return array<string, mixed>
 */
function planBody(int $categoryId, array $overrides = []): array
{
    return [
        'category_id' => $categoryId,
        'name' => 'Stone',
        'price_tokens' => 500,
        'memory' => 2048,
        'disk' => 10240,
        'cpu' => 100,
        'backups' => 2,
        'egg_ids' => [Egg::query()->firstOrFail()->id],
        'node_ids' => [],
        ...$overrides,
    ];
}

test('creates, lists cheapest first, updates and deletes plans', function (): void {
    $category = shopCategory();

    $this->postJson(route('api.admin.shop.plans.store'), planBody($category->id, ['name' => 'Iron', 'price_tokens' => 900]))
        ->assertStatus(Response::HTTP_CREATED)
        ->assertJsonPath('name', 'Iron')
        ->assertJsonPath('resources.io', 500)
        ->assertJsonPath('node_ids', null);
    $stone = $this->postJson(route('api.admin.shop.plans.store'), planBody($category->id))->assertCreated()->json('id');

    $this->getJson(route('api.admin.shop.plans'))->assertOk()
        ->assertJsonPath('data.0.name', 'Stone')
        ->assertJsonPath('data.1.name', 'Iron');

    $node = Node::factory()->create(['location_id' => Location::factory()->create()->id]);
    $this->putJson(route('api.admin.shop.plans.update', ['plan' => $stone]), planBody($category->id, ['price_tokens' => 450, 'node_ids' => [$node->id]]))
        ->assertOk()
        ->assertJsonPath('price_tokens', 450)
        ->assertJsonPath('node_ids', [$node->id]);
    $this->assertActivityLogged('admin:shop.plan.update');

    $this->deleteJson(route('api.admin.shop.plans.delete', ['plan' => $stone]))->assertNoContent();
    expect(ShopPlan::query()->whereKey($stone)->exists())->toBeFalse();
});

test('stores the pay-as-you-play options of a plan', function (): void {
    $category = shopCategory();

    // New plans do not sleep; the idle time defaults to 15 minutes.
    $this->postJson(route('api.admin.shop.plans.store'), planBody($category->id))
        ->assertCreated()
        ->assertJsonPath('sleep_allowed', false)
        ->assertJsonPath('sleep_discount_percent', 0)
        ->assertJsonPath('sleep_idle_minutes', 15);

    $id = $this->postJson(route('api.admin.shop.plans.store'), planBody($category->id, ['sleep_allowed' => true, 'sleep_discount_percent' => 60, 'sleep_idle_minutes' => 30]))
        ->assertCreated()
        ->assertJsonPath('sleep_allowed', true)
        ->assertJsonPath('sleep_discount_percent', 60)
        ->assertJsonPath('sleep_idle_minutes', 30)
        ->json('id');

    expect(ShopPlan::query()->findOrFail($id))
        ->sleep_allowed->toBeTrue()
        ->sleep_discount_percent->toBe(60)
        ->sleep_idle_minutes->toBe(30);

    $this->putJson(route('api.admin.shop.plans.update', ['plan' => $id]), planBody($category->id, ['sleep_allowed' => false, 'sleep_discount_percent' => 100, 'sleep_idle_minutes' => 5]))
        ->assertOk()
        ->assertJsonPath('sleep_allowed', false)
        ->assertJsonPath('sleep_discount_percent', 100)
        ->assertJsonPath('sleep_idle_minutes', 5);
});

test('rejects sleep discounts outside 0-100 and idle times outside 5-1440 minutes', function (array $overrides, string $field): void {
    $this->postJson(route('api.admin.shop.plans.store'), planBody(shopCategory()->id, $overrides))
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.meta.source_field', $field);
})->with([
    [['sleep_discount_percent' => 101], 'sleep_discount_percent'],
    [['sleep_discount_percent' => -1], 'sleep_discount_percent'],
    [['sleep_idle_minutes' => 4], 'sleep_idle_minutes'],
    [['sleep_idle_minutes' => 1441], 'sleep_idle_minutes'],
    [['sleep_allowed' => 'sometimes'], 'sleep_allowed'],
]);

test('rejects a plan without eggs or with unknown eggs', function (): void {
    $category = shopCategory();

    $response = $this->postJson(route('api.admin.shop.plans.store'), planBody($category->id, ['egg_ids' => []]))->assertUnprocessable();
    expect(collect($response->json('errors'))->pluck('meta.source_field')->all())->toContain('egg_ids');

    $this->postJson(route('api.admin.shop.plans.store'), planBody($category->id, ['egg_ids' => [999999]]))->assertUnprocessable();
});

test('reorders categories and refuses to delete one that still has plans', function (): void {
    $first = shopCategory(['sort' => 0]);
    $second = shopCategory(['sort' => 1]);
    ShopPlan::query()->create([...planBody($first->id), 'egg_ids' => [1]]);

    $this->putJson(route('api.admin.shop.categories.reorder'), ['ids' => [$second->id, $first->id]])->assertNoContent();
    $this->getJson(route('api.admin.shop.categories'))->assertOk()
        ->assertJsonPath('data.0.id', $second->id)
        ->assertJsonPath('data.1.plans_count', 1);

    $this->deleteJson(route('api.admin.shop.categories.delete', ['category' => $first->id]))->assertStatus(Response::HTTP_BAD_REQUEST);
    $this->deleteJson(route('api.admin.shop.categories.delete', ['category' => $second->id]))->assertNoContent();
});

test('category slugs are unique and lower-case', function (): void {
    shopCategory(['slug' => 'minecraft']);

    $this->postJson(route('api.admin.shop.categories.store'), ['name' => 'Minecraft', 'slug' => 'minecraft'])->assertUnprocessable();
    $this->postJson(route('api.admin.shop.categories.store'), ['name' => 'Bots', 'slug' => 'Bots!'])->assertUnprocessable();
    $this->postJson(route('api.admin.shop.categories.store'), ['name' => 'Bots', 'slug' => 'bots'])->assertCreated()->assertJsonPath('plans_count', 0);
});

test('manages periods, multipliers and add-ons', function (): void {
    $this->postJson(route('api.admin.shop.periods.store'), ['name' => 'Quarterly', 'days' => 90, 'price_modifier_percent' => -10])
        ->assertCreated()->assertJsonPath('enabled', true);

    $location = Location::factory()->create();
    $id = $this->postJson(route('api.admin.shop.multipliers.store'), ['scope' => 'location', 'scope_id' => $location->id, 'multiplier' => 1.5, 'label' => 'Pro'])
        ->assertCreated()->assertJsonPath('multiplier', 1.5)->json('id');
    // One multiplier per location.
    $this->postJson(route('api.admin.shop.multipliers.store'), ['scope' => 'location', 'scope_id' => $location->id, 'multiplier' => 2])->assertUnprocessable();
    $this->putJson(route('api.admin.shop.multipliers.update', ['multiplier' => $id]), ['scope' => 'location', 'scope_id' => $location->id, 'multiplier' => 1.25])
        ->assertOk()->assertJsonPath('multiplier', 1.25);
    $this->assertActivityLogged('admin:shop.multiplier.update');

    $this->postJson(route('api.admin.shop.addons.store'), [
        'key' => 'extra-backup', 'name' => 'Extra backup', 'resource' => 'backups', 'amount' => 1, 'price_tokens' => 50, 'max_units' => 5,
    ])->assertCreated()->assertJsonPath('resource', 'backups');
    $this->postJson(route('api.admin.shop.addons.store'), [
        'key' => 'extra-backup', 'name' => 'Dupe', 'resource' => 'gpus', 'amount' => 1, 'price_tokens' => 50, 'max_units' => 5,
    ])->assertUnprocessable();
});

test('stores the shop settings and reports capacity', function (): void {
    $this->putJson(route('api.admin.shop.settings.update'), [
        'enabled' => true,
        'grace_days' => 0,
        'low_balance_days' => 2,
        'limits' => ['memory' => 20480, 'disk' => 0, 'cpu' => 1500],
    ])->assertOk()
        ->assertJsonPath('enabled', true)
        ->assertJsonPath('grace_days', 0)
        ->assertJsonPath('limits.memory', 20480)
        ->assertJsonPath('remaining.memory', 20480)
        ->assertJsonPath('remaining.disk', null);

    expect(Setting::fetch('settings::monoforge:shop:grace_days'))->toBe('0')
        ->and(Setting::fetch('settings::monoforge:shop:enabled'))->toBe('true')
        ->and(Setting::fetch('settings::monoforge:shop:limits:cpu_percent'))->toBe('1500');

    $this->putJson(route('api.admin.shop.settings.update'), [
        'enabled' => true, 'grace_days' => 31, 'low_balance_days' => 2, 'limits' => ['memory' => 0, 'disk' => 0, 'cpu' => 0],
    ])->assertUnprocessable();
});

test('lists eggs, nodes and locations for the editors without node addresses', function (): void {
    Node::factory()->create(['location_id' => Location::factory()->create()->id]);

    $response = $this->getJson(route('api.admin.shop.options'))->assertOk();

    expect($response->json('eggs'))->not->toBeEmpty()
        ->and(array_keys($response->json('nodes.0')))->toBe(['id', 'name', 'location_id', 'public', 'maintenance', 'shop_enabled']);
});

test('non admins are forbidden', function (): void {
    $this->actingAsNonAdmin();

    $this->assertAccessDeniedJson($this->getJson(route('api.admin.shop.plans')));
    $this->assertAccessDeniedJson($this->putJson(route('api.admin.shop.settings.update'), ['enabled' => true]));
});
