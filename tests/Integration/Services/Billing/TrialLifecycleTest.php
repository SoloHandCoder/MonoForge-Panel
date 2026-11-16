<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Services\Billing\TrialLifecycleTest;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Pterodactyl\Exceptions\Billing\SubscriptionRenewalException;
use Pterodactyl\Models\Billing\ServerSubscription;
use Pterodactyl\Models\Billing\ShopCategory;
use Pterodactyl\Models\Billing\ShopPeriod;
use Pterodactyl\Models\Billing\ShopPlan;
use Pterodactyl\Models\Billing\ShopTrial;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Models\UserNotice;
use Pterodactyl\Services\Billing\CapacityService;
use Pterodactyl\Services\Billing\SubscriptionService;
use Pterodactyl\Services\Billing\TokenWallet;
use Pterodactyl\Services\Billing\TrialService;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Tests\Support\Fakes\FakeDaemonServer;

uses(IntegrationTestCase::class, DatabaseTransactions::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-20 12:00:00');
    Notification::fake();
    config()->set('logging.default', 'null');
    config()->set('monoforge.shop.grace_days', 7);

    $this->daemon = new FakeDaemonServer;
    $this->wallet = $this->app->make(TokenWallet::class);
    $this->app->instance(TokenWallet::class, $this->wallet);
    $this->service = $this->app->make(SubscriptionService::class);
    $this->trials = $this->app->make(TrialService::class);

    $category = ShopCategory::query()->forceCreate(['name' => 'Minecraft', 'slug' => 'minecraft-'.uniqid(), 'sort' => 0, 'visible' => true])->id;
    $this->plan = ShopPlan::query()->forceCreate([
        'category_id' => $category, 'name' => 'Starter', 'description' => '', 'visible' => true, 'sort' => 0,
        'price_tokens' => 600, 'memory' => 2048, 'swap' => 0, 'disk' => 10000, 'cpu' => 100, 'io' => 500,
        'databases' => 1, 'backups' => 1, 'allocations' => 0, 'egg_ids' => [], 'node_ids' => null,
    ]);
    $this->period = ShopPeriod::query()->forceCreate(['name' => 'Monthly', 'days' => 30, 'price_modifier_percent' => 0, 'sort' => 0, 'enabled' => true]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * A running 48 hour trial.
 *
 * @return array{User, Server, ServerSubscription}
 */
function runningTrial(object $test): array
{
    [$user, $server] = $test->generateTestAccount();
    $subscription = $test->service->startTrial($server, $user, $test->plan, 48);
    $test->trials->record($user, $server, $test->plan->id, 48, '203.0.113.7');

    return [$user, $server, $subscription];
}

test('a trial runs until its end, then suspends the server and is deleted after the grace period', function (): void {
    [$user, $server, $subscription] = runningTrial($this);
    $this->wallet->credit($user, 5000, 'purchase', 'Top-up');

    // Nothing happens before the end.
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(47));
    expect($this->service->expireTrials())->toBe(0)
        ->and($this->service->renewDue())->toBe(0);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());
    expect($this->service->expireTrials())->toBe(1)
        ->and($this->service->expireTrials())->toBe(0);

    $subscription->refresh();
    expect($subscription->status)->toBe(ServerSubscription::STATUS_SUSPENDED)
        ->and($subscription->is_trial)->toBeTrue()
        ->and($subscription->delete_at)->toEqual(CarbonImmutable::now()->addDays(7))
        ->and($server->refresh()->isSuspended())->toBeTrue()
        ->and(ShopTrial::query()->sole()->state())->toBe(ShopTrial::STATE_EXPIRED)
        ->and(UserNotice::query()->where('user_id', $user->id)->where('title', 'Free trial ended')->count())->toBe(1);
    $this->daemon->assertSyncedTimes(1);

    // A balance never pays an ended trial by itself, and "Renew now" is refused.
    expect($this->service->collectOverdue())->toBe(0)
        ->and($this->service->unsuspendIfPaid($subscription))->toBeFalse()
        ->and($this->wallet->balance($user))->toBe(5000)
        ->and(fn () => $this->service->renewNow($subscription))->toThrow(SubscriptionRenewalException::class);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(7));
    expect($this->service->deleteExpired())->toBe(1)
        ->and(Server::query()->find($server->id))->toBeNull()
        ->and(ShopTrial::query()->sole()->state())->toBe(ShopTrial::STATE_DELETED)
        ->and(UserNotice::query()->where('user_id', $user->id)->where('body', 'like', '%free trial ended and it was not kept%')->exists())->toBeTrue()
        ->and($this->wallet->balance($user))->toBe(5000);
    $this->daemon->assertDeleted();
});

