<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Admin\Shop\ShopTrialControllerTest;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Models\Billing\ServerSubscription;
use Pterodactyl\Models\Billing\ShopCategory;
use Pterodactyl\Models\Billing\ShopPlan;
use Pterodactyl\Models\Billing\ShopTrial;
use Pterodactyl\Models\Setting;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Billing\TrialSettings;
use Pterodactyl\Tests\Integration\Api\Admin\AdminApiIntegrationTestCase;

uses(AdminApiIntegrationTestCase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-20 12:00:00');
    $category = ShopCategory::query()->forceCreate(['name' => 'Minecraft', 'slug' => 'minecraft-'.uniqid(), 'sort' => 0, 'visible' => true])->id;
    $this->plan = ShopPlan::query()->forceCreate([
        'category_id' => $category, 'name' => 'Starter', 'description' => '', 'visible' => true, 'sort' => 0,
        'price_tokens' => 500, 'memory' => 2048, 'swap' => 0, 'disk' => 10000, 'cpu' => 100, 'io' => 500,
        'databases' => 1, 'backups' => 1, 'allocations' => 0, 'egg_ids' => [], 'node_ids' => null,
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

test('updates the trial settings and stores them', function (): void {
    $this->putJson(route('api.admin.shop.trials.update'), [
        'enabled' => true,
        'plan_ids' => [$this->plan->id],
        'hours' => 72,
        'max_concurrent' => 5,
        'daily_cap' => 3,
        'require_verified' => false,
    ])->assertOk()->assertExactJson([
        'enabled' => true,
        'plan_ids' => [$this->plan->id],
        'hours' => 72,
        'max_concurrent' => 5,
        'daily_cap' => 3,
        'require_verified' => false,
    ]);

    expect(TrialSettings::all())->toBe(['enabled' => true, 'plan_ids' => [$this->plan->id], 'hours' => 72, 'max_concurrent' => 5, 'daily_cap' => 3, 'require_verified' => false])
        ->and(Setting::query()->where('key', 'settings::monoforge:shop:trials:plan_ids')->value('value'))->toBe((string) $this->plan->id)
        ->and(Setting::query()->where('key', 'settings::monoforge:shop:trials:hours')->value('value'))->toBe('72');
    $this->assertActivityLogged('admin:shop.trials.update');
});

test('refuses other trial lengths, unknown plans and negative limits', function (array $body): void {
    $valid = ['enabled' => true, 'plan_ids' => [$this->plan->id], 'hours' => 48, 'max_concurrent' => 5, 'require_verified' => true];

    $this->putJson(route('api.admin.shop.trials.update'), [...$valid, ...$body])->assertUnprocessable();
})->with([
    'hours 36' => [['hours' => 36]],
    'unknown plan' => [['plan_ids' => [999999]]],
    'negative max' => [['max_concurrent' => -1]],
    'negative daily cap' => [['daily_cap' => -1]],
    'no plan list' => [['plan_ids' => null]],
]);

test('lists the trials with their state and the conversion rate', function (): void {
    $server = $this->createServerModel();
    ServerSubscription::query()->create([
        'server_id' => $server->id, 'user_id' => $server->owner_id, 'period_days' => 2, 'price_tokens' => 0, 'addons' => [],
        'status' => ServerSubscription::STATUS_TRIAL, 'is_trial' => true, 'auto_renew' => false, 'next_renewal_at' => CarbonImmutable::now()->addDay(),
    ]);
    $running = ShopTrial::query()->create(['user_id' => $server->owner_id, 'server_id' => $server->id, 'plan_id' => $this->plan->id, 'subnet_hash' => str_repeat('a', 64), 'hours' => 48, 'ends_at' => CarbonImmutable::now()->addDay()]);
    ShopTrial::query()->create(['user_id' => User::factory()->create()->id, 'plan_id' => $this->plan->id, 'hours' => 48, 'ends_at' => CarbonImmutable::now()->subDay(), 'converted_at' => CarbonImmutable::now()->subDay()]);
    ShopTrial::query()->create(['user_id' => null, 'plan_id' => null, 'hours' => 24, 'ends_at' => CarbonImmutable::now()->subDays(3), 'expired_at' => CarbonImmutable::now()->subDays(3)]);

    $response = $this->getJson(route('api.admin.shop.trials'))
        ->assertOk()
        ->assertJsonPath('stats', ['started' => 3, 'running' => 1, 'converted' => 1, 'lapsed' => 1, 'conversion_rate' => 50])
        ->assertJsonPath('trials.2.id', $running->id)
        ->assertJsonPath('trials.2.state', 'running')
        ->assertJsonPath('trials.2.server', ['id' => $server->id, 'identifier' => $server->uuidShort, 'name' => $server->name])
        ->assertJsonPath('trials.2.plan', ['id' => $this->plan->id, 'name' => 'Starter'])
        ->assertJsonPath('trials.1.state', 'converted')
        ->assertJsonPath('trials.0.state', 'deleted')
        ->assertJsonPath('trials.0.user', null);

    // No hashes leave the panel.
    expect($response->getContent())->not->toContain(str_repeat('a', 64));
});

test('reading needs the shop read permission and changing needs the update permission', function (): void {
    $user = $this->actingAsNonAdmin();
    $this->getJson(route('api.admin.shop.trials'))->assertForbidden();

    // Reading the shop is not enough to change it.
    Gate::define(Permissions::AdminShopRead->value, fn (User $actor): bool => $actor->is($user));
    $this->putJson(route('api.admin.shop.trials.update'), ['enabled' => true, 'plan_ids' => [], 'hours' => 48, 'max_concurrent' => 1, 'require_verified' => true])->assertForbidden();

    expect(TrialSettings::enabled())->toBeFalse();
});
