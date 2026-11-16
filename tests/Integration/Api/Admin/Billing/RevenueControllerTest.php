<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Admin\Billing\RevenueControllerTest;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\Billing\GetRevenueRequest;
use Pterodactyl\Models\Billing\ServerSubscription;
use Pterodactyl\Models\Billing\TebexPayment;
use Pterodactyl\Models\Billing\TokenTransaction;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Billing\Revenue\SubscriptionEndings;
use Pterodactyl\Services\Billing\SubscriptionService;
use Pterodactyl\Tests\Integration\Api\Admin\AdminApiIntegrationTestCase;
use RuntimeException;

uses(AdminApiIntegrationTestCase::class);

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00:00'));
});

function ledger(User $user, string $type, int $amount, string $at, array $extra = []): void
{
    DB::table('token_transactions')->insert(array_merge([
        'user_id' => $user->id,
        'amount' => $amount,
        'balance_after' => 0,
        'type' => $type,
        'reference_type' => null,
        'reference_id' => null,
        'description' => 'test',
        'meta' => null,
        'created_at' => $at,
    ], $extra));
}

function payment(User $user, string $id, string $status, string $amount, string $currency, ?string $processedAt, int $tokens = 0, int $reversed = 0): void
{
    DB::table('tebex_payments')->insert([
        'transaction_id' => $id,
        'user_id' => $user->id,
        'status' => $status,
        'tokens' => $tokens,
        'tokens_reversed' => $reversed,
        'amount' => $amount,
        'currency' => $currency,
        'raw' => '[]',
        'processed_at' => $processedAt,
        'created_at' => $processedAt ?? '2026-11-10 00:00:00',
        'updated_at' => '2026-11-16 00:00:00',
    ]);
}

function plan(string $name): int
{
    $category = DB::table('shop_categories')->insertGetId(['name' => 'Games', 'slug' => 'games-'.$name]);

    return DB::table('shop_plans')->insertGetId([
        'category_id' => $category,
        'name' => $name,
        'price_tokens' => 100,
        'memory' => 1024,
        'disk' => 1024,
        'cpu' => 100,
        'egg_ids' => '[]',
    ]);
}

function subscription(Server $server, array $attributes): ServerSubscription
{
    return ServerSubscription::query()->create(array_merge([
        'server_id' => $server->id,
        'user_id' => $server->owner_id,
        'period_days' => 30,
        'price_tokens' => 300,
        'status' => ServerSubscription::STATUS_ACTIVE,
        'next_renewal_at' => CarbonImmutable::now()->addDays(20),
    ], $attributes));
}

