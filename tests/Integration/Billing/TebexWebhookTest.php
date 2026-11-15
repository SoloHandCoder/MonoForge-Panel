<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Billing\TebexWebhookTest;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Pterodactyl\Console\Scheduler;
use Pterodactyl\Models\ActivityLog;
use Pterodactyl\Models\ApiKey;
use Pterodactyl\Models\Billing\TebexCheckout;
use Pterodactyl\Models\Billing\TebexPayment;
use Pterodactyl\Models\Billing\TokenPack;
use Pterodactyl\Models\User;
use Pterodactyl\Models\UserNotice;
use Pterodactyl\Services\Billing\Tebex\TebexSettings;
use Pterodactyl\Services\Billing\Tebex\TebexWebhookProcessor;
use Pterodactyl\Services\Billing\TokenWallet;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Symfony\Component\HttpFoundation\Response;

uses(IntegrationTestCase::class, DatabaseTransactions::class);

const SECRET = 'whsec-test-secret';

beforeEach(function (): void {
    resolve(TebexSettings::class)->save('abcd-public', null, SECRET, []);
});

/**
 * @param  array<string, mixed>  $event
 * @return TestResponse<Response>
 */
function sendWebhook(array $event, string $ip = '18.209.80.3', ?string $signature = null): TestResponse
{
    $body = json_encode($event, JSON_THROW_ON_ERROR);

    return test()->call('POST', '/api/webhooks/tebex', server: [
        'REMOTE_ADDR' => $ip,
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SIGNATURE' => $signature ?? hash_hmac('sha256', hash('sha256', $body), SECRET),
    ], content: $body);
}

/**
 * A Tebex payment webhook as documented, for one product.
 *
 * @param  array<string, mixed>  $custom
 * @return array<string, mixed>
 */
function paymentEvent(string $type, string $transactionId, int|string $packageId, array $custom, int $status = 1, int $quantity = 1): array
{
    return [
        'id' => (string) Str::uuid(),
        'type' => $type,
        'date' => now()->toAtomString(),
        'subject' => [
            'transaction_id' => $transactionId,
            'status' => ['id' => $status, 'description' => 'Complete'],
            'price_paid' => ['amount' => round(4.99 * $quantity, 2), 'currency' => 'EUR'],
            'customer' => ['email' => 'buyer@example.com'],
            'products' => [[
                'id' => $packageId,
                'name' => 'Pack',
                'quantity' => $quantity,
                'base_price' => ['amount' => 4.99, 'currency' => 'EUR'],
                'paid_price' => ['amount' => round(4.99 * $quantity, 2), 'currency' => 'EUR'],
                'custom' => $custom,
            ]],
        ],
    ];
}

function wallet(): TokenWallet
{
    return resolve(TokenWallet::class);
}

test('answers the validation webhook with its id', function (): void {
    sendWebhook(['id' => 'abc-123', 'type' => 'validation.webhook', 'date' => now()->toAtomString(), 'subject' => []])
        ->assertOk()
        ->assertExactJson(['id' => 'abc-123']);
});

test('rejects webhooks from other addresses, with a bad signature or without a secret', function (): void {
    $event = ['id' => 'abc-123', 'type' => 'validation.webhook', 'date' => now()->toAtomString(), 'subject' => []];

    sendWebhook($event, ip: '203.0.113.9')->assertNotFound();
    sendWebhook($event, signature: str_repeat('0', 64))->assertForbidden();
    sendWebhook($event, signature: '')->assertForbidden();

    resolve(TebexSettings::class)->save('abcd-public', null, null, ['203.0.113.9']);
    sendWebhook($event, ip: '203.0.113.9')->assertOk();
    sendWebhook($event)->assertNotFound();
});

test('a signature over a different body is rejected', function (): void {
    $event = ['id' => 'abc-123', 'type' => 'validation.webhook', 'date' => now()->toAtomString(), 'subject' => []];
    $signature = hash_hmac('sha256', hash('sha256', json_encode($event, JSON_THROW_ON_ERROR)), SECRET);
    $event['id'] = 'abc-124';

    sendWebhook($event, signature: $signature)->assertForbidden();
});

