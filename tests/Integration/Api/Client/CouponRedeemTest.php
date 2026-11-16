<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Client\CouponRedeemTest;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Pterodactyl\Enum\OAuthProvider;
use Pterodactyl\Models\Billing\Coupon;
use Pterodactyl\Models\Billing\CouponRedemption;
use Pterodactyl\Models\Billing\TokenTransaction;
use Pterodactyl\Models\User;
use Pterodactyl\Models\UserOAuthAccount;
use Pterodactyl\Services\Billing\CouponService;
use Pterodactyl\Services\Billing\NetworkHash;
use Pterodactyl\Services\Billing\TokenWallet;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;
use RuntimeException;

uses(ClientApiIntegrationTestCase::class);

/**
 * @param  array<string, mixed>  $attributes
 */
function tokensCoupon(array $attributes = []): Coupon
{
    return Coupon::query()->forceCreate(['code' => 'LAUNCH', 'type' => Coupon::TYPE_TOKENS, 'value' => 250, 'enabled' => true, ...$attributes]);
}

/**
 * A user with a linked Discord account, which a free-tokens coupon needs.
 *
 * @param  array<string, mixed>  $attributes
 */
function redeemer(array $attributes = [], ?string $discordId = null): User
{
    $user = User::factory()->create($attributes);
    UserOAuthAccount::query()->create(['user_id' => $user->id, 'provider' => OAuthProvider::Discord, 'provider_user_id' => $discordId ?? (string) (500000 + $user->id)]);

    return $user;
}

function redeemAs(User $user, string $code, string $ip = '198.51.100.7'): TestResponse
{
    return test()->actingAs($user)->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/client/account/wallet/coupons', ['code' => $code]);
}

test('a free-tokens coupon credits the wallet once and is audited', function (): void {
    $coupon = tokensCoupon();
    $user = redeemer();

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
    $admin = redeemer(['root_admin' => true]);
    tokensCoupon(['created_by' => $admin->id]);

    redeemAs(redeemer(), 'LAUNCH', '198.51.100.7')->assertOk();
    // Same /24.
    redeemAs(redeemer(), 'LAUNCH', '198.51.100.200')
        ->assertStatus(422)
        ->assertJsonPath('errors.0.detail', "This code can't be used.");
    redeemAs(redeemer(), 'LAUNCH', '203.0.113.1')->assertOk();

    redeemAs($admin, 'LAUNCH', '192.0.2.1')
        ->assertStatus(422)
        ->assertJsonPath('errors.0.detail', "This code can't be used.");
});

test('a discount coupon is refused on the wallet, and unknown codes all get the same answer', function (): void {
    tokensCoupon(['code' => 'OFF20', 'type' => Coupon::TYPE_PERCENT, 'value' => 20]);
    tokensCoupon(['code' => 'OFF', 'enabled' => false]);
    $user = redeemer();

    redeemAs($user, 'OFF20')
        ->assertStatus(422)
        ->assertJsonPath('errors.0.detail', 'This coupon is a discount. Enter it at the store checkout.');
    redeemAs($user, 'OFF')->assertStatus(422)->assertJsonPath('errors.0.detail', "This code can't be used.");
    redeemAs($user, 'NOPE')->assertStatus(422)->assertJsonPath('errors.0.detail', "This code can't be used.");
    redeemAs($user, '<script>')->assertStatus(422)->assertJsonPath('errors.0.detail', "This code can't be used.");

    expect(resolve(TokenWallet::class)->balance($user))->toBe(0);
});

test('a new-customers coupon is refused to a user who bought tokens', function (): void {
    tokensCoupon(['new_customers_only' => true]);
    $buyer = redeemer();
    resolve(TokenWallet::class)->credit($buyer, 500, TokenTransaction::TYPE_PURCHASE, 'Bought tokens on Tebex', 'tebex_payment', 'tbx-9');

    redeemAs($buyer, 'LAUNCH')->assertStatus(422)->assertJsonPath('errors.0.detail', "This code can't be used.");
    redeemAs(redeemer(), 'LAUNCH', '203.0.113.5')->assertOk();
});

test('redeeming is throttled, and so are refused codes', function (): void {
    tokensCoupon();
    $user = redeemer();

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

test('a free-tokens coupon needs a linked account and is redeemed once per linked account', function (): void {
    tokensCoupon();

    redeemAs(User::factory()->create(), 'LAUNCH')
        ->assertStatus(422)
        ->assertJsonPath('errors.0.detail', CouponService::LINK_ACCOUNT);

    $first = redeemer([], '31337');
    redeemAs($first, 'LAUNCH', '198.51.100.7')->assertOk();
    expect(CouponRedemption::query()->sole()->discord_hash)->not->toBeNull();

    // The same Discord account, moved to a new account, on another network.
    UserOAuthAccount::query()->where('user_id', $first->id)->delete();
    redeemAs(redeemer([], '31337'), 'LAUNCH', '203.0.113.50')
        ->assertStatus(422)
        ->assertJsonPath('errors.0.detail', "This code can't be used.");
    expect(CouponRedemption::query()->count())->toBe(1);
});

test('the anti-abuse hashes refuse to run without an app key', function (): void {
    config()->set('app.key', '');

    expect(fn (): ?string => NetworkHash::of('198.51.100.7'))->toThrow(RuntimeException::class, 'APP_KEY');
});