test('the report sums the range from the ledger, the payments and the subscriptions', function (): void {
    $buyer = User::factory()->create(['created_at' => '2026-11-15 09:00:00']);
    $other = User::factory()->create(['created_at' => '2026-01-01 00:00:00']);

    // Purchases: two in the last 7 days, one before it.
    ledger($buyer, TokenTransaction::TYPE_PURCHASE, 1000, '2026-11-15 10:00:00');
    ledger($other, TokenTransaction::TYPE_PURCHASE, 500, '2026-11-16 08:00:00');
    ledger($other, TokenTransaction::TYPE_PURCHASE, 9999, '2026-11-01 08:00:00');
    ledger($buyer, TokenTransaction::TYPE_SERVER_CHARGE, -300, '2026-11-15 11:00:00', ['meta' => json_encode(['sleep_saved_tokens' => 40])]);
    ledger($buyer, TokenTransaction::TYPE_SERVER_CHARGE, -200, '2026-11-16 11:00:00', ['meta' => json_encode(['sleep_saved_tokens' => 0])]);
    ledger($buyer, TokenTransaction::TYPE_ADDON_CHARGE, -50, '2026-11-16 11:00:00');
    ledger($buyer, TokenTransaction::TYPE_AI_USAGE, -7, '2026-11-14 11:00:00');
    DB::table('ai_usage')->insert(['user_id' => $buyer->id, 'scope' => 'server', 'period' => '2026-11', 'ai_tokens' => 9000, 'free_tokens' => 2000, 'panel_tokens_charged' => 7, 'created_at' => '2026-11-14 11:00:00']);

    // Money: EUR and USD stay apart; a refund in range, a dispute that was won, and a chargeback after a dispute hold.
    payment($buyer, 'tbx-1', TebexPayment::STATUS_COMPLETED, '9.99', 'EUR', '2026-11-15 10:00:00', 1000);
    payment($other, 'tbx-2', TebexPayment::STATUS_REFUNDED, '4.99', 'EUR', '2026-11-16 08:00:00', 500, 500);
    payment($other, 'tbx-3', TebexPayment::STATUS_COMPLETED, '12.00', 'USD', '2026-11-12 08:00:00', 1200);
    payment($other, 'tbx-4', TebexPayment::STATUS_CHARGEBACK, '3.50', 'USD', '2026-11-01 08:00:00', 300, 300);
    payment($other, 'tbx-old', TebexPayment::STATUS_REFUNDED, '1.00', 'EUR', '2026-10-01 08:00:00', 100, 100);
    payment($other, 'tbx-open', TebexPayment::STATUS_DISPUTED, '2.00', 'EUR', '2026-11-02 08:00:00', 200, 200);
    ledger($other, TokenTransaction::TYPE_REFUND, -500, '2026-11-16 09:00:00', ['reference_type' => TebexPayment::LEDGER_REFERENCE, 'reference_id' => 'tbx-2']);
    ledger($other, TokenTransaction::TYPE_CHARGEBACK, -1200, '2026-11-13 09:00:00', ['reference_type' => TebexPayment::LEDGER_REFERENCE, 'reference_id' => 'tbx-3#dispute']);
    ledger($other, TokenTransaction::TYPE_CHARGEBACK, -300, '2026-11-14 09:00:00', ['reference_type' => TebexPayment::LEDGER_REFERENCE, 'reference_id' => 'tbx-4#dispute']);
    // Refunded before the range: counted in an earlier one, even though a second row falls inside.
    ledger($other, TokenTransaction::TYPE_REFUND, -100, '2026-10-02 09:00:00', ['reference_type' => TebexPayment::LEDGER_REFERENCE, 'reference_id' => 'tbx-old']);
    ledger($other, TokenTransaction::TYPE_CHARGEBACK, -1, '2026-11-14 09:00:00', ['reference_type' => TebexPayment::LEDGER_REFERENCE, 'reference_id' => 'tbx-old#dispute']);
    ledger($other, TokenTransaction::TYPE_CHARGEBACK, -200, '2026-11-14 09:00:00', ['reference_type' => TebexPayment::LEDGER_REFERENCE, 'reference_id' => 'tbx-open#dispute']);

    // Subscriptions: 300 per 30 days, 100 per 7 days and 50 per 7 days (150 per 7 days rounds down to 642 once).
    $big = plan('Big');
    $small = plan('Small');
    DB::table('users')->where('id', $buyer->id)->update(['token_balance' => 350]);
    subscription($this->createServerModel(['owner_id' => $buyer->id]), ['plan_id' => $big, 'next_renewal_at' => CarbonImmutable::now()->addDays(2)]);
    subscription($this->createServerModel(['owner_id' => $buyer->id]), ['plan_id' => $small, 'period_days' => 7, 'price_tokens' => 100, 'next_renewal_at' => CarbonImmutable::now()->addDays(3)]);
    subscription($this->createServerModel(['owner_id' => $buyer->id]), ['plan_id' => $small, 'period_days' => 7, 'price_tokens' => 50, 'next_renewal_at' => CarbonImmutable::now()->addDays(10)]);
    subscription($this->createServerModel(['owner_id' => $other->id]), ['plan_id' => $big, 'status' => ServerSubscription::STATUS_SUSPENDED]);

    $response = $this->getJson(route('api.admin.billing.revenue', ['range' => '7']))->assertOk();

    $response->assertJsonPath('range', ['from' => '2026-11-10', 'to' => '2026-11-16', 'days' => 7])
        ->assertJsonPath('tokens', ['sold' => 1500, 'purchases' => 2, 'refunded' => 500, 'charged_back' => 300, 'net' => 700, 'spent_on_servers' => 550])
        ->assertJsonPath('money', [
            ['currency' => 'EUR', 'gross' => '14.98', 'refunded' => '4.99', 'charged_back' => '0.00', 'net' => '9.99', 'payments' => 2, 'refunds' => 1, 'chargebacks' => 0],
            ['currency' => 'USD', 'gross' => '12.00', 'refunded' => '0.00', 'charged_back' => '3.50', 'net' => '8.50', 'payments' => 1, 'refunds' => 0, 'chargebacks' => 1],
        ])
        ->assertJsonPath('disputes_open', 1)
        ->assertJsonPath('subscriptions', ['active' => 3, 'suspended' => 1, 'mrr_tokens' => 300 + 642])
        // 350 tokens cover the 300 renewal but not the 100 after it; the 50 renews in 10 days.
        ->assertJsonPath('renewals_due', ['days' => 7, 'count' => 2, 'tokens' => 400, 'short_of_balance' => 1])
        ->assertJsonPath('top_plans', [
            ['id' => $small, 'name' => 'Small', 'active' => 2, 'mrr_tokens' => 642],
            ['id' => $big, 'name' => 'Big', 'active' => 1, 'mrr_tokens' => 300],
        ])
        ->assertJsonPath('sleep_saved_tokens', 40)
        ->assertJsonPath('ai', ['charged_tokens' => 7, 'ai_tokens' => 9000])
        ->assertJsonPath('users.paying', 2)
        ->assertJsonCount(7, 'daily')
        ->assertJsonPath('daily.5', ['date' => '2026-11-15', 'tokens_sold' => 1000, 'tokens_spent' => 300, 'new_users' => 1])
        ->assertJsonPath('daily.6.tokens_sold', 500)->assertJsonPath('daily.6.tokens_spent', 250);

    // Users are only counted: no emails or usernames leave the endpoint.
    expect($response->getContent())->not->toContain($buyer->email)->not->toContain($other->username);
});

