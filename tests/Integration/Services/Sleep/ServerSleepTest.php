<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Services\Sleep\ServerSleepTest;

use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule as ConsoleSchedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Pterodactyl\Console\Scheduler;
use Pterodactyl\Contracts\Servers\SendsServerPower;
use Pterodactyl\Contracts\Servers\WakesServers;
use Pterodactyl\Jobs\Schedule\RunTaskJob;
use Pterodactyl\Models\ActivityLog;
use Pterodactyl\Models\Billing\ServerSleepSession;
use Pterodactyl\Models\Billing\ServerSubscription;
use Pterodactyl\Models\Billing\ShopCategory;
use Pterodactyl\Models\Billing\ShopPlan;
use Pterodactyl\Models\Billing\TokenTransaction;
use Pterodactyl\Models\Schedule;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Task;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Assistant\CrashDoctor\CrashRecorder;
use Pterodactyl\Services\Billing\SubscriptionService;
use Pterodactyl\Services\Billing\TokenWallet;
use Pterodactyl\Services\Query\QueryConnector;
use Pterodactyl\Services\Query\QueryStatus;
use Pterodactyl\Services\Query\ServerQuery;
use Pterodactyl\Services\Sleep\ServerSleep;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Tests\Support\Fakes\FakeDaemonPower;
use Pterodactyl\Tests\Support\Fakes\FakeDaemonServer;
use Pterodactyl\Tests\Support\Fakes\FakeMinecraftServer;

uses(IntegrationTestCase::class, DatabaseTransactions::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-20 12:00:00');
    Notification::fake();
    config()->set('logging.default', 'null');
    Cache::flush();

    $this->daemon = new FakeDaemonServer;
    $this->daemon->details = ['state' => 'running', 'is_suspended' => false, 'utilization' => []];

    $this->power = new FakeDaemonPower;
    $this->minecraft = new FakeMinecraftServer;
    $this->app->instance(QueryConnector::class, $this->minecraft);

    $this->wallet = $this->app->make(TokenWallet::class);
    $this->app->instance(TokenWallet::class, $this->wallet);
    $this->sleep = $this->app->make(ServerSleep::class);
    $this->subscriptions = $this->app->make(SubscriptionService::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function sleepPlan(array $attributes = []): ShopPlan
{
    static $count = 0;
    $count++;
    $category = ShopCategory::query()->create(['name' => "Sleepy {$count}", 'slug' => "sleepy-{$count}"]);

    return ShopPlan::query()->create([
        'category_id' => $category->id,
        'name' => 'Stone',
        'price_tokens' => 500,
        'memory' => 2048,
        'disk' => 10240,
        'cpu' => 100,
        'egg_ids' => [1],
        'sleep_allowed' => true,
        'sleep_discount_percent' => 50,
        'sleep_idle_minutes' => 3,
        ...$attributes,
    ]);
}

/**
 * A shop server (the test egg is BungeeCord, a Minecraft Java egg) with auto-sleep on.
 *
 * @return array{User, Server, ServerSubscription}
 */
function sleepyServer(object $test, array $plan = [], array $attributes = [], int $balance = 0): array
{
    [$user, $server] = $test->generateTestAccount();
    if ($balance > 0) {
        $test->wallet->credit($user, $balance, 'admin_adjust', 'Test balance');
    }

    $subscription = ServerSubscription::query()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'plan_id' => sleepPlan($plan)->id,
        'period_days' => 30,
        'price_tokens' => 500,
        'addons' => [],
        'next_renewal_at' => CarbonImmutable::now()->addDays(10),
        'auto_sleep' => true,
        ...$attributes,
    ]);

    return [$user, $server, $subscription];
}

/** One scheduler pass, with the cached Wings and player readings expired as a minute later would. */
function tick(object $test): array
{
    Cache::flush();

    return $test->sleep->check();
}

/**
 * A recorded sleep, at the discount given or (like a real sleep) the server's plan's discount now,
 * that the per-minute check confirmed offline the whole time (unless $confirmedMinutes says less).
 */
function session(Server $server, string $from, ?string $to, ?int $discount = null, ?int $confirmedMinutes = null): void
{
    $discount ??= ServerSubscription::query()->where('server_id', $server->id)->first()?->plan?->sleepDiscountPercent() ?? 0;
    $start = CarbonImmutable::parse($from);
    $end = $to === null ? null : CarbonImmutable::parse($to);
    $confirmedMinutes ??= intdiv(max(0, ($end ?? CarbonImmutable::now())->getTimestamp() - $start->getTimestamp()), 60);

    ServerSleepSession::query()->create(['server_id' => $server->id, 'started_at' => $start, 'ended_at' => $end, 'discount_percent' => $discount, 'confirmed_offline_minutes' => $confirmedMinutes]);
}

