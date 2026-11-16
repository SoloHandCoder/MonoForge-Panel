<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Client\Store\StoreCouponTest;

use Illuminate\Support\Arr;
use Pterodactyl\Contracts\Servers\CreatesServers;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Billing\Coupon;
use Pterodactyl\Models\Billing\CouponRedemption;
use Pterodactyl\Models\Billing\ServerSubscription;
use Pterodactyl\Models\Billing\ShopCategory;
use Pterodactyl\Models\Billing\ShopPeriod;
use Pterodactyl\Models\Billing\ShopPlan;
use Pterodactyl\Models\Billing\TokenTransaction;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Objects\DeploymentObject;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Billing\CouponService;
use Pterodactyl\Services\Billing\StorePurchaseService;
use Pterodactyl\Services\Billing\TokenWallet;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;
use RuntimeException;
use Throwable;

uses(ClientApiIntegrationTestCase::class);

/** Stands in for Wings, like the purchase tests: persists the server like CreateServer does. */
final class CouponFakeServerCreation implements CreatesServers
{
    public ?Throwable $failure = null;

    public function create(array $input, ?DeploymentObject $deployment = null): Server
    {
        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }

        $allocation = Allocation::query()->findOrFail($input['allocation_id']);
        $server = Server::factory()->create([
            ...Arr::only($input, ['external_id', 'name', 'owner_id', 'egg_id', 'image', 'startup', 'memory', 'swap', 'disk', 'io', 'cpu', 'database_limit', 'allocation_limit', 'backup_limit', 'allocation_id']),
            'node_id' => $allocation->node_id,
        ]);
        $allocation->forceFill(['server_id' => $server->id])->save();

        return $server;
    }
}

beforeEach(function (): void {
    config(['monoforge.shop.enabled' => true]);

    $this->creation = new CouponFakeServerCreation;
    $this->app->instance(CreatesServers::class, $this->creation);

    $this->node = Node::factory()->create(['location_id' => Location::factory()->create()->id, 'memory' => 16384, 'disk' => 100000, 'shop_enabled' => true]);
    Allocation::factory()->count(4)->create(['node_id' => $this->node->id]);
    $this->egg = Egg::query()->where('author', 'support@pterodactyl.io')->where('name', 'Bungeecord')->firstOrFail();

    $this->category = ShopCategory::query()->forceCreate(['name' => 'Minecraft', 'slug' => 'minecraft-'.uniqid(), 'sort' => 0, 'visible' => true]);
    $this->plan = ShopPlan::query()->forceCreate([
        'category_id' => $this->category->id, 'name' => 'Starter', 'description' => '', 'visible' => true, 'sort' => 0,
        'price_tokens' => 500, 'memory' => 2048, 'swap' => 0, 'disk' => 10000, 'cpu' => 100, 'io' => 500,
        'databases' => 1, 'backups' => 1, 'allocations' => 0, 'egg_ids' => [$this->egg->id], 'node_ids' => null,
    ]);
    $this->period = ShopPeriod::query()->forceCreate(['name' => 'Monthly', 'days' => 30, 'price_modifier_percent' => 0, 'sort' => 0, 'enabled' => true]);

    $this->user = User::factory()->create();
    resolve(TokenWallet::class)->credit($this->user, 1000, 'admin_adjust', 'Test balance');
});

/**
 * @param  array<string, mixed>  $attributes
 */