test('churn counts cancelled, unpaid and deleted subscriptions once each', function (): void {

    $cancelled = subscription($this->createServerModel(), []);
    resolve(SubscriptionService::class)->cancel($cancelled);
    resolve(SubscriptionService::class)->cancel($cancelled->refresh());
    // Deleting the server of a cancelled subscription does not count it again.
    $cancelled->server->delete();

    $unpaid = subscription($this->createServerModel(), ['status' => ServerSubscription::STATUS_SUSPENDED, 'period_days' => 7, 'price_tokens' => 70]);
    $unpaid->server->delete();

    $running = subscription($this->createServerModel(), []);
    $running->server->delete();

    expect(DB::table(SubscriptionEndings::TABLE)->orderBy('id')->pluck('reason')->all())->toBe(['cancelled', 'unpaid', 'deleted']);

    $this->getJson(route('api.admin.billing.revenue'))
        ->assertOk()
        ->assertJsonPath('churn', ['cancelled' => 1, 'unpaid' => 1, 'deleted' => 1, 'total' => 3, 'mrr_tokens' => 300 + 300 + 300])
        ->assertJsonPath('subscriptions.active', 0);
});

test('a deletion that rolls back leaves no churn behind', function (): void {
    $running = subscription($this->createServerModel(), []);

    try {
        DB::transaction(function () use ($running): void {
            $running->server->delete();

            throw new RuntimeException('Wings said no');
        });
    } catch (RuntimeException) {
    }

    expect(DB::table(SubscriptionEndings::TABLE)->count())->toBe(0);
});

test('a report is cached for five minutes', function (): void {
    $user = User::factory()->create();
    ledger($user, TokenTransaction::TYPE_PURCHASE, 100, '2026-11-16 08:00:00');
    $this->getJson(route('api.admin.billing.revenue'))->assertOk()->assertJsonPath('tokens.sold', 100);

    ledger($user, TokenTransaction::TYPE_PURCHASE, 100, '2026-11-16 09:00:00');
    $this->getJson(route('api.admin.billing.revenue'))->assertOk()->assertJsonPath('tokens.sold', 100);

    $this->travel(301)->seconds();
    $this->getJson(route('api.admin.billing.revenue'))->assertOk()->assertJsonPath('tokens.sold', 200);
});

test('ranges default to 30 days and custom ranges are checked', function (): void {
    $this->getJson(route('api.admin.billing.revenue'))
        ->assertOk()
        ->assertJsonPath('range', ['from' => '2026-10-18', 'to' => '2026-11-16', 'days' => 30])
        ->assertJsonCount(30, 'daily');

    $this->getJson(route('api.admin.billing.revenue', ['range' => 'custom', 'from' => '2026-02-01', 'to' => '2026-02-28']))
        ->assertOk()
        ->assertJsonPath('range', ['from' => '2026-02-01', 'to' => '2026-02-28', 'days' => 28]);

    $this->getJson(route('api.admin.billing.revenue', ['range' => '365']))->assertUnprocessable()->assertJsonPath('errors.0.meta.source_field', 'range');
    $this->getJson(route('api.admin.billing.revenue', ['range' => 'custom']))->assertUnprocessable()->assertJsonPath('errors.0.meta.source_field', 'from')->assertJsonPath('errors.1.meta.source_field', 'to');
    $this->getJson(route('api.admin.billing.revenue', ['range' => 'custom', 'from' => '2026-11-10', 'to' => '2026-11-01']))->assertUnprocessable()->assertJsonPath('errors.0.meta.source_field', 'to');
    $this->getJson(route('api.admin.billing.revenue', ['range' => 'custom', 'from' => '2026-11-10', 'to' => '2026-11-17']))->assertUnprocessable()->assertJsonPath('errors.0.meta.source_field', 'to');
    $this->getJson(route('api.admin.billing.revenue', ['range' => 'custom', 'from' => '2025-01-01', 'to' => '2026-11-16']))->assertUnprocessable()->assertJsonPath('errors.0.meta.source_field', 'to');
    $this->getJson(route('api.admin.billing.revenue', ['range' => 'custom', 'from' => '2026-1-1', 'to' => '2026-11-16']))->assertUnprocessable()->assertJsonPath('errors.0.meta.source_field', 'from');
});