/** The minutes after a sleep: Wings reports it offline and the scheduler runs once a minute. */
function sleepOfflineFor(object $test, int $minutes): void
{
    $test->daemon->details['state'] = 'offline';
    for ($i = 0; $i < $minutes; $i++) {
        $test->travel(1)->minutes();
        tick($test);
    }
}

// ServerQuery

test('ServerQuery pings the main allocation, or the node when it listens on every address', function (): void {
    [, $server] = sleepyServer($this);
    $server->allocation->forceFill(['ip' => '10.0.0.5', 'port' => 25570])->save();
    $this->minecraft->online(2, ['Alex', 'Steve']);

    expect(resolve(ServerQuery::class)->status($server->fresh()))->toEqual(new QueryStatus(2, 20, 'A Minecraft Server', 'Paper 1.21.4', ['Alex', 'Steve']))
        ->and($this->minecraft->connections[0])->toBe(['host' => '10.0.0.5', 'port' => 25570, 'timeout' => 2.0]);

    Cache::flush();
    $server->allocation->forceFill(['ip' => '0.0.0.0'])->save();
    $server->node->forceFill(['fqdn' => 'node1.example.test'])->save();
    resolve(ServerQuery::class)->status($server->fresh());

    expect($this->minecraft->connections[1]['host'])->toBe('node1.example.test');
});

test('ServerQuery caches answers, and no answer, for 30 seconds per server', function (): void {
    [, $server] = sleepyServer($this);
    $this->minecraft->accepts = false;

    expect(resolve(ServerQuery::class)->status($server))->toBeNull()
        ->and(resolve(ServerQuery::class)->status($server))->toBeNull()
        ->and($this->minecraft->connections)->toHaveCount(1);

    $this->travel(31)->seconds();
    $this->minecraft->accepts = true;

    expect(resolve(ServerQuery::class)->status($server)?->online)->toBe(0)
        ->and($this->minecraft->connections)->toHaveCount(2);
});

test('ServerQuery answers null without connecting for games it does not support', function (): void {
    [, $server] = sleepyServer($this);
    $egg = $this->cloneEggAndVariables($server->egg);
    $egg->forceFill(['name' => 'Valheim'])->save();
    $server->forceFill(['egg_id' => $egg->id])->save();

    expect(ServerQuery::supports($server->fresh()))->toBeFalse()
        ->and(resolve(ServerQuery::class)->status($server->fresh()))->toBeNull()
        ->and($this->minecraft->connections)->toBe([]);
});

// Falling asleep

test('a running server nobody plays on sleeps after the idle minutes', function (): void {
    [, $server, $subscription] = sleepyServer($this);

    expect(tick($this))->toBe(['asleep' => 0, 'awake' => 0])
        ->and(tick($this))->toBe(['asleep' => 0, 'awake' => 0])
        ->and($subscription->fresh()->idle_checks)->toBe(2);
    $this->power->assertNothingSent();

    expect(tick($this))->toBe(['asleep' => 1, 'awake' => 0]);

    $this->power->assertSentTo('stop', $server->uuid);
    $fresh = $subscription->fresh();
    expect($fresh->sleep_started_at)->toEqual(CarbonImmutable::now())
        ->and($fresh->idle_checks)->toBe(0)
        ->and($fresh->isSleeping())->toBeTrue()
        ->and($this->sleep->isSleeping($server))->toBeTrue()
        ->and(ServerSleepSession::query()->where('server_id', $server->id)->whereNull('ended_at')->sole()->discount_percent)->toBe(50)
        ->and(ActivityLog::query()->where('event', 'server:sleep.asleep')->firstOrFail()->properties->get('minutes'))->toBe(3);

    // Sleeping (Wings says it is stopping, then offline): nothing more is sent.
    $this->daemon->details['state'] = 'offline';
    expect(tick($this))->toBe(['asleep' => 0, 'awake' => 0]);
    $this->power->assertSentTimes('stop', 1);
});

