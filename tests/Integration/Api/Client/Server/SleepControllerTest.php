<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Client\Server\SleepControllerTest;

use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Notification;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Models\Billing\ServerSleepSession;
use Pterodactyl\Models\Billing\ServerSubscription;
use Pterodactyl\Models\Billing\ShopCategory;
use Pterodactyl\Models\Billing\ShopPlan;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Subuser;
use Pterodactyl\Models\User;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;
use Pterodactyl\Tests\Support\Fakes\FakeDaemonPower;

uses(ClientApiIntegrationTestCase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-20 12:00:00');
    Notification::fake();
    $this->power = new FakeDaemonPower;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * A shop server whose plan allows auto-sleep (the test egg is BungeeCord, a Minecraft Java egg).
 *
 * @return array{User, Server, ServerSubscription}
 */
function shopServer(object $test, array $plan = [], array $attributes = []): array
{
    static $count = 0;
    $count++;
    [$user, $server] = $test->generateTestAccount();
    $category = ShopCategory::query()->create(['name' => "Plans {$count}", 'slug' => "plans-{$count}"]);
    $shopPlan = ShopPlan::query()->create([
        'category_id' => $category->id,
        'name' => 'Stone',
        'price_tokens' => 500,
        'memory' => 2048,
        'disk' => 10240,
        'cpu' => 100,
        'egg_ids' => [$server->egg_id],
        'sleep_allowed' => true,
        'sleep_discount_percent' => 40,
        'sleep_idle_minutes' => 20,
        ...$plan,
    ]);

    $subscription = ServerSubscription::query()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'plan_id' => $shopPlan->id,
        'period_days' => 30,
        'price_tokens' => 500,
        'addons' => [],
        'next_renewal_at' => CarbonImmutable::now()->addDays(10),
        ...$attributes,
    ]);

    return [$user, $server, $subscription];
}

function asleep(Server $server, ServerSubscription $subscription, string $since = '2026-11-20 06:00:00'): void
{
    $subscription->forceFill(['auto_sleep' => true, 'sleep_started_at' => CarbonImmutable::parse($since)])->save();
    ServerSleepSession::query()->create(['server_id' => $server->id, 'started_at' => CarbonImmutable::parse($since), 'discount_percent' => 40]);
}

function subuserOf(Server $server, array $permissions): User
{
    $user = User::factory()->create();
    Subuser::query()->create(['user_id' => $user->id, 'server_id' => $server->id, 'permissions' => $permissions]);

    return $user;
}

test('the owner turns auto-sleep on and off, and the billing shows the sleep', function (): void {
    [$user, $server, $subscription] = shopServer($this);
    // 5 of the 30 days slept so far: 500 × 5/30 × 40% = 33.3 → 33 saved.
    ServerSleepSession::query()->create(['server_id' => $server->id, 'started_at' => CarbonImmutable::parse('2026-11-01 00:00:00'), 'ended_at' => CarbonImmutable::parse('2026-11-06 00:00:00'), 'discount_percent' => 40]);
    $subscription->forceFill(['last_sleep_saved_tokens' => 120])->save();

    $this->actingAs($user)->patchJson("/api/client/subscriptions/{$server->uuidShort}", ['auto_sleep' => true])
        ->assertOk()
        ->assertJsonPath('attributes.auto_renew', true)
        ->assertJsonPath('attributes.sleep.enabled', true)
        ->assertJsonPath('attributes.sleep.allowed', true)
        ->assertJsonPath('attributes.sleep.supported', true)
        ->assertJsonPath('attributes.sleep.sleeping', false)
        ->assertJsonPath('attributes.sleep.idle_minutes', 20)
        ->assertJsonPath('attributes.sleep.discount_percent', 40)
        ->assertJsonPath('attributes.sleep.slept_seconds_this_period', 5 * 86_400)
        ->assertJsonPath('attributes.sleep.saved_this_period', 33)
        ->assertJsonPath('attributes.sleep.last_saved_tokens', 120);
    $this->assertActivityFor('server:subscription.auto-sleep', $user, $server);
    expect($subscription->fresh()->auto_sleep)->toBeTrue();

    $this->actingAs($user)->patchJson("/api/client/subscriptions/{$server->uuidShort}", ['auto_sleep' => false, 'auto_renew' => false])
        ->assertOk()
        ->assertJsonPath('attributes.sleep.enabled', false)
        ->assertJsonPath('attributes.auto_renew', false);

    $this->actingAs($user)->getJson('/api/client/subscriptions')
        ->assertOk()
        ->assertJsonPath('data.0.attributes.sleep.allowed', true);
});

