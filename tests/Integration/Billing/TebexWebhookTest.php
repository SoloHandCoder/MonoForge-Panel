<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Billing\TebexWebhookTest;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Pterodactyl\Models\Billing\TebexPayment;
use Pterodactyl\Models\Billing\TokenPack;
use Pterodactyl\Models\User;
use Pterodactyl\Models\UserNotice;
use Pterodactyl\Services\Billing\Tebex\TebexSettings;
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
            'price_paid' => ['amount' => 4.99, 'currency' => 'EUR'],
            'customer' => ['email' => 'buyer@example.com'],
            'products' => [[
                'id' => $packageId,
                'name' => 'Pack',
                'quantity' => $quantity,
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

test('a lost dispute charges the tokens back', function (): void {
    $user = User::factory()->create();
    TokenPack::query()->create(['tebex_package_id' => '100', 'name' => 'Small', 'tokens' => 100]);
    sendWebhook(paymentEvent('payment.completed', 'tbx-7', 100, ['user_id' => $user->id]))->assertOk();
    sendWebhook(paymentEvent('payment.dispute.opened', 'tbx-7', 100, ['user_id' => $user->id]))->assertOk();

    expect(TebexPayment::query()->where('transaction_id', 'tbx-7')->value('status'))->toBe(TebexPayment::STATUS_DISPUTED)
        ->and(wallet()->balance($user))->toBe(100);

    sendWebhook(paymentEvent('payment.dispute.lost', 'tbx-7', 100, ['user_id' => $user->id], status: 3))->assertOk();

    expect(wallet()->balance($user))->toBe(0)
        ->and(wallet()->hasTransaction(TebexPayment::LEDGER_REFERENCE, 'tbx-7', 'chargeback'))->toBeTrue();
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