test('only root administrators, or the billing read permission, see revenue', function (): void {
    expect((new GetRevenueRequest)->permissions())->toBe([Permissions::AdminBillingRead]);

    $this->actingAsNonAdmin();
    $this->getJson(route('api.admin.billing.revenue'))->assertForbidden();
});

test('coupons, referrals and kept trials are reported apart and never counted as sold', function (): void {
    $buyer = User::factory()->create();
    $friend = User::factory()->create();

    ledger($buyer, TokenTransaction::TYPE_PURCHASE, 1000, '2026-11-15 10:00:00');
    // A store purchase with a coupon: 20 off a 200-token first period, charged 180.
    ledger($buyer, TokenTransaction::TYPE_SERVER_CHARGE, -180, '2026-11-15 11:00:00', ['meta' => json_encode(['coupon_id' => 1, 'coupon_discount' => 20, 'full_price' => 200])]);
    // A free trial kept by buying a period: one ordinary server charge.
    ledger($friend, TokenTransaction::TYPE_SERVER_CHARGE, -300, '2026-11-15 12:00:00', ['reference_type' => 'subscription', 'reference_id' => 'trial-convert:9']);
    // Free tokens: a wallet coupon and two referral rewards, one taken back after a refund.
    ledger($buyer, TokenTransaction::TYPE_COUPON, 250, '2026-11-15 13:00:00');
    ledger($buyer, TokenTransaction::TYPE_REFERRAL, 200, '2026-11-15 14:00:00');
    ledger($friend, TokenTransaction::TYPE_REFERRAL, 200, '2026-11-15 14:00:00');
    ledger($buyer, TokenTransaction::TYPE_REFERRAL, -200, '2026-11-16 09:00:00');
    // Outside the range.
    ledger($buyer, TokenTransaction::TYPE_COUPON, 999, '2026-10-01 13:00:00');

    $running = $this->createServerModel();
    subscription($running, ['status' => ServerSubscription::STATUS_TRIAL, 'price_tokens' => 0, 'is_trial' => true]);
    $trial = fn (array $attributes): bool => DB::table('shop_trials')->insert(array_merge([
        'hours' => 48, 'ends_at' => '2026-11-17 00:00:00', 'created_at' => '2026-11-10 00:00:00', 'updated_at' => '2026-11-10 00:00:00',
    ], $attributes));
    $trial(['user_id' => $running->owner_id, 'server_id' => $running->id]);
    $trial(['user_id' => $buyer->id, 'converted_at' => '2026-11-12 00:00:00']);
    $trial(['user_id' => $friend->id, 'expired_at' => '2026-11-12 00:00:00']);

    $this->getJson(route('api.admin.billing.revenue', ['range' => '7']))
        ->assertOk()
        ->assertJsonPath('tokens.sold', 1000)
        ->assertJsonPath('tokens.net', 1000)
        ->assertJsonPath('tokens.spent_on_servers', 480)
        ->assertJsonPath('users.paying', 1)
        ->assertJsonPath('promotions', [
            'coupon_discount_tokens' => 20,
            'coupon_purchases' => 1,
            'coupon_credit_tokens' => 250,
            'coupon_credits' => 1,
            'referral_reward_tokens' => 400,
            'referral_rewards' => 2,
            'referral_clawback_tokens' => 200,
        ])
        ->assertJsonPath('trials.started', 3)
        ->assertJsonPath('trials.running', 1)
        ->assertJsonPath('trials.converted', 1)
        ->assertJsonPath('trials.lapsed', 1)
        ->assertJsonPath('trials.conversion_rate', 50)
        ->assertJsonPath('daily.5.tokens_sold', 1000);
});