function coupon(array $attributes = []): Coupon
{
    return Coupon::query()->forceCreate([
        'code' => 'WELCOME',
        'type' => Coupon::TYPE_PERCENT,
        'value' => 20,
        'enabled' => true,
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function couponOrder(array $overrides = []): array
{
    $test = pterodactylTestCase();

    return [
        'plan_id' => $test->plan->id,
        'period_id' => $test->period->id,
        'egg_id' => $test->egg->id,
        'location_id' => $test->node->location_id,
        'name' => 'Survival',
        'coupon' => 'welcome',
        'expected_total' => 400,
        'idempotency_key' => 'key-'.str_repeat('c', 20),
        ...$overrides,
    ];
}

function couponBalance(User $user): int
{
    return resolve(TokenWallet::class)->balance($user);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function quoteBody(array $overrides = []): array
{
    $test = pterodactylTestCase();

    return ['plan_id' => $test->plan->id, 'period_id' => $test->period->id, 'location_id' => $test->node->location_id, 'coupon' => 'Welcome', ...$overrides];
}

test('the quote shows what a coupon takes off the first period, without redeeming it', function (): void {
    $coupon = coupon();

    $this->actingAs($this->user)->postJson('/api/client/store/quote', quoteBody())
        ->assertOk()
        ->assertJsonPath('quote.total_per_period', 500)
        ->assertJsonPath('coupon.code', 'WELCOME')
        ->assertJsonPath('coupon.valid', true)
        ->assertJsonPath('coupon.type', 'percent')
        ->assertJsonPath('coupon.discount_tokens', 100)
        ->assertJsonPath('coupon.first_period_tokens', 400);

    expect($coupon->refresh()->redemptions_count)->toBe(0)
        ->and(CouponRedemption::query()->count())->toBe(0);

    $this->actingAs($this->user)->postJson('/api/client/store/quote', quoteBody(['coupon' => 'NOPE']))
        ->assertOk()
        ->assertJsonPath('coupon.valid', false)
        ->assertJsonPath('coupon.message', 'This coupon code is not valid.')
        ->assertJsonPath('coupon.first_period_tokens', 500);

    $this->actingAs($this->user)->postJson('/api/client/store/quote', quoteBody(['coupon' => null]))
        ->assertOk()
        ->assertJsonPath('coupon', null);
});

test('a percent coupon discounts only the first period: the subscription renews at the full price', function (): void {
    $coupon = coupon();

    $this->actingAs($this->user)->postJson('/api/client/store/purchase', couponOrder())
        ->assertCreated()
        ->assertJsonPath('attributes.tokens_charged', 400)
        ->assertJsonPath('attributes.discount_tokens', 100)
        ->assertJsonPath('attributes.coupon', 'WELCOME');

    $server = Server::query()->where('owner_id', $this->user->id)->sole();
    $charge = TokenTransaction::query()->where('type', 'server_charge')->where('user_id', $this->user->id)->sole();
    $redemption = CouponRedemption::query()->sole();

    expect(couponBalance($this->user))->toBe(600)
        ->and(ServerSubscription::query()->where('server_id', $server->id)->value('price_tokens'))->toBe(500)
        ->and($charge->amount)->toBe(-400)
        ->and($charge->meta)->toMatchArray(['coupon_id' => $coupon->id, 'coupon_discount' => 100, 'full_price' => 500])
        ->and($redemption->user_id)->toBe($this->user->id)
        ->and($redemption->tokens)->toBe(100)
        ->and($redemption->server_id)->toBe($server->id)
        ->and($redemption->purchase_reference)->toBe(StorePurchaseService::reference($this->user, couponOrder()['idempotency_key']))
        ->and($coupon->refresh()->redemptions_count)->toBe(1);
});

test("percent maths is integer and rounds down in the shop's favour, and a fixed coupon always leaves one token to pay", function (): void {
    $percent = new Coupon(['type' => Coupon::TYPE_PERCENT, 'value' => 33]);
    $fixed = new Coupon(['type' => Coupon::TYPE_FIXED, 'value' => 10_000]);
    $all = new Coupon(['type' => Coupon::TYPE_PERCENT, 'value' => 100]);
    $tokens = new Coupon(['type' => Coupon::TYPE_TOKENS, 'value' => 100]);

    expect($percent->discountFor(500))->toBe(165)
        ->and($percent->discountFor(7))->toBe(2)
        ->and($percent->discountFor(1))->toBe(0)
        ->and($fixed->discountFor(500))->toBe(499)
        ->and($all->discountFor(500))->toBe(499)
        ->and($all->discountFor(1_000_000_000_000))->toBe(999_999_999_999)
        ->and($tokens->discountFor(500))->toBe(0);
});

test('a fixed coupon takes its tokens off the first period', function (): void {
    coupon(['code' => 'FIFTY', 'type' => Coupon::TYPE_FIXED, 'value' => 50]);

    $this->actingAs($this->user)->postJson('/api/client/store/purchase', couponOrder(['coupon' => 'fifty', 'expected_total' => 450]))
        ->assertCreated()
        ->assertJsonPath('attributes.tokens_charged', 450);

    expect(couponBalance($this->user))->toBe(550);
});

test('the confirmed price must be the discounted one', function (): void {
    coupon();

    $this->actingAs($this->user)->postJson('/api/client/store/purchase', couponOrder(['expected_total' => 500]))
        ->assertStatus(409)
        ->assertJsonPath('errors.0.detail', 'The price changed to 400 tokens. Please check your order and confirm again.');

    expect(couponBalance($this->user))->toBe(1000)
        ->and(CouponRedemption::query()->count())->toBe(0);
});

test('a replayed purchase returns the discounted charge and never redeems twice; a second purchase with the coupon is refused', function (): void {
    $coupon = coupon();

    $this->actingAs($this->user)->postJson('/api/client/store/purchase', couponOrder())->assertCreated();
    $this->actingAs($this->user)->postJson('/api/client/store/purchase', couponOrder())
        ->assertOk()
        ->assertJsonPath('attributes.replayed', true)
        ->assertJsonPath('attributes.tokens_charged', 400)
        ->assertJsonPath('attributes.discount_tokens', 100)
        ->assertJsonPath('attributes.coupon', 'WELCOME');

    $this->actingAs($this->user)->postJson('/api/client/store/purchase', couponOrder(['idempotency_key' => 'key-'.str_repeat('d', 20)]))
        ->assertStatus(422)
        ->assertJsonPath('errors.0.detail', 'You have already used this coupon.');

    expect($coupon->refresh()->redemptions_count)->toBe(1)
        ->and(couponBalance($this->user))->toBe(600)
        ->and(Server::query()->where('owner_id', $this->user->id)->count())->toBe(1);
});

test('the redemption limit, the validity window and the enabled switch are enforced', function (): void {
    $coupon = coupon(['max_redemptions' => 1]);
    $other = User::factory()->create();
    resolve(TokenWallet::class)->credit($other, 1000, 'admin_adjust', 'Test balance');

    $this->actingAs($other)->postJson('/api/client/store/purchase', couponOrder(['idempotency_key' => 'key-'.str_repeat('o', 20)]))->assertCreated();
    $this->actingAs($this->user)->postJson('/api/client/store/purchase', couponOrder())
        ->assertStatus(422)
        ->assertJsonPath('errors.0.detail', 'This coupon has been used up.');

    $coupon->forceFill(['max_redemptions' => null, 'valid_from' => now()->addDay()])->save();
    $this->actingAs($this->user)->postJson('/api/client/store/purchase', couponOrder())
        ->assertStatus(422)
        ->assertJsonPath('errors.0.detail', 'This coupon is not valid yet.');

    // The purchase endpoint allows three attempts a minute.
    $this->travel(61)->seconds();
    $coupon->forceFill(['valid_from' => null, 'valid_until' => now()->subMinute()])->save();
    $this->actingAs($this->user)->postJson('/api/client/store/purchase', couponOrder())
        ->assertStatus(422)
        ->assertJsonPath('errors.0.detail', 'This coupon has expired.');

    $this->travel(61)->seconds();
    $coupon->forceFill(['valid_until' => null, 'enabled' => false])->save();
    $this->actingAs($this->user)->postJson('/api/client/store/purchase', couponOrder())
        ->assertStatus(422)
        ->assertJsonPath('errors.0.detail', 'This coupon code is not valid.');

    expect(couponBalance($this->user))->toBe(1000);
});

test('a coupon limited to plans or categories only applies to those', function (): void {
    $otherPlan = ShopPlan::query()->forceCreate([...$this->plan->only(['category_id', 'description', 'visible', 'sort', 'price_tokens', 'memory', 'swap', 'disk', 'cpu', 'io', 'databases', 'backups', 'allocations']), 'name' => 'Other', 'egg_ids' => [$this->egg->id], 'node_ids' => null]);
    $coupon = coupon(['plan_ids' => [$otherPlan->id]]);

    $this->actingAs($this->user)->postJson('/api/client/store/purchase', couponOrder())
        ->assertStatus(422)
        ->assertJsonPath('errors.0.detail', 'This coupon does not apply to this plan.');

    $coupon->forceFill(['category_ids' => [$this->category->id]])->save();
    $this->actingAs($this->user)->postJson('/api/client/store/purchase', couponOrder())->assertCreated();
});

test('a new-customers coupon is refused after a purchase, but not after a purchase that failed and was refunded', function (): void {
    coupon(['new_customers_only' => true]);

    $this->creation->failure = new RuntimeException('Wings is down');
    $this->actingAs($this->user)->postJson('/api/client/store/purchase', couponOrder(['coupon' => null, 'expected_total' => 500, 'idempotency_key' => 'key-'.str_repeat('f', 20)]))->assertStatus(502);
    $this->creation->failure = null;

    expect(resolve(CouponService::class)->hasPurchased($this->user))->toBeFalse();
    $this->actingAs($this->user)->postJson('/api/client/store/purchase', couponOrder())->assertCreated();

    $buyer = User::factory()->create();
    resolve(TokenWallet::class)->credit($buyer, 900, 'purchase', 'Bought tokens on Tebex', 'tebex_payment', 'tbx-1');
    $this->actingAs($buyer)->postJson('/api/client/store/purchase', couponOrder(['idempotency_key' => 'key-'.str_repeat('b', 20)]))
        ->assertStatus(422)
        ->assertJsonPath('errors.0.detail', 'This coupon is only for new customers.');
});

test('a purchase that fails after the charge refunds the discounted amount and gives the coupon back', function (): void {
    $coupon = coupon(['max_redemptions' => 1]);

    $this->creation->failure = new RuntimeException('Wings is down');
    $this->actingAs($this->user)->postJson('/api/client/store/purchase', couponOrder())
        ->assertStatus(502)
        ->assertJsonPath('errors.0.detail', fn (string $detail): bool => str_contains($detail, 'your 400 tokens were refunded'));

    expect(couponBalance($this->user))->toBe(1000)
        ->and(CouponRedemption::query()->count())->toBe(0)
        ->and($coupon->refresh()->redemptions_count)->toBe(0);

    $this->creation->failure = null;
    $this->actingAs($this->user)->postJson('/api/client/store/purchase', couponOrder(['idempotency_key' => 'key-'.str_repeat('e', 20)]))
        ->assertCreated()
        ->assertJsonPath('attributes.tokens_charged', 400);
});

test('a short balance leaves the coupon unused', function (): void {
    $coupon = coupon();
    $poor = User::factory()->create();
    resolve(TokenWallet::class)->credit($poor, 100, 'admin_adjust', 'Test balance');

    $this->actingAs($poor)->postJson('/api/client/store/purchase', couponOrder())->assertStatus(402);

    expect($coupon->refresh()->redemptions_count)->toBe(0)
        ->and(CouponRedemption::query()->count())->toBe(0)
        ->and(couponBalance($poor))->toBe(100);
});

test('a free-tokens coupon is refused at the checkout', function (): void {
    coupon(['type' => Coupon::TYPE_TOKENS, 'value' => 100]);

    $this->actingAs($this->user)->postJson('/api/client/store/purchase', couponOrder())
        ->assertStatus(422)
        ->assertJsonPath('errors.0.detail', 'This coupon adds tokens to your wallet. Redeem it on the Wallet page.');
});

test('refused codes are counted, so codes cannot be guessed through the quote', function (): void {
    coupon();

    for ($i = 0; $i < CouponService::MAX_FAILURES; $i++) {
        $this->actingAs($this->user)->postJson('/api/client/store/quote', quoteBody(['coupon' => 'GUESS'.$i]))->assertJsonPath('coupon.valid', false);
    }

    $this->actingAs($this->user)->postJson('/api/client/store/quote', quoteBody())
        ->assertOk()
        ->assertJsonPath('coupon.valid', false)
        ->assertJsonPath('coupon.message', 'Too many coupon codes were tried. Please wait a few minutes and try again.');
});