test('credits a completed payment once, with the bonus, however often Tebex sends it', function (): void {
    $user = User::factory()->create();
    $pack = TokenPack::query()->create(['tebex_package_id' => '6012345', 'name' => '500 tokens', 'tokens' => 500, 'bonus_tokens' => 50]);
    $event = paymentEvent('payment.completed', 'tbx-1', 6012345, ['user_id' => $user->id, 'pack_id' => $pack->id]);

    sendWebhook($event)->assertOk();
    sendWebhook($event)->assertOk();
    // Another delivery of the same payment under a new webhook ID.
    sendWebhook([...$event, 'id' => (string) Str::uuid()])->assertOk();

    expect(wallet()->balance($user))->toBe(550)
        ->and(wallet()->hasTransaction(TebexPayment::LEDGER_REFERENCE, 'tbx-1', 'purchase'))->toBeTrue();

    $payment = TebexPayment::query()->where('transaction_id', 'tbx-1')->sole();
    expect($payment->status)->toBe(TebexPayment::STATUS_COMPLETED)
        ->and($payment->user_id)->toBe($user->id)
        ->and($payment->tokens)->toBe(550)
        ->and($payment->amount)->toBe('4.99')
        ->and($payment->currency)->toBe('EUR')
        ->and($payment->raw)->toHaveCount(2)
        ->and($payment->processed_at)->not->toBeNull()
        ->and($payment->flagged_at)->toBeNull()
        ->and(UserNotice::query()->where('user_id', $user->id)->where('level', 'success')->count())->toBe(1);
});

test('takes the tokens from the paid package, not from the pack named in custom data', function (): void {
    $user = User::factory()->create();
    TokenPack::query()->create(['tebex_package_id' => '100', 'name' => 'Small', 'tokens' => 100]);
    $big = TokenPack::query()->create(['tebex_package_id' => '999', 'name' => 'Big', 'tokens' => 10000]);

    sendWebhook(paymentEvent('payment.completed', 'tbx-2', 100, ['user_id' => $user->id, 'pack_id' => $big->id], quantity: 2))->assertOk();

    expect(wallet()->balance($user))->toBe(200);
});

test('a payment that is not complete, or matches no user or pack, credits nothing and is flagged', function (): void {
    $user = User::factory()->create();
    TokenPack::query()->create(['tebex_package_id' => '100', 'name' => 'Small', 'tokens' => 100]);

    sendWebhook(paymentEvent('payment.completed', 'tbx-3', 100, ['user_id' => $user->id], status: 19))->assertOk();
    sendWebhook(paymentEvent('payment.completed', 'tbx-4', 555, ['user_id' => $user->id]))->assertOk();
    sendWebhook(paymentEvent('payment.completed', 'tbx-5', 100, []))->assertOk();

    expect(wallet()->balance($user))->toBe(0)
        ->and(TebexPayment::query()->where('transaction_id', 'tbx-3')->value('status'))->toBe(TebexPayment::STATUS_PENDING)
        ->and(TebexPayment::query()->whereIn('transaction_id', ['tbx-4', 'tbx-5'])->pluck('status')->unique()->all())->toBe([TebexPayment::STATUS_UNMATCHED])
        ->and(TebexPayment::query()->whereNotNull('flagged_at')->count())->toBe(2);
});