test('with a grace period of 0 days the server is deleted when the trial ends', function (): void {
    config()->set('monoforge.shop.grace_days', 0);
    [, $server] = runningTrial($this);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(48));
    $this->service->expireTrials();

    expect(Server::query()->find($server->id))->toBeNull();
});

test('the billing command ends trials', function (): void {
    [, $server] = runningTrial($this);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(49));

    $this->artisan('p:billing:process')->assertSuccessful();

    expect($server->refresh()->isSuspended())->toBeTrue();
});

test('an ended trial kept with tokens is unsuspended and becomes a normal subscription', function (): void {
    [$user, $server, $subscription] = runningTrial($this);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(50));
    $this->service->expireTrials();
    expect($server->refresh()->isSuspended())->toBeTrue();

    $this->wallet->credit($user, 1200, 'purchase', 'Top-up');
    $this->trials->convert($subscription->fresh(), $this->period->id, 600, $this->service);

    $subscription->refresh();
    expect($subscription->status)->toBe(ServerSubscription::STATUS_ACTIVE)
        ->and($subscription->is_trial)->toBeFalse()
        ->and($subscription->price_tokens)->toBe(600)
        ->and($subscription->next_renewal_at)->toEqual(CarbonImmutable::now()->addDays(30))
        ->and($subscription->delete_at)->toBeNull()
        ->and($subscription->suspended_at)->toBeNull()
        ->and($server->refresh()->isSuspended())->toBeFalse()
        ->and($this->wallet->balance($user))->toBe(600)
        ->and(ShopTrial::query()->sole()->state())->toBe(ShopTrial::STATE_CONVERTED)
        // The warning about the ended trial is read.
        ->and(UserNotice::query()->where('user_id', $user->id)->where('title', 'Free trial ended')->whereNull('read_at')->exists())->toBeFalse();

    // From now on it renews like any shop server.
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(30));
    expect($this->service->renewDue())->toBe(1)
        ->and($this->wallet->balance($user))->toBe(0);
});

test('an admin extension keeps a trial a trial, also after it ended', function (): void {
    [, $server, $subscription] = runningTrial($this);

    $this->service->extend($subscription, 2);
    expect($subscription->fresh())
        ->status->toBe(ServerSubscription::STATUS_TRIAL)
        ->next_renewal_at->toEqual(CarbonImmutable::now()->addHours(48)->addDays(2))
        ->and(ShopTrial::query()->sole()->ends_at)->toEqual(CarbonImmutable::now()->addHours(48)->addDays(2));

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(5));
    $this->service->expireTrials();
    expect($server->refresh()->isSuspended())->toBeTrue();

    $this->service->extend($subscription->fresh(), 1);
    expect($subscription->fresh())
        ->status->toBe(ServerSubscription::STATUS_TRIAL)
        ->is_trial->toBeTrue()
        ->delete_at->toBeNull()
        ->next_renewal_at->toEqual(CarbonImmutable::now()->addDay())
        ->and($server->refresh()->isSuspended())->toBeFalse()
        ->and(ShopTrial::query()->sole()->state())->toBe(ShopTrial::STATE_RUNNING);
});

test('the conversion rate counts kept trials among the trials that are over', function (): void {
    [$kept, , $keptSubscription] = runningTrial($this);
    runningTrial($this);
    [, , $lapsed] = runningTrial($this);

    $this->wallet->credit($kept, 600, 'purchase', 'Top-up');
    $this->trials->convert($keptSubscription, $this->period->id, null, $this->service);
    $lapsed->forceFill(['next_renewal_at' => CarbonImmutable::now()->subMinute()])->save();
    $this->service->expireTrials();

    expect($this->trials->stats())->toBe([
        'started' => 3,
        'running' => 1,
        'converted' => 1,
        'lapsed' => 1,
        'conversion_rate' => 50.0,
    ]);
});

test('a trial counts against the shop capacity', function (): void {
    runningTrial($this);

    expect($this->app->make(CapacityService::class)->used()['memory'])->toBeGreaterThan(0);
});
