<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Client\CouponRedeemTest;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Pterodactyl\Models\Billing\Coupon;
use Pterodactyl\Models\Billing\CouponRedemption;
use Pterodactyl\Models\Billing\TokenTransaction;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Billing\CouponService;
use Pterodactyl\Services\Billing\NetworkHash;
use Pterodactyl\Services\Billing\TokenWallet;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

uses(ClientApiIntegrationTestCase::class);

/**
 * @param  array<string, mixed>  $attributes
 */
function tokensCoupon(array $attributes = []): Coupon
{
    return Coupon::query()->forceCreate(['code' => 'LAUNCH', 'type' => Coupon::TYPE_TOKENS, 'value' => 250, 'enabled' => true, ...$attributes]);
}

function redeemAs(User $user, string $code, string $ip = '198.51.100.7'): TestResponse
{
    return test()->actingAs($user)->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/client/account/wallet/coupons', ['code' => $code]);
}

test('a free-tokens coupon credits the wallet once and is audited', function (): void {
    $coupon = tokensCoupon();
    $user = User::factory()->create();

    redeemAs($user, 'launch')
        ->assertOk()
        ->assertJsonPath('code', 'LAUNCH')
        ->assertJsonPath('tokens', 250)
        ->assertJsonPath('balance', 250);

    $transaction = TokenTransaction::query()->where('user_id', $user->id)->sole();
    $redemption = CouponRedemption::query()->sole();
    expect($transaction->type)->toBe(TokenTransaction::TYPE_COUPON)
        ->and($transaction->reference_type)->toBe(CouponService::LEDGER_REFERENCE)
        ->and($transaction->reference_id)->toBe((string) $redemption->id)
        ->and($redemption->network_hash)->toBe(NetworkHash::of('198.51.100.7'))
        ->and($coupon->refresh()->redemptions_count)->toBe(1);
    $this->assertActivityLogged('user:coupon.redeem');

    redeemAs($user, 'LAUNCH')
        ->assertStatus(422)
        ->assertJsonPath('errors.0.detail', 'You have already used this coupon.');
    expect(resolve(TokenWallet::class)->balance($user))->toBe(250);
});

test('a free-tokens coupon is redeemed once per network, and never by the admin who made it', function (): void {
    $admin = User::factory()->create(['root_admin' => true]);
    tokensCoupon(['created_by' => $admin->id]);

    redeemAs(User::factory()->create(), 'LAUNCH', '198.51.100.7')->assertOk();
    // Same /24.
    redeemAs(User::factory()->create(), 'LAUNCH', '198.51.100.200')
        ->assertStatus(422)
        ->assertJsonPath('errors.0.detail', 'This coupon was already redeemed from your network.');
    redeemAs(User::factory()->create(), 'LAUNCH', '203.0.113.1')->assertOk();

    redeemAs($admin, 'LAUNCH', '192.0.2.1')
        ->assertStatus(422)
        ->assertJsonPath('errors.0.detail', 'You cannot redeem a coupon you created.');
});

test('a discount coupon is refused on the wallet, and unknown codes all get the same answer', function (): void {
    tokensCoupon(['code' => 'OFF20', 'type' => Coupon::TYPE_PERCENT, 'value' => 20]);
    tokensCoupon(['code' => 'OFF', 'enabled' => false]);
    $user = User::factory()->create();

    redeemAs($user, 'OFF20')
        ->assertStatus(422)
        ->assertJsonPath('errors.0.detail', 'This coupon is a discount. Enter it at the store checkout.');
    redeemAs($user, 'OFF')->assertStatus(422)->assertJsonPath('errors.0.detail', 'This coupon code is not valid.');
    redeemAs($user, 'NOPE')->assertStatus(422)->assertJsonPath('errors.0.detail', 'This coupon code is not valid.');
    redeemAs($user, '<script>')->assertStatus(422)->assertJsonPath('errors.0.detail', 'This coupon code is not valid.');

    expect(resolve(TokenWallet::class)->balance($user))->toBe(0);
});

test('a new-customers coupon is refused to a user who bought tokens', function (): void {
    tokensCoupon(['new_customers_only' => true]);
    $buyer = User::factory()->create();
    resolve(TokenWallet::class)->credit($buyer, 500, TokenTransaction::TYPE_PURCHASE, 'Bought tokens on Tebex', 'tebex_payment', 'tbx-9');

    redeemAs($buyer, 'LAUNCH')->assertStatus(422)->assertJsonPath('errors.0.detail', 'This coupon is only for new customers.');
    redeemAs(User::factory()->create(), 'LAUNCH', '203.0.113.5')->assertOk();
});

test('redeeming is throttled, and so are refused codes', function (): void {
    tokensCoupon();
    $user = User::factory()->create();

    for ($i = 0; $i < 5; $i++) {
        redeemAs($user, 'GUESS'.$i)->assertStatus(422);
    }

    redeemAs($user, 'LAUNCH')->assertStatus(429);

    // After the per-minute limit, the failures still count towards the 10-minute one.
    foreach (range(1, CouponService::MAX_FAILURES - 5) as $i) {
        RateLimiter::hit('coupon-failures:'.$user->id, CouponService::FAILURE_WINDOW);
    }

    $this->travel(61)->seconds();
    redeemAs($user, 'LAUNCH')
        ->assertStatus(422)
        ->assertJsonPath('errors.0.detail', 'Too many coupon codes were tried. Please wait a few minutes and try again.');
    expect(resolve(TokenWallet::class)->balance($user))->toBe(0);
});

test('the network hash covers a /24 or a /48 and is keyed', function (): void {
    expect(NetworkHash::network('198.51.100.7'))->toBe('198.51.100.0/24')
        ->and(NetworkHash::network('::ffff:198.51.100.7'))->toBe('198.51.100.0/24')
        ->and(NetworkHash::network('2001:db8:abcd:12::1'))->toBe('2001:db8:abcd::/48')
        ->and(NetworkHash::network('not an ip'))->toBeNull()
        ->and(NetworkHash::of('198.51.100.7'))->toBe(NetworkHash::of('198.51.100.250'))
        ->and(NetworkHash::of('198.51.100.7'))->not->toBe(NetworkHash::of('198.51.101.7'))
        ->and(NetworkHash::of('198.51.100.7'))->not->toBe(hash('sha256', 'network:198.51.100.0/24'));
});