test('a refund takes the tokens back once, even into a negative balance, and flags the payment', function (): void {
    $user = User::factory()->create();
    TokenPack::query()->create(['tebex_package_id' => '100', 'name' => 'Small', 'tokens' => 100, 'bonus_tokens' => 10]);
    sendWebhook(paymentEvent('payment.completed', 'tbx-6', 100, ['user_id' => $user->id]))->assertOk();
    wallet()->debit($user, 80, 'server_charge', 'Spent');

    sendWebhook(paymentEvent('payment.refunded', 'tbx-6', 100, ['user_id' => $user->id], status: 2))->assertOk();
    sendWebhook(paymentEvent('payment.dispute.lost', 'tbx-6', 100, ['user_id' => $user->id], status: 3))->assertOk();

    $payment = TebexPayment::query()->where('transaction_id', 'tbx-6')->sole();
    expect(wallet()->balance($user))->toBe(-80)
        ->and($payment->tokens_reversed)->toBe(110)
        ->and($payment->status)->toBe(TebexPayment::STATUS_CHARGEBACK)
        ->and($payment->flagged_at)->not->toBeNull()
        ->and(UserNotice::query()->where('user_id', $user->id)->where('level', 'danger')->count())->toBe(1);

    // A completion arriving after the refund credits nothing.
    sendWebhook(paymentEvent('payment.completed', 'tbx-6', 100, ['user_id' => $user->id]))->assertOk();
    expect(wallet()->balance($user))->toBe(-80);
});

test('an opened dispute holds the tokens back and a lost one keeps them', function (): void {
    $user = User::factory()->create();
    TokenPack::query()->create(['tebex_package_id' => '100', 'name' => 'Small', 'tokens' => 100]);
    sendWebhook(paymentEvent('payment.completed', 'tbx-7', 100, ['user_id' => $user->id]))->assertOk();
    wallet()->debit($user, 30, 'server_charge', 'Spent');
    $opened = paymentEvent('payment.dispute.opened', 'tbx-7', 100, ['user_id' => $user->id]);
    sendWebhook($opened)->assertOk();
    sendWebhook([...$opened, 'id' => (string) Str::uuid()])->assertOk();

    $payment = TebexPayment::query()->where('transaction_id', 'tbx-7')->sole();
    expect($payment->status)->toBe(TebexPayment::STATUS_DISPUTED)
        ->and($payment->tokens_reversed)->toBe(100)
        // Into a negative balance, once however often Tebex sends it.
        ->and(wallet()->balance($user))->toBe(-30)
        ->and(UserNotice::query()->where('user_id', $user->id)->where('title', 'Payment disputed')->count())->toBe(1);

    sendWebhook(paymentEvent('payment.dispute.lost', 'tbx-7', 100, ['user_id' => $user->id], status: 3))->assertOk();

    expect(wallet()->balance($user))->toBe(-30)
        ->and(TebexPayment::query()->where('transaction_id', 'tbx-7')->value('status'))->toBe(TebexPayment::STATUS_CHARGEBACK);
});

test('a dispute won by the seller gives the held tokens back once', function (): void {
    $user = User::factory()->create();
    TokenPack::query()->create(['tebex_package_id' => '100', 'name' => 'Small', 'tokens' => 100]);
    sendWebhook(paymentEvent('payment.completed', 'tbx-9', 100, ['user_id' => $user->id]))->assertOk();
    sendWebhook(paymentEvent('payment.dispute.opened', 'tbx-9', 100, ['user_id' => $user->id]))->assertOk();

    expect(wallet()->balance($user))->toBe(0);

    $won = paymentEvent('payment.dispute.won', 'tbx-9', 100, ['user_id' => $user->id]);
    sendWebhook($won)->assertOk();
    sendWebhook([...$won, 'id' => (string) Str::uuid()])->assertOk();

    $payment = TebexPayment::query()->where('transaction_id', 'tbx-9')->sole();
    expect(wallet()->balance($user))->toBe(100)
        ->and($payment->status)->toBe(TebexPayment::STATUS_COMPLETED)
        ->and($payment->tokens_reversed)->toBe(0);

    // A refund afterwards still takes the tokens back.
    sendWebhook(paymentEvent('payment.refunded', 'tbx-9', 100, ['user_id' => $user->id], status: 2))->assertOk();
    expect(wallet()->balance($user))->toBe(0);
});