test('auto-sleep is refused without a plan that allows it or a supported game, and for others', function (): void {
    [$user, $server, $subscription] = shopServer($this, ['sleep_allowed' => false]);

    $this->actingAs($user)->patchJson("/api/client/subscriptions/{$server->uuidShort}", ['auto_sleep' => true])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.detail', "This server's plan does not include auto-sleep.");

    // Turning it off is always fine.
    $this->actingAs($user)->patchJson("/api/client/subscriptions/{$server->uuidShort}", ['auto_sleep' => false])->assertOk();

    [$owner, $other] = shopServer($this);
    $egg = $this->cloneEggAndVariables($other->egg);
    $egg->forceFill(['name' => 'Valheim'])->save();
    $other->forceFill(['egg_id' => $egg->id])->save();

    $this->actingAs($owner)->patchJson("/api/client/subscriptions/{$other->uuidShort}", ['auto_sleep' => true])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.detail', 'Auto-sleep is not supported for this game yet.');
    $this->actingAs($owner)->getJson('/api/client/subscriptions')
        ->assertJsonPath('data.0.attributes.sleep.supported', false);

    // A subuser does not pay, so cannot change the billing.
    $subuser = subuserOf($other, [Permissions::ControlStart->value]);
    $this->actingAs($subuser)->patchJson("/api/client/subscriptions/{$other->uuidShort}", ['auto_sleep' => false])->assertNotFound();

    // Something must be sent.
    $this->actingAs($user)->patchJson("/api/client/subscriptions/{$server->uuidShort}", [])->assertUnprocessable();
    expect($subscription->fresh()->auto_sleep)->toBeFalse();
});

test('anyone with access reads the sleep state; only control.start may wake', function (): void {
    [$user, $server, $subscription] = shopServer($this);
    asleep($server, $subscription);
    $viewer = subuserOf($server, [Permissions::ControlConsole->value]);

    $this->actingAs($viewer)->getJson("/api/client/servers/{$server->uuidShort}/sleep")
        ->assertOk()
        ->assertJsonPath('object', 'server_sleep')
        ->assertJsonPath('attributes.available', true)
        ->assertJsonPath('attributes.supported', true)
        ->assertJsonPath('attributes.enabled', true)
        ->assertJsonPath('attributes.sleeping', true)
        ->assertJsonPath('attributes.sleep_started_at', '2026-11-20T06:00:00+00:00')
        ->assertJsonPath('attributes.can_wake', false);

    $this->actingAs($viewer)->postJson("/api/client/servers/{$server->uuidShort}/sleep/wake")->assertForbidden();
    $this->power->assertNothingSent();

    $starter = subuserOf($server, [Permissions::ControlStart->value]);
    $this->actingAs($starter)->getJson("/api/client/servers/{$server->uuidShort}/sleep")->assertJsonPath('attributes.can_wake', true);
    $this->actingAs($starter)->postJson("/api/client/servers/{$server->uuidShort}/sleep/wake")->assertNoContent();

    $this->power->assertSentTo('start', $server->uuid);
    $this->assertActivityFor('server:sleep.wake', $starter, $server);
    expect($subscription->fresh()->sleep_started_at)->toBeNull()
        ->and(ServerSleepSession::query()->value('ended_at'))->toEqual(CarbonImmutable::now());

    // Awake now.
    $this->actingAs($user)->postJson("/api/client/servers/{$server->uuidShort}/sleep/wake")
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertJsonPath('errors.0.detail', 'This server is not sleeping.');
    $this->actingAs($user)->getJson("/api/client/servers/{$server->uuidShort}/sleep")
        ->assertJsonPath('attributes.sleeping', false)
        ->assertJsonPath('attributes.sleep_started_at', null)
        ->assertJsonPath('attributes.can_wake', true);
});

test('a server bought outside the shop never sleeps', function (): void {
    [$user, $server] = $this->generateTestAccount();

    $this->actingAs($user)->getJson("/api/client/servers/{$server->uuidShort}/sleep")
        ->assertOk()
        ->assertJsonPath('attributes.available', false)
        ->assertJsonPath('attributes.enabled', false)
        ->assertJsonPath('attributes.sleeping', false);
    $this->actingAs($user)->postJson("/api/client/servers/{$server->uuidShort}/sleep/wake")->assertStatus(Response::HTTP_CONFLICT);
});

test('a wake Wings refuses answers 503 and the server keeps sleeping', function (): void {
    [$user, $server, $subscription] = shopServer($this);
    asleep($server, $subscription);
    $this->power->response = new \GuzzleHttp\Psr7\Response(502);

    $this->actingAs($user)->postJson("/api/client/servers/{$server->uuidShort}/sleep/wake")
        ->assertStatus(Response::HTTP_SERVICE_UNAVAILABLE)
        ->assertJsonPath('errors.0.detail', 'The server could not be woken right now. Try again in a minute.');

    expect($subscription->fresh()->isSleeping())->toBeTrue();
});