test('a player, an unanswered ping or a stopped server starts the idle count again', function (): void {
    [, , $subscription] = sleepyServer($this);

    tick($this);
    tick($this);
    $this->minecraft->online(1);
    tick($this);
    expect($subscription->fresh()->idle_checks)->toBe(0);

    $this->minecraft->online(0);
    tick($this);
    $this->minecraft->accepts = false;
    tick($this);
    expect($subscription->fresh()->idle_checks)->toBe(0);

    $this->minecraft->accepts = true;
    tick($this);
    $this->daemon->details['state'] = 'offline';
    tick($this);
    expect($subscription->fresh()->idle_checks)->toBe(0);

    $this->power->assertNothingSent();
});

test('nothing sleeps without the plan, the switch, a supported game or an active subscription', function (array $plan, array $attributes): void {
    [, $server, $subscription] = sleepyServer($this, $plan, ['idle_checks' => 5, ...$attributes]);

    foreach (range(1, 4) as $minute) {
        tick($this);
    }

    $this->power->assertNothingSent();
    expect($subscription->fresh()->sleep_started_at)->toBeNull()
        ->and($this->sleep->isSleeping($server))->toBeFalse();
    if ($subscription->fresh()->isActive()) {
        expect($subscription->fresh()->idle_checks)->toBe(0);
    }
})->with([
    'the plan does not allow it' => [['sleep_allowed' => false], []],
    'auto-sleep is off' => [[], ['auto_sleep' => false]],
    'the subscription is suspended' => [[], ['status' => ServerSubscription::STATUS_SUSPENDED]],
    'the subscription is cancelled' => [[], ['status' => ServerSubscription::STATUS_CANCELLED]],
]);

test('a game that cannot be asked never sleeps', function (): void {
    [, $server, $subscription] = sleepyServer($this);
    $egg = $this->cloneEggAndVariables($server->egg);
    $egg->forceFill(['name' => 'Terraria'])->save();
    $server->forceFill(['egg_id' => $egg->id])->save();

    foreach (range(1, 4) as $minute) {
        tick($this);
    }

    $this->power->assertNothingSent();
    expect($this->minecraft->connections)->toBe([])
        ->and($subscription->fresh()->sleep_started_at)->toBeNull();
});

test('a suspended or installing server is left alone', function (): void {
    [, $server, $subscription] = sleepyServer($this, attributes: ['idle_checks' => 2]);
    $server->forceFill(['status' => Server::STATUS_INSTALLING])->save();

    tick($this);

    $this->power->assertNothingSent();
    expect($subscription->fresh()->idle_checks)->toBe(0);
});

test('Wings refusing the stop keeps the server awake and tries again', function (): void {
    [, , $subscription] = sleepyServer($this, attributes: ['idle_checks' => 2]);
    $this->power->response = new Response(500);

    expect(tick($this))->toBe(['asleep' => 0, 'awake' => 0])
        ->and($subscription->fresh()->sleep_started_at)->toBeNull()
        ->and(ServerSleepSession::query()->count())->toBe(0);

    $this->power->response = null;
    expect(tick($this))->toBe(['asleep' => 1, 'awake' => 0]);
});

// Waking up

test('a sleeping server somebody started from the console is awake again', function (): void {
    [, $server, $subscription] = sleepyServer($this, attributes: ['idle_checks' => 2]);
    tick($this);
    expect($subscription->fresh()->isSleeping())->toBeTrue();

    // Still "running" while it saves and stops: not a start within the settle time.
    $this->travel(30)->seconds();
    expect(tick($this))->toBe(['asleep' => 0, 'awake' => 0]);

    // "starting" is always somebody starting it.
    $this->daemon->details['state'] = 'starting';
    expect(tick($this))->toBe(['asleep' => 0, 'awake' => 1])
        ->and($subscription->fresh()->sleep_started_at)->toBeNull()
        ->and(ServerSleepSession::query()->where('server_id', $server->id)->value('ended_at'))->toEqual(CarbonImmutable::now());
});

test('a sleeping server still running after the settle time counts as started', function (): void {
    [, , $subscription] = sleepyServer($this, attributes: ['idle_checks' => 2]);
    tick($this);

    $this->travel(ServerSleep::SETTLE_SECONDS + 1)->seconds();
    expect(tick($this))->toBe(['asleep' => 0, 'awake' => 1])
        ->and($subscription->fresh()->isSleeping())->toBeFalse();
});