test('a payment below the package price is not credited, is flagged and root admins are told', function (array $prices): void {
    $user = User::factory()->create();
    $admin = User::factory()->create(['root_admin' => true]);
    TokenPack::query()->create(['tebex_package_id' => '100', 'name' => 'Small', 'tokens' => 100]);
    $event = paymentEvent('payment.completed', 'tbx-low', 100, ['user_id' => $user->id]);
    $event['subject'] = array_replace_recursive($event['subject'], $prices);
    if (array_key_exists('drop', $prices)) {
        unset($event['subject']['products'][0]['base_price'], $event['subject']['drop']);
    }

    sendWebhook($event)->assertOk();
    sendWebhook([...$event, 'id' => (string) Str::uuid()])->assertOk();

    $payment = TebexPayment::query()->where('transaction_id', 'tbx-low')->sole();
    expect(wallet()->balance($user))->toBe(0)
        ->and($payment->status)->toBe(TebexPayment::STATUS_UNDERPAID)
        ->and($payment->flagged_at)->not->toBeNull()
        ->and($payment->processed_at)->toBeNull()
        ->and(UserNotice::query()->where('user_id', $admin->id)->where('title', 'Tebex payment needs a look')->count())->toBe(1)
        ->and(UserNotice::query()->where('user_id', $user->id)->exists())->toBeFalse();
})->with([
    'a coupon' => [['price_paid' => ['amount' => 0.99], 'products' => [['paid_price' => ['amount' => 0.99]]]]],
    'a product discount with the full total' => [['products' => [['paid_price' => ['amount' => 1.00]]]]],
    'another currency' => [['price_paid' => ['currency' => 'USD'], 'products' => [['paid_price' => ['currency' => 'USD']]]]],
    'no package price' => [['drop' => true]],
]);

test('token amounts beyond 32 bits are stored and credited', function (): void {
    $user = User::factory()->create();
    TokenPack::query()->create(['tebex_package_id' => '100', 'name' => 'Huge', 'tokens' => 3_000_000_000]);

    sendWebhook(paymentEvent('payment.completed', 'tbx-big', 100, ['user_id' => $user->id], quantity: 2))->assertOk();

    expect(wallet()->balance($user))->toBe(6_000_000_000)
        ->and(TebexPayment::query()->where('transaction_id', 'tbx-big')->value('tokens'))->toBe(6_000_000_000);
});

test('reads the user from the basket custom data on the payment when the products carry none', function (): void {
    $user = User::factory()->create();
    TokenPack::query()->create(['tebex_package_id' => '100', 'name' => 'Small', 'tokens' => 100]);
    $event = paymentEvent('payment.completed', 'tbx-8', 100, []);
    $event['subject']['products'][0]['custom'] = null;
    $event['subject']['custom'] = ['user_id' => (string) $user->id];

    sendWebhook($event)->assertOk();

    expect(wallet()->balance($user))->toBe(100);
});

/**
 * @param  array<string, string>  $server  extra server variables (headers as HTTP_*)
 * @return TestResponse<Response>
 */
function sendValidationFrom(string $peer, array $server = []): TestResponse
{
    $body = json_encode(['id' => 'abc-123', 'type' => 'validation.webhook', 'date' => now()->toAtomString(), 'subject' => []], JSON_THROW_ON_ERROR);

    return test()->call('POST', '/api/webhooks/tebex', server: [
        'REMOTE_ADDR' => $peer,
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SIGNATURE' => hash_hmac('sha256', hash('sha256', $body), SECRET),
        ...$server,
    ], content: $body);
}

