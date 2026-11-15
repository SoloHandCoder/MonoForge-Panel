<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Services\Billing\SubscriptionServiceTest;

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Pterodactyl\Console\Scheduler;
use Pterodactyl\Exceptions\Billing\SubscriptionRenewalException;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Billing\ServerSubscription;
use Pterodactyl\Models\Billing\ShopPeriod;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Setting;
use Pterodactyl\Models\User;
use Pterodactyl\Models\UserNotice;
use Pterodactyl\Notifications\SubscriptionBillingNotification;
use Pterodactyl\Services\Billing\BillingSettings;
use Pterodactyl\Services\Billing\Quote;
use Pterodactyl\Services\Billing\SubscriptionService;
use Pterodactyl\Services\Billing\TokenWallet;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Tests\Support\Fakes\FakeDaemonServer;

uses(IntegrationTestCase::class, DatabaseTransactions::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-20 12:00:00');
    Notification::fake();
    config()->set('logging.default', 'null');
    $this->daemon = new FakeDaemonServer;
    // One wallet for the whole test (Task Q's real wallet keeps its state in the database anyway).
    $this->wallet = $this->app->make(TokenWallet::class);
    $this->app->instance(TokenWallet::class, $this->wallet);
    $this->service = $this->app->make(SubscriptionService::class);
    Setting::put(BillingSettings::GRACE_DAYS, '7');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * A shop server whose renewal of 500 tokens every 30 days is due now.
 *
 * @return array{User, Server, ServerSubscription}
 */
function dueSubscription(object $test, int $balance, array $attributes = []): array
{
    [$user, $server] = $test->generateTestAccount();
    if ($balance > 0) {
        $test->wallet->credit($user, $balance, 'admin_adjust', 'Test balance');
    }

    $subscription = ServerSubscription::query()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'period_days' => 30,
        'price_tokens' => 500,
        'addons' => [],
        'next_renewal_at' => CarbonImmutable::now(),
        ...$attributes,
    ]);

    return [$user, $server, $subscription];
}

test('start bills from one period after the purchase', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $period = (new ShopPeriod)->forceFill(['name' => 'Quarterly', 'days' => 90]);
    $quote = new Quote(basePerPeriod: 1350, multiplier: 1.0, addonsPerPeriod: 150, totalPerPeriod: 1500, periodDays: 90, lines: [], resources: []);

    $subscription = $this->service->start($server, $user, null, $period, $quote, ['backup' => 2, 'empty' => 0]);

    expect($subscription->fresh())
        ->status->toBe(ServerSubscription::STATUS_ACTIVE)
        ->price_tokens->toBe(1500)
        ->period_days->toBe(90)
        ->addons->toBe([['key' => 'backup', 'units' => 2]])
        ->next_renewal_at->toEqual(CarbonImmutable::now()->addDays(90))
        ->and($server->subscription?->id)->toBe($subscription->id);
});

test('a due renewal is charged once and moves the renewal one period on', function (): void {
    [$user, , $subscription] = dueSubscription($this, 1200);

    expect($this->service->renew($subscription))->toBeTrue()
        // A second run in the same period (scheduler re-run, a stale model) charges nothing.
        ->and($this->service->renew($subscription))->toBeTrue()
        ->and($this->service->renewDue())->toBe(0)
        ->and($this->wallet->balance($user))->toBe(700)
        ->and($subscription->fresh()->next_renewal_at)->toEqual(CarbonImmutable::now()->addDays(30));

    $stale = ServerSubscription::query()->findOrFail($subscription->id);
    $stale->next_renewal_at = CarbonImmutable::now();
    expect($this->service->renew($stale))->toBeTrue()
        ->and($this->wallet->balance($user))->toBe(700);
});

test('a period that was charged already is not charged again', function (): void {
    [$user, , $subscription] = dueSubscription($this, 1000);
    // A run that died after the debit but before saving the next renewal date.
    $this->wallet->debit($user, 500, SubscriptionService::CHARGE_TYPE, 'Renewal', SubscriptionService::REFERENCE_TYPE, $subscription->periodKey());

    expect($this->service->renew($subscription))->toBeTrue()
        ->and($this->wallet->balance($user))->toBe(500)
        ->and($subscription->fresh()->next_renewal_at)->toEqual(CarbonImmutable::now()->addDays(30));
});