test('a server that ignores the stop ends its sleep after the settle time, whatever Wings says', function (string $state): void {
    [, $server, $subscription] = sleepyServer($this, attributes: ['idle_checks' => 2]);
    tick($this);
    $this->daemon->details['state'] = $state;

    // Within the settle time a slow graceful stop is allowed...
    $this->travel(ServerSleep::SETTLE_SECONDS - 10)->seconds();
    expect(tick($this))->toBe(['asleep' => 0, 'awake' => 0]);

    // ...after it, any state but offline ends the sleep, and none of that time is discounted.
    $this->travel(20)->seconds();
    expect(tick($this))->toBe(['asleep' => 0, 'awake' => 1])
        ->and($subscription->fresh()->isSleeping())->toBeFalse()
        ->and(ServerSleepSession::query()->where('server_id', $server->id)->sole()->confirmed_offline_minutes)->toBe(0)
        ->and($this->sleep->sleptSeconds($server->id, CarbonImmutable::now()->subDay(), CarbonImmutable::now()))->toBe(0);
})->with(['stopping', 'running']);

test('only minutes Wings confirmed offline are discounted, one per minute', function (): void {
    [, $server] = sleepyServer($this, attributes: ['idle_checks' => 2]);
    tick($this);
    sleepOfflineFor($this, 5);

    // A second pass in the same minute confirms nothing more.
    Cache::flush();
    $this->sleep->check();
    $session = ServerSleepSession::query()->where('server_id', $server->id)->sole();
    expect($session->confirmed_offline_minutes)->toBe(5);

    // An hour nobody observed: the server is still asleep but earns nothing for it.
    $this->travel(1)->hours();
    expect($this->sleep->sleptSeconds($server->id, CarbonImmutable::now()->subDay(), CarbonImmutable::now()))->toBe(5 * 60);
});

test('the sleep is closed when the subscription is suspended or cancelled', function (string $how): void {
    [, $server, $subscription] = sleepyServer($this, attributes: ['idle_checks' => 2, 'next_renewal_at' => CarbonImmutable::now()]);
    tick($this);
    expect($subscription->fresh()->isSleeping())->toBeTrue();

    match ($how) {
        'suspend' => $this->subscriptions->suspend($subscription->fresh()),
        'cancel' => $this->subscriptions->cancel($subscription->fresh()),
    };

    expect(ServerSleepSession::query()->where('server_id', $server->id)->whereNull('ended_at')->exists())->toBeFalse()
        ->and($subscription->fresh()->sleep_started_at)->toBeNull();
})->with(['suspend', 'cancel']);

test('the auto-sleep stop and wake are not taken for crashes', function (): void {
    [, $server] = sleepyServer($this, attributes: ['idle_checks' => 2]);
    tick($this);
    expect(resolve(CrashRecorder::class)->stopRequestedSince($server, CarbonImmutable::now()->subMinute()))->toBeTrue();
});

test('a game that keeps timing out is skipped, and the pings stop at the pass budget', function (): void {
    [, $server, $subscription] = sleepyServer($this);
    Cache::put('server-query:timeouts:'.$server->uuid, ServerQuery::UNRESPONSIVE_AFTER, 60);
    $this->sleep->check();
    expect($subscription->fresh()->idle_checks)->toBe(0)
        ->and($this->minecraft->connections)->toBe([]);
});

test('a public wake never stores the visitor address', function (): void {
    [, $server] = sleepyServer($this, attributes: ['idle_checks' => 2]);
    tick($this);
    Cache::flush();

    expect($this->sleep->wake($server, public: true))->toBeTrue()
        ->and(ActivityLog::query()->where('event', 'server:sleep.wake')->sole()->ip)->toBe('');
});

test('starting the server through the panel or a schedule wakes it', function (): void {
    [, $server, $subscription] = sleepyServer($this, attributes: ['idle_checks' => 2]);
    tick($this);

    resolve(SendsServerPower::class)->send($server, 'start');

    expect($subscription->fresh()->sleep_started_at)->toBeNull()
        ->and(ServerSleepSession::query()->whereNull('ended_at')->count())->toBe(0);
});