test('behind the Cloudflare tunnel the allowlist checks CF-Connecting-IP, never the loopback peer', function (): void {
    // cloudflared on the same host: the peer is loopback and Cloudflare names the client.
    sendValidationFrom('127.0.0.1', ['HTTP_CF_CONNECTING_IP' => '18.209.80.3'])->assertOk();
    // Loopback without the header is not Tebex.
    sendValidationFrom('127.0.0.1')->assertNotFound();
    // An outside peer cannot claim to be Tebex with the header.
    sendValidationFrom('203.0.113.9', ['HTTP_CF_CONNECTING_IP' => '18.209.80.3'])->assertNotFound();
    sendValidationFrom('203.0.113.9', ['HTTP_X_FORWARDED_FOR' => '18.209.80.3'])->assertNotFound();
});

test('the webhook throttle is per client address', function (): void {
    resolve(TebexSettings::class)->save('abcd-public', null, null, ['18.209.80.3', '54.87.231.232']);

    for ($i = 0; $i < 120; $i++) {
        sendValidationFrom('127.0.0.1', ['HTTP_CF_CONNECTING_IP' => '203.0.113.'.($i % 2 === 0 ? '9' : '10')]);
    }

    // Another address flooding through the same tunnel does not use up Tebex's allowance.
    sendValidationFrom('127.0.0.1', ['HTTP_CF_CONNECTING_IP' => '18.209.80.3'])->assertOk();

    for ($i = 0; $i < 119; $i++) {
        sendValidationFrom('127.0.0.1', ['HTTP_CF_CONNECTING_IP' => '18.209.80.3']);
    }

    sendValidationFrom('127.0.0.1', ['HTTP_CF_CONNECTING_IP' => '18.209.80.3'])->assertTooManyRequests();
    sendValidationFrom('127.0.0.1', ['HTTP_CF_CONNECTING_IP' => '54.87.231.232'])->assertOk();
});

test('webhook bodies older than 90 days lose the buyer details, the payments stay', function (): void {
    $user = User::factory()->create();
    TokenPack::query()->create(['tebex_package_id' => '100', 'name' => 'Small', 'tokens' => 100]);
    $old = paymentEvent('payment.completed', 'tbx-old', 100, ['user_id' => $user->id]);

    $this->travelTo(now()->subDays(91));
    sendWebhook($old)->assertOk();
    $this->travelBack();
    sendWebhook(paymentEvent('payment.completed', 'tbx-new', 100, ['user_id' => $user->id]))->assertOk();

    $this->artisan('p:billing:prune-tebex-payloads')->assertSuccessful();

    $pruned = TebexPayment::query()->where('transaction_id', 'tbx-old')->sole();
    $kept = TebexPayment::query()->where('transaction_id', 'tbx-new')->sole();
    expect($pruned->raw)->toEqual([['id' => $old['id'], 'type' => 'payment.completed', 'date' => $old['date']]])
        ->and(json_encode($pruned->raw))->not->toContain('buyer@example.com')
        ->and($pruned->raw_pruned_at)->not->toBeNull()
        ->and($pruned->status)->toBe(TebexPayment::STATUS_COMPLETED)
        ->and($pruned->tokens)->toBe(100)
        ->and($pruned->toApiArray()['events'])->toBe(['payment.completed'])
        ->and(json_encode($kept->raw))->toContain('buyer@example.com');

    // The same webhook again is still recognised and credits nothing.
    sendWebhook($old)->assertOk();
    expect(wallet()->balance($user))->toBe(200);
});

test('the payload pruning runs daily', function (): void {
    $schedule = new Schedule;
    $this->app->make(Scheduler::class)($schedule);
    $events = array_values(array_filter($schedule->events(), fn (Event $event): bool => str_contains((string) $event->command, 'p:billing:prune-tebex-payloads')));

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('0 0 * * *');
});