test('an unpaid renewal suspends the server, warns once and counts down to the deletion', function (): void {
    [$user, $server, $subscription] = dueSubscription($this, 100);

    expect($this->service->renewDue())->toBe(1)
        ->and($this->service->renewDue())->toBe(0);

    $subscription->refresh();
    expect($subscription->status)->toBe(ServerSubscription::STATUS_SUSPENDED)
        ->and($subscription->delete_at)->toEqual(CarbonImmutable::now()->addDays(7))
        ->and($server->refresh()->isSuspended())->toBeTrue()
        ->and($this->wallet->balance($user))->toBe(100)
        ->and(UserNotice::query()->where('user_id', $user->id)->where('kind', "billing.suspended.{$subscription->id}")->count())->toBe(1);
    $this->daemon->assertSyncedTimes(1);
    Notification::assertSentToTimes($user, SubscriptionBillingNotification::class, 1);

    // Nothing is deleted before the grace period is over.
    expect($this->service->deleteExpired())->toBe(0);

    // A day before the deletion: one warning, however often the scheduler runs.
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(6)->addHour());
    expect($this->service->warnDeletionSoon())->toBe(1)
        ->and($this->service->warnDeletionSoon())->toBe(0);
    Notification::assertSentToTimes($user, SubscriptionBillingNotification::class, 2);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDay());
    expect($this->service->deleteExpired())->toBe(1)
        ->and(Server::query()->find($server->id))->toBeNull()
        ->and(ServerSubscription::query()->find($subscription->id))->toBeNull();
    $this->daemon->assertDeleted();
});

test('with a grace period of 0 days the failed renewal deletes the server at once', function (): void {
    Setting::put(BillingSettings::GRACE_DAYS, '0');
    [$user, $server] = dueSubscription($this, 0);

    $this->service->renewDue();

    expect(Server::query()->find($server->id))->toBeNull();
    $this->daemon->assertDeleted();
    expect(UserNotice::query()->where('user_id', $user->id)->where('title', 'Server deleted')->exists())->toBeTrue();
});

test('a top-up pays the unpaid renewal and unsuspends the server', function (): void {
    [$user, $server, $subscription] = dueSubscription($this, 0);
    $this->service->renewDue();
    expect($server->refresh()->isSuspended())->toBeTrue();

    // Still short: nothing happens.
    expect($this->service->collectOverdue())->toBe(0);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(2));
    $this->wallet->credit($user, 600, 'purchase', 'Top-up');
    expect($this->service->collectOverdue())->toBe(1);

    $subscription->refresh();
    expect($subscription->status)->toBe(ServerSubscription::STATUS_ACTIVE)
        ->and($subscription->delete_at)->toBeNull()
        ->and($subscription->suspended_at)->toBeNull()
        // The new period starts at the payment.
        ->and($subscription->next_renewal_at)->toEqual(CarbonImmutable::now()->addDays(30))
        ->and($server->refresh()->isSuspended())->toBeFalse()
        ->and($this->wallet->balance($user))->toBe(100)
        ->and(UserNotice::query()->where('user_id', $user->id)->where('kind', "billing.suspended.{$subscription->id}")->whereNull('read_at')->exists())->toBeFalse()
        ->and($this->service->collectOverdue())->toBe(0);
});

test('renew now pays a suspended server, or one period ahead at most', function (): void {
    [$user, $server, $subscription] = dueSubscription($this, 0, ['next_renewal_at' => CarbonImmutable::now()->addDays(10)]);

    expect(fn () => $this->service->renewNow($subscription))->toThrow(SubscriptionRenewalException::class);

    $this->wallet->credit($user, 1000, 'purchase', 'Top-up');
    $this->service->renewNow($subscription);
    expect($subscription->fresh()->next_renewal_at)->toEqual(CarbonImmutable::now()->addDays(40))
        ->and(fn () => $this->service->renewNow($subscription->fresh()))->toThrow(SubscriptionRenewalException::class, 'already paid')
        ->and($this->wallet->balance($user))->toBe(500);

    $subscription->forceFill(['next_renewal_at' => CarbonImmutable::now()])->save();
    $this->service->suspend($subscription);
    $this->service->renewNow($subscription->fresh());

    expect($subscription->fresh()->status)->toBe(ServerSubscription::STATUS_ACTIVE)
        ->and($server->refresh()->isSuspended())->toBeFalse()
        ->and($this->wallet->balance($user))->toBe(0);
});