test('a schedule that starts the server wakes it, unless Wings refused the start', function (): void {
    [, $server, $subscription] = sleepyServer($this, attributes: ['idle_checks' => 2]);
    tick($this);
    $schedule = Schedule::factory()->create(['server_id' => $server->id]);
    $task = Task::factory()->create(['schedule_id' => $schedule->id, 'action' => Task::ACTION_POWER, 'payload' => 'start', 'continue_on_failure' => true]);

    $this->power->response = new Response(502);
    Bus::dispatchSync(new RunTaskJob($task));
    expect($subscription->fresh()->isSleeping())->toBeTrue();

    $this->power->response = null;
    Bus::dispatchSync(new RunTaskJob($task->fresh()));
    expect($subscription->fresh()->isSleeping())->toBeFalse();
});

test('wake starts a sleeping server once and ends its sleep', function (): void {
    [, $server, $subscription] = sleepyServer($this, attributes: ['idle_checks' => 2]);
    tick($this);
    sleepOfflineFor($this, 3);
    // Two hours nobody checked (the scheduler was down): not confirmed, so not discounted.
    $this->travel(2)->hours();

    $wakes = resolve(WakesServers::class);
    expect($wakes)->toBeInstanceOf(ServerSleep::class)
        ->and($wakes->isSleeping($server))->toBeTrue()
        ->and($wakes->wake($server))->toBeTrue();

    $this->power->assertSentTo('start', $server->uuid);
    expect($wakes->isSleeping($server))->toBeFalse()
        ->and($subscription->fresh()->sleep_started_at)->toBeNull()
        ->and($this->sleep->sleptSeconds($server->id, CarbonImmutable::now()->subDay(), CarbonImmutable::now()))->toBe(180)
        ->and(ActivityLog::query()->where('event', 'server:sleep.wake')->exists())->toBeTrue();

    // Awake now: another wake sends nothing.
    expect($wakes->wake($server))->toBeFalse();
    $this->power->assertSentTimes('start', 1);
});

test('wake does nothing for a server that is not sleeping, and keeps it asleep when Wings refuses', function (): void {
    [, $server] = sleepyServer($this, attributes: ['idle_checks' => 2]);
    [, $other] = $this->generateTestAccount();

    expect($this->sleep->wake($server))->toBeFalse()
        ->and($this->sleep->wake($other))->toBeFalse();
    $this->power->assertNothingSent();

    tick($this);
    $this->power->response = new Response(502);
    Cache::flush();

    expect($this->sleep->wake($server))->toBeFalse()
        ->and($this->sleep->isSleeping($server))->toBeTrue();

    // The lock was released, so the next attempt goes through.
    $this->power->response = null;
    expect($this->sleep->wake($server))->toBeTrue();
});

// Recording sleep

test('sums the sleep inside a window, counting an open sleep until now and overlaps once', function (): void {
    [, $server] = sleepyServer($this);
    [, $other] = $this->generateTestAccount();
    session($server, '2026-11-01 00:00:00', '2026-11-02 00:00:00'); // before the window
    session($server, '2026-11-09 18:00:00', '2026-11-10 06:00:00'); // 6 h inside
    session($server, '2026-11-12 00:00:00', '2026-11-12 10:00:00'); // 10 h
    session($server, '2026-11-12 08:00:00', '2026-11-12 12:00:00'); // overlaps: 2 more hours
    session($server, '2026-11-20 06:00:00', null); // still asleep: 6 h until now
    session($other, '2026-11-12 00:00:00', null); // another server

    expect($this->sleep->sleptSeconds($server->id, CarbonImmutable::parse('2026-11-10 00:00:00'), CarbonImmutable::parse('2026-11-25 00:00:00')))
        ->toBe((6 + 10 + 2 + 6) * 3600)
        ->and($this->sleep->sleptSeconds($server->id, CarbonImmutable::now(), CarbonImmutable::now()->addDay()))->toBe(0);
});

// Billing