test('the price check counts the quantity, whether paid_price is per package or per line', function (array $prices, bool $credited): void {
    $user = User::factory()->create();
    TokenPack::query()->create(['tebex_package_id' => '100', 'name' => 'Small', 'tokens' => 100]);
    $event = paymentEvent('payment.completed', 'tbx-qty', 100, ['user_id' => $user->id], quantity: 2);
    $event['subject']['price_paid']['amount'] = $prices['total'];
    $event['subject']['products'][0]['paid_price']['amount'] = $prices['line'];

    sendWebhook($event)->assertOk();

    expect(wallet()->balance($user))->toBe($credited ? 200 : 0)
        ->and(TebexPayment::query()->where('transaction_id', 'tbx-qty')->value('status'))->toBe($credited ? TebexPayment::STATUS_COMPLETED : TebexPayment::STATUS_UNDERPAID);
})->with([
    'line total, full price' => [['total' => 9.98, 'line' => 9.98], true],
    'per package, full price' => [['total' => 9.98, 'line' => 4.99], true],
    'half off, ambiguous paid price' => [['total' => 4.99, 'line' => 4.99], false],
    'below the price either way' => [['total' => 4.00, 'line' => 2.00], false],
]);

/**
 * An underpaid payment of 100 tokens, flagged for the admin.
 */
function flaggedPayment(User $user, string $transactionId = 'tbx-flag'): TebexPayment
{
    TokenPack::query()->firstOrCreate(['tebex_package_id' => '100'], ['name' => 'Small', 'tokens' => 100]);
    $event = paymentEvent('payment.completed', $transactionId, 100, ['user_id' => $user->id]);
    $event['subject']['price_paid']['amount'] = 0.99;
    $event['subject']['products'][0]['paid_price']['amount'] = 0.99;
    sendWebhook($event)->assertOk();

    return TebexPayment::query()->where('transaction_id', $transactionId)->sole();
}

test('an admin approves a flagged payment once, and a later refund takes the tokens back', function (): void {
    $admin = User::factory()->create(['root_admin' => true]);
    $user = User::factory()->create();
    $payment = flaggedPayment($user);

    expect($payment->status)->toBe(TebexPayment::STATUS_UNDERPAID)
        ->and(UserNotice::query()->where('user_id', $admin->id)->value('body'))->toContain('Approve and credit');

    // The shortfall must be accepted explicitly, and the list shows why it was flagged.
    $this->actingAs($admin)->getJson(route('api.admin.shop.tebex.payments'))
        ->assertJsonPath('data.0.flag_reason', '0.99 EUR was paid for 1 × a package priced 4.99 EUR');
    $this->actingAs($admin)->postJson(route('api.admin.shop.tebex.payments.approve', $payment->id))
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.detail', fn (string $detail): bool => str_contains($detail, 'does not cover its package price'));
    expect(wallet()->balance($user))->toBe(0);

    $this->actingAs($admin)->postJson(route('api.admin.shop.tebex.payments.approve', $payment->id), ['accept_underpaid' => true])
        ->assertOk()
        ->assertJsonPath('status', TebexPayment::STATUS_COMPLETED)
        ->assertJsonPath('tokens', 100);
    $this->actingAs($admin)->postJson(route('api.admin.shop.tebex.payments.approve', $payment->id), ['accept_underpaid' => true])->assertOk();

    expect(wallet()->balance($user))->toBe(100)
        ->and(ActivityLog::query()->where('event', 'admin:tebex-payment.approve')->count())->toBe(1);

    sendWebhook(paymentEvent('payment.refunded', 'tbx-flag', 100, ['user_id' => $user->id], status: 2))->assertOk();
    expect(wallet()->balance($user))->toBe(0)
        ->and($payment->fresh()->tokens_reversed)->toBe(100);
});

test('an unmatched payment is credited to the user the admin picks', function (): void {
    $admin = User::factory()->create(['root_admin' => true]);
    $user = User::factory()->create();
    TokenPack::query()->create(['tebex_package_id' => '100', 'name' => 'Small', 'tokens' => 100]);
    sendWebhook(paymentEvent('payment.completed', 'tbx-nouser', 100, []))->assertOk();
    $payment = TebexPayment::query()->where('transaction_id', 'tbx-nouser')->sole();

    $this->actingAs($admin)->postJson(route('api.admin.shop.tebex.payments.approve', $payment->id))->assertStatus(409);
    $this->actingAs($admin)->postJson(route('api.admin.shop.tebex.payments.approve', $payment->id), ['user_id' => $user->id])->assertOk();

    expect(wallet()->balance($user))->toBe(100)
        ->and($payment->fresh()->user_id)->toBe($user->id);
});

