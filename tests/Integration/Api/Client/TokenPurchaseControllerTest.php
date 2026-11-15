<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Client\TokenPurchaseControllerTest;

use Illuminate\Support\Str;
use Pterodactyl\Models\Billing\TebexCheckout;
use Pterodactyl\Models\Billing\TebexPayment;
use Pterodactyl\Models\Billing\TokenPack;
use Pterodactyl\Models\Billing\TokenTransaction;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Billing\TokenWallet;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

uses(ClientApiIntegrationTestCase::class);

function checkoutFor(User $user, ?TebexPayment $payment = null): TebexCheckout
{
    $pack = TokenPack::query()->create(['tebex_package_id' => '6012345', 'name' => '500 tokens', 'tokens' => 500, 'bonus_tokens' => 50]);

    return TebexCheckout::query()->create([
        'uuid' => (string) Str::uuid(),
        'user_id' => $user->id,
        'token_pack_id' => $pack->id,
        'basket_ident' => 'bkt-'.Str::random(8),
        'tebex_payment_id' => $payment?->id,
    ]);
}

function payment(string $status, int $tokens = 0): TebexPayment
{
    return TebexPayment::query()->create(['transaction_id' => 'tbx-'.Str::random(10), 'status' => $status, 'tokens' => $tokens, 'raw' => []]);
}

test('a checkout without a payment yet is pending', function (): void {
    $user = User::factory()->create();
    resolve(TokenWallet::class)->credit($user, 100, TokenTransaction::TYPE_PURCHASE, 'Earlier');
    $checkout = checkoutFor($user);

    $this->actingAs($user)->getJson('/api/client/account/wallet/purchases/'.$checkout->uuid)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson(['data' => [
            'id' => $checkout->uuid,
            'status' => 'pending',
            'pack_name' => '500 tokens',
            'tokens' => 0,
            'balance' => 100,
            'created_at' => $checkout->created_at?->toAtomString(),
        ]]);
});

test('reports the tokens Tebex confirmed and the new balance', function (): void {
    $user = User::factory()->create();
    resolve(TokenWallet::class)->credit($user, 550, TokenTransaction::TYPE_PURCHASE, 'Bought 500 tokens on Tebex');
    $checkout = checkoutFor($user, payment(TebexPayment::STATUS_COMPLETED, 550));

    $this->actingAs($user)->getJson('/api/client/account/wallet/purchases/'.$checkout->uuid)
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.tokens', 550)
        ->assertJsonPath('data.balance', 550);
});

test('maps every payment state to what the player sees', function (string $payment, string $expected): void {
    $user = User::factory()->create();
    // Tokens on the payment are only reported once it is completed.
    $checkout = checkoutFor($user, payment($payment, 550));

    $this->actingAs($user)->getJson('/api/client/account/wallet/purchases/'.$checkout->uuid)
        ->assertOk()
        ->assertJsonPath('data.status', $expected)
        ->assertJsonPath('data.tokens', $expected === 'completed' ? 550 : 0);
})->with([
    [TebexPayment::STATUS_PENDING, 'pending'],
    [TebexPayment::STATUS_COMPLETED, 'completed'],
    [TebexPayment::STATUS_REFUNDED, 'refunded'],
    [TebexPayment::STATUS_CHARGEBACK, 'refunded'],
    [TebexPayment::STATUS_DECLINED, 'declined'],
    [TebexPayment::STATUS_DISPUTED, 'review'],
    [TebexPayment::STATUS_UNMATCHED, 'review'],
    [TebexPayment::STATUS_UNDERPAID, 'review'],
]);

test("another user's checkout and unknown references are not found", function (): void {
    $owner = User::factory()->create();
    $checkout = checkoutFor($owner, payment(TebexPayment::STATUS_COMPLETED, 550));

    $this->actingAs(User::factory()->create())->getJson('/api/client/account/wallet/purchases/'.$checkout->uuid)->assertNotFound();
    $this->getJson('/api/client/account/wallet/purchases/'.Str::uuid())->assertNotFound();
    // The basket ident is not a way in either.
    $this->actingAs($owner)->getJson('/api/client/account/wallet/purchases/'.$checkout->basket_ident)->assertNotFound();
});

test('a guest cannot read a checkout', function (): void {
    $checkout = checkoutFor(User::factory()->create());

    $this->getJson('/api/client/account/wallet/purchases/'.$checkout->uuid)->assertUnauthorized();
});