test('the renewal discounts the time the server slept in the period, once', function (): void {
    [$user, $server, $subscription] = sleepyServer($this, attributes: ['next_renewal_at' => CarbonImmutable::now()], balance: 1000);
    // Half of the 30-day period that ends now, at 50%: 500 × (0.5 + 0.5 × 0.5) = 375.
    session($server, '2026-10-21 12:00:00', '2026-11-05 12:00:00');
    // Before the period: not counted.
    session($server, '2026-10-01 00:00:00', '2026-10-15 00:00:00');

    expect($this->subscriptions->renew($subscription))->toBeTrue()
        ->and($this->wallet->balance($user))->toBe(625);

    $charge = TokenTransaction::query()->where('reference_type', SubscriptionService::REFERENCE_TYPE)->sole();
    expect($charge->amount)->toBe(-375)
        ->and($charge->description)->toContain('125 tokens saved by sleeping')
        ->and($charge->meta)->toMatchArray(['full_price' => 500, 'sleep_seconds' => 15 * 86_400, 'sleep_discount_percent' => 50, 'sleep_saved_tokens' => 125])
        ->and($subscription->fresh()->last_sleep_saved_tokens)->toBe(125)
        ->and($subscription->fresh()->next_renewal_at)->toEqual(CarbonImmutable::now()->addDays(30));

    // Re-runs and a stale copy charge nothing more.
    $stale = ServerSubscription::query()->findOrFail($subscription->id);
    $stale->next_renewal_at = CarbonImmutable::now();
    expect($this->subscriptions->renew($subscription))->toBeTrue()
        ->and($this->subscriptions->renew($stale))->toBeTrue()
        ->and($this->subscriptions->renewDue())->toBe(0)
        ->and($this->wallet->balance($user))->toBe(625);
});

test('each sleep keeps the discount it started with, whatever the plan says at the renewal', function (): void {
    [$user, $server, $subscription] = sleepyServer($this, attributes: ['next_renewal_at' => CarbonImmutable::now()->addDays(10)], balance: 1000);
    $plan = $subscription->plan;

    // The server falls asleep while the plan gives 50% (3 idle checks), and sleeps 6 days.
    tick($this);
    tick($this);
    tick($this);
    expect(ServerSleepSession::query()->where('server_id', $server->id)->sole()->discount_percent)->toBe(50);
    $this->travelTo(CarbonImmutable::now()->addDays(6));
    ServerSleepSession::query()->where('server_id', $server->id)->update(['confirmed_offline_minutes' => 6 * 1440]);
    expect($this->sleep->markAwake($server))->toBeTrue();

    // The admin lowers the discount to 10%: the next sleep (3 days) is recorded at 10%.
    $plan->forceFill(['sleep_discount_percent' => 10])->save();
    $this->daemon->details['state'] = 'running';
    $subscription->refresh();
    tick($this);
    tick($this);
    tick($this);
    $second = ServerSleepSession::query()->where('server_id', $server->id)->latest('id')->firstOrFail();
    expect($second->discount_percent)->toBe(10);
    $this->travelTo(CarbonImmutable::now()->addDays(3));
    $second->forceFill(['confirmed_offline_minutes' => 3 * 1440])->save();
    $this->sleep->markAwake($server);

    // The admin then turns the discount off entirely before the renewal.
    $plan->forceFill(['sleep_discount_percent' => 0])->save();
    $this->travelTo($subscription->fresh()->next_renewal_at);
    $subscription->refresh();

    // 6 days at 50% and 3 days at 10% of a 30-day period: 500 × (6 × 50 + 3 × 10) / 3000 = 55 saved.
    expect($this->subscriptions->renew($subscription))->toBeTrue()
        ->and($this->wallet->balance($user))->toBe(1000 - 445)
        ->and($subscription->fresh()->last_sleep_saved_tokens)->toBe(55);

    $charge = TokenTransaction::query()->where('reference_type', SubscriptionService::REFERENCE_TYPE)->sole();
    expect($charge->meta)->toMatchArray(['full_price' => 500, 'sleep_seconds' => 9 * 86_400, 'sleep_discount_percent' => 37, 'sleep_saved_tokens' => 55]);
});

test('the discount rounds the charge up to whole tokens', function (): void {
    [$user, $server, $subscription] = sleepyServer($this, ['sleep_discount_percent' => 33], ['price_tokens' => 7, 'next_renewal_at' => CarbonImmutable::now()], balance: 100);
    // A third of the period at 33%: 7 × (2/3 + 1/3 × 0.67) = 6.23 → 7 (saved = floor(0.77) = 0).
    session($server, '2026-10-21 12:00:00', '2026-10-31 12:00:00');

    $this->subscriptions->renew($subscription);
    expect($this->wallet->balance($user))->toBe(93);

    // Two thirds at 33%: 7 × (1/3 + 2/3 × 0.67) = 5.46 → 6.
    $subscription->refresh();
    session($server, '2026-11-20 12:00:00', '2026-12-10 12:00:00');
    $this->travelTo(CarbonImmutable::parse('2026-12-20 12:00:00'));
    $this->subscriptions->renew($subscription);

    expect($this->wallet->balance($user))->toBe(87)
        ->and($subscription->fresh()->last_sleep_saved_tokens)->toBe(1);
});