test('only flagged payments can be approved, only by root admins in a session', function (): void {
    $user = User::factory()->create();
    $payment = flaggedPayment($user);
    $staff = User::factory()->create(['root_admin' => false]);

    $this->actingAs($staff)->postJson(route('api.admin.shop.tebex.payments.approve', $payment->id))->assertForbidden();

    $admin = User::factory()->create(['root_admin' => true]);
    $key = ApiKey::factory()->create(['user_id' => $admin->id, 'key_type' => ApiKey::TYPE_ACCOUNT]);
    Auth::forgetGuards();
    $this->withHeader('Accept', 'application/vnd.pterodactyl.v1+json')
        ->withHeader('Authorization', 'Bearer '.$key->identifier.decrypt($key->token))
        ->postJson(route('api.admin.shop.tebex.payments.approve', $payment->id))
        ->assertForbidden();
    expect(wallet()->balance($user))->toBe(0);
});

test('a payment that is not flagged for approval cannot be approved', function (): void {
    $admin = User::factory()->create(['root_admin' => true]);
    $refunded = flaggedPayment(User::factory()->create());
    $refunded->forceFill(['status' => TebexPayment::STATUS_REFUNDED])->save();

    $this->actingAs($admin)->postJson(route('api.admin.shop.tebex.payments.approve', $refunded->id))
        ->assertStatus(409)
        ->assertJsonPath('errors.0.detail', 'Only an underpaid or unmatched payment can be approved.');
});

test('a payment that matched a user is credited to that user only, and never to the approving admin', function (): void {
    $admin = User::factory()->create(['root_admin' => true]);
    $buyer = User::factory()->create();
    $other = User::factory()->create();
    $payment = flaggedPayment($buyer);

    foreach ([$other, $admin] as $recipient) {
        $this->actingAs($admin)->postJson(route('api.admin.shop.tebex.payments.approve', $payment->id), ['user_id' => $recipient->id, 'accept_underpaid' => true])
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.detail', "This payment belongs to user #{$buyer->id}; its tokens can only be credited to them.");
    }

    expect(wallet()->balance($other))->toBe(0)
        ->and(wallet()->balance($admin))->toBe(0)
        ->and($payment->fresh()->user_id)->toBe($buyer->id)
        ->and($payment->fresh()->processed_at)->toBeNull();
});

test('an unmatched payment cannot be credited to the approving admin', function (): void {
    $admin = User::factory()->create(['root_admin' => true]);
    TokenPack::query()->create(['tebex_package_id' => '100', 'name' => 'Small', 'tokens' => 100]);
    sendWebhook(paymentEvent('payment.completed', 'tbx-self', 100, []))->assertOk();
    $payment = TebexPayment::query()->where('transaction_id', 'tbx-self')->sole();

    $this->actingAs($admin)->postJson(route('api.admin.shop.tebex.payments.approve', $payment->id), ['user_id' => $admin->id])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.detail', 'You cannot credit a payment to yourself.');
    expect(wallet()->balance($admin))->toBe(0);
});