test('a short balance is warned about once before the renewal', function (): void {
    [$user, , $subscription] = dueSubscription($this, 400, ['next_renewal_at' => CarbonImmutable::now()->addDays(2)]);
    dueSubscription($this, 0, ['next_renewal_at' => CarbonImmutable::now()->addDays(10)]);

    expect($this->service->warnUpcoming())->toBe(1)
        ->and($this->service->warnUpcoming())->toBe(0)
        ->and(UserNotice::query()->where('user_id', $user->id)->where('kind', "billing.renewal_short.{$subscription->id}")->exists())->toBeTrue();
    Notification::assertSentToTimes($user, SubscriptionBillingNotification::class, 1);
});

test('a suspension Wings refused is retried, and an offline node is force deleted a day late', function (): void {
    [, $server, $subscription] = dueSubscription($this, 0);
    $this->daemon->throwable = new DaemonConnectionException(Http::failedRequest([], 500));

    $this->service->renewDue();
    expect($subscription->fresh()->status)->toBe(ServerSubscription::STATUS_SUSPENDED)
        ->and($server->refresh()->isSuspended())->toBeFalse();

    $this->daemon->throwable = null;
    expect($this->service->syncServerStates())->toBe(1)
        ->and($server->refresh()->isSuspended())->toBeTrue()
        ->and($this->service->syncServerStates())->toBe(0);

    $this->daemon->throwable = new DaemonConnectionException(Http::failedRequest([], 500));
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(7)->addHour());
    expect($this->service->deleteExpired())->toBe(0)
        ->and(Server::query()->find($server->id))->not->toBeNull();

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDay());
    expect($this->service->deleteExpired())->toBe(1)
        ->and(Server::query()->find($server->id))->toBeNull();
});

test('auto-renew off suspends at the end of the period, and cancel stops billing', function (): void {
    [$user, $server, $subscription] = dueSubscription($this, 5000, ['auto_renew' => false]);

    $this->service->renewDue();
    expect($subscription->fresh()->status)->toBe(ServerSubscription::STATUS_SUSPENDED)
        ->and($this->wallet->balance($user))->toBe(5000)
        ->and($this->service->collectOverdue())->toBe(0);

    $this->service->cancel($subscription);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(30));

    expect($subscription->fresh()->status)->toBe(ServerSubscription::STATUS_CANCELLED)
        ->and($server->refresh()->isSuspended())->toBeFalse()
        ->and($this->service->renewDue())->toBe(0)
        ->and($this->service->deleteExpired())->toBe(0)
        ->and($this->wallet->balance($user))->toBe(5000);
});

test('extend moves the renewal and restores a suspended server', function (): void {
    [, $server, $subscription] = dueSubscription($this, 0);
    $this->service->renewDue();

    $this->service->extend($subscription->fresh(), 5);

    expect($subscription->fresh())
        ->status->toBe(ServerSubscription::STATUS_ACTIVE)
        ->delete_at->toBeNull()
        ->next_renewal_at->toEqual(CarbonImmutable::now()->addDays(5))
        ->and($server->refresh()->isSuspended())->toBeFalse();
});

test('the billing command runs every five minutes and is safe to repeat', function (): void {
    [$user, , $subscription] = dueSubscription($this, 600);

    $schedule = new Schedule;
    $this->app->make(Scheduler::class)($schedule);
    $events = array_values(array_filter($schedule->events(), fn (Event $event): bool => str_contains((string) $event->command, 'p:billing:process')));

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('*/5 * * * *')
        ->and($events[0]->withoutOverlapping)->toBeTrue();

    $this->artisan('p:billing:process')->assertSuccessful();
    $this->artisan('p:billing:process')->assertSuccessful();

    expect($this->wallet->balance($user))->toBe(100)
        ->and($subscription->fresh()->next_renewal_at)->toEqual(CarbonImmutable::now()->addDays(30));
});