test('a period slept entirely at 100% is free and writes no charge', function (): void {
    [$user, $server, $subscription] = sleepyServer($this, ['sleep_discount_percent' => 100], ['next_renewal_at' => CarbonImmutable::now()]);
    session($server, '2026-10-21 12:00:00', null);

    expect($this->subscriptions->renew($subscription))->toBeTrue()
        ->and($this->wallet->balance($user))->toBe(0)
        ->and(TokenTransaction::query()->where('user_id', $user->id)->where('type', SubscriptionService::CHARGE_TYPE)->exists())->toBeFalse()
        ->and($subscription->fresh())
        ->status->toBe(ServerSubscription::STATUS_ACTIVE)
        ->last_sleep_saved_tokens->toBe(500)
        ->next_renewal_at->toEqual(CarbonImmutable::now()->addDays(30));

    // The next period has not slept yet... except the open sleep, which goes on.
    expect($this->subscriptions->renew($subscription))->toBeTrue()
        ->and($subscription->fresh()->next_renewal_at)->toEqual(CarbonImmutable::now()->addDays(30));
});

test('a balance that covers only the discounted price still renews', function (): void {
    [$user, $server, $subscription] = sleepyServer($this, attributes: ['next_renewal_at' => CarbonImmutable::now()], balance: 400);
    session($server, '2026-10-21 12:00:00', '2026-11-05 12:00:00');

    expect($this->subscriptions->renewDue())->toBe(1)
        ->and($subscription->fresh()->status)->toBe(ServerSubscription::STATUS_ACTIVE)
        ->and($this->wallet->balance($user))->toBe(25);
});

test('a suspended server is restored by a top-up that covers the discounted price', function (): void {
    [$user, $server, $subscription] = sleepyServer($this, attributes: [
        'status' => ServerSubscription::STATUS_SUSPENDED,
        'next_renewal_at' => CarbonImmutable::now()->subDay(),
        'suspended_at' => CarbonImmutable::now()->subDay(),
        'delete_at' => CarbonImmutable::now()->addDays(6),
    ]);
    // The whole unpaid period (Oct 20 - Nov 19) slept: 500 × 0.5 = 250.
    session($server, '2026-10-20 12:00:00', '2026-11-19 12:00:00');
    $this->wallet->credit($user, 300, 'admin_adjust', 'Top-up');

    expect($this->subscriptions->collectOverdue())->toBe(1)
        ->and($this->wallet->balance($user))->toBe(50)
        ->and($subscription->fresh()->status)->toBe(ServerSubscription::STATUS_ACTIVE);
});

test('paying early counts the sleep so far', function (): void {
    [$user, $server, $subscription] = sleepyServer($this, attributes: ['next_renewal_at' => CarbonImmutable::now()->addDays(10)], balance: 1000);
    // The period runs Oct 31 - Nov 30; 10 of its days slept so far.
    session($server, '2026-11-01 00:00:00', '2026-11-11 00:00:00');

    $this->subscriptions->renewNow($subscription);

    // 500 × (1 − 10/30 × 0.5) = 416.67 → 417.
    expect($this->wallet->balance($user))->toBe(583)
        ->and($subscription->fresh()->next_renewal_at)->toEqual(CarbonImmutable::now()->addDays(40));
});

test('a plan without a sleep discount charges the full price', function (): void {
    [$user, $server, $subscription] = sleepyServer($this, ['sleep_discount_percent' => 0], ['next_renewal_at' => CarbonImmutable::now()], balance: 1000);
    session($server, '2026-10-21 12:00:00', '2026-11-20 12:00:00');

    $this->subscriptions->renew($subscription);

    expect($this->wallet->balance($user))->toBe(500)
        ->and($subscription->fresh()->last_sleep_saved_tokens)->toBe(0);
});

test('the auto-sleep pass runs every minute on one server without overlapping', function (): void {
    $schedule = resolve(ConsoleSchedule::class);
    (new Scheduler)($schedule);

    $event = collect($schedule->events())->first(fn (Event $event): bool => str_contains((string) $event->command, 'p:billing:auto-sleep'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();

    [, , $subscription] = sleepyServer($this, attributes: ['idle_checks' => 2]);
    Cache::flush();
    $this->artisan('p:billing:auto-sleep')->assertSuccessful();
    expect($subscription->fresh()->isSleeping())->toBeTrue();
});