test('an unmatched payment that is also underpaid needs the shortfall accepted', function (): void {
    $admin = User::factory()->create(['root_admin' => true]);
    $user = User::factory()->create();
    TokenPack::query()->create(['tebex_package_id' => '100', 'name' => 'Small', 'tokens' => 100]);
    $event = paymentEvent('payment.completed', 'tbx-both', 100, []);
    $event['subject']['price_paid']['amount'] = 0.99;
    $event['subject']['products'][0]['paid_price']['amount'] = 0.99;
    sendWebhook($event)->assertOk();
    $payment = TebexPayment::query()->where('transaction_id', 'tbx-both')->sole();

    expect($payment->status)->toBe(TebexPayment::STATUS_UNMATCHED)
        ->and($payment->flag_reason)->toBe('it matched no panel user; also 0.99 EUR was paid for 1 × a package priced 4.99 EUR');

    $this->actingAs($admin)->postJson(route('api.admin.shop.tebex.payments.approve', $payment->id), ['user_id' => $user->id])->assertUnprocessable();
    expect(wallet()->balance($user))->toBe(0);

    $this->actingAs($admin)->postJson(route('api.admin.shop.tebex.payments.approve', $payment->id), ['user_id' => $user->id, 'accept_underpaid' => true])->assertOk();
    expect(wallet()->balance($user))->toBe(100)
        ->and(ActivityLog::query()->where('event', 'admin:tebex-payment.approve')->sole()->properties->get('accepted_underpaid'))->toContain('0.99 EUR');
});

test('a second approve of a stale model credits and logs nothing', function (): void {
    $admin = User::factory()->create(['root_admin' => true]);
    $payment = flaggedPayment(User::factory()->create());
    $stale = TebexPayment::query()->findOrFail($payment->id);
    $processor = resolve(TebexWebhookProcessor::class);

    expect($processor->approve($payment, $admin, null, true))->toBeTrue()
        // A second admin's copy was loaded before the first approval.
        ->and($processor->approve($stale, $admin, null, true))->toBeFalse()
        ->and($stale->processed_at)->not->toBeNull()
        ->and(ActivityLog::query()->where('event', 'admin:tebex-payment.approve')->count())->toBe(1);
});

test("links the payment to the buyer's own checkout, so the return page can follow it", function (): void {
    $user = User::factory()->create();
    $pack = TokenPack::query()->create(['tebex_package_id' => '6012345', 'name' => '500 tokens', 'tokens' => 500, 'bonus_tokens' => 50]);
    $checkout = TebexCheckout::query()->create(['uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'token_pack_id' => $pack->id]);
    $custom = ['user_id' => $user->id, 'pack_id' => $pack->id, 'checkout' => $checkout->uuid];

    sendWebhook(paymentEvent('payment.completed', 'tbx-link', 6012345, $custom))->assertOk();

    $payment = TebexPayment::query()->where('transaction_id', 'tbx-link')->sole();
    expect($checkout->refresh()->tebex_payment_id)->toBe($payment->id)
        ->and($checkout->status())->toBe(TebexCheckout::STATUS_COMPLETED);

    // A refund of the same transaction keeps the link and the page shows it.
    sendWebhook(paymentEvent('payment.refunded', 'tbx-link', 6012345, $custom))->assertOk();
    expect($checkout->refresh()->tebex_payment_id)->toBe($payment->id)
        ->and($checkout->status())->toBe(TebexCheckout::STATUS_REFUNDED);

    // Another payment naming the same checkout does not take it over.
    sendWebhook(paymentEvent('payment.completed', 'tbx-other', 6012345, $custom))->assertOk();
    expect($checkout->refresh()->tebex_payment_id)->toBe($payment->id);
});

test("a payment is never linked to another user's checkout", function (): void {
    $owner = User::factory()->create();
    $buyer = User::factory()->create();
    $pack = TokenPack::query()->create(['tebex_package_id' => '6012345', 'name' => '500 tokens', 'tokens' => 500]);
    $checkout = TebexCheckout::query()->create(['uuid' => (string) Str::uuid(), 'user_id' => $owner->id, 'token_pack_id' => $pack->id]);

    sendWebhook(paymentEvent('payment.completed', 'tbx-foreign', 6012345, ['user_id' => $buyer->id, 'pack_id' => $pack->id, 'checkout' => $checkout->uuid]))->assertOk();

    expect($checkout->refresh()->tebex_payment_id)->toBeNull()
        ->and(wallet()->balance($buyer))->toBe(500);
});
