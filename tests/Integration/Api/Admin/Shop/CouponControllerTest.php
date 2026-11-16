<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Admin\Shop\CouponControllerTest;

use Pterodactyl\Models\Billing\Coupon;
use Pterodactyl\Models\Billing\CouponRedemption;
use Pterodactyl\Models\Billing\ShopCategory;
use Pterodactyl\Models\User;
use Pterodactyl\Tests\Integration\Api\Admin\AdminApiIntegrationTestCase;

uses(AdminApiIntegrationTestCase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function couponInput(array $overrides = []): array
{
    return [
        'code' => ' welcome-20 ',
        'type' => 'percent',
        'value' => 20,
        'description' => '20% off the first month',
        'max_redemptions' => 100,
        'valid_from' => '2026-11-01T00:00:00+00:00',
        'valid_until' => '2026-12-31T23:59:59+00:00',
        'new_customers_only' => true,
        'enabled' => true,
        ...$overrides,
    ];
}

test('creates, lists, updates and deletes coupons; codes are stored upper case', function (): void {
    $category = ShopCategory::query()->forceCreate(['name' => 'Minecraft', 'slug' => 'mc-'.uniqid(), 'sort' => 0, 'visible' => true]);

    $id = $this->postJson(route('api.admin.shop.coupons.store'), couponInput(['category_ids' => [$category->id]]))
        ->assertCreated()
        ->assertJsonPath('code', 'WELCOME-20')
        ->assertJsonPath('type', 'percent')
        ->assertJsonPath('value', 20)
        ->assertJsonPath('category_ids', [$category->id])
        ->assertJsonPath('plan_ids', [])
        ->assertJsonPath('redemptions_count', 0)
        ->json('id');

    expect(Coupon::query()->findOrFail($id)->created_by)->toBe($this->getAdminUser()->id);
    $this->assertActivityLogged('admin:shop.coupon.create');

    $this->getJson(route('api.admin.shop.coupons', ['search' => 'welc']))->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('meta.total', 1);
    $this->getJson(route('api.admin.shop.coupons', ['search' => 'other']))->assertOk()->assertJsonPath('meta.total', 0);

    // Unique whatever the case.
    $this->postJson(route('api.admin.shop.coupons.store'), couponInput(['code' => 'Welcome-20']))->assertUnprocessable();

    $this->putJson(route('api.admin.shop.coupons.update', $id), couponInput(['value' => 25, 'max_redemptions' => null]))
        ->assertOk()
        ->assertJsonPath('value', 25)
        ->assertJsonPath('max_redemptions', null);
    $this->assertActivityLogged('admin:shop.coupon.update');

    $this->deleteJson(route('api.admin.shop.coupons.delete', $id))->assertNoContent();
    expect(Coupon::query()->find($id))->toBeNull();
    $this->assertActivityLogged('admin:shop.coupon.delete');
});

test('refuses bad values: more than 100%, a free-tokens coupon over the cap, a bad code, an end before the start', function (): void {
    $this->postJson(route('api.admin.shop.coupons.store'), couponInput(['value' => 101]))->assertUnprocessable();
    $this->postJson(route('api.admin.shop.coupons.store'), couponInput(['type' => 'tokens', 'value' => Coupon::MAX_TOKENS + 1]))->assertUnprocessable();
    $this->postJson(route('api.admin.shop.coupons.store'), couponInput(['code' => 'no spaces']))->assertUnprocessable();
    $this->postJson(route('api.admin.shop.coupons.store'), couponInput(['code' => 'AB']))->assertUnprocessable();
    $this->postJson(route('api.admin.shop.coupons.store'), couponInput(['valid_until' => '2026-10-01T00:00:00+00:00']))->assertUnprocessable();
    $this->postJson(route('api.admin.shop.coupons.store'), couponInput(['type' => 'bogus']))->assertUnprocessable();

    // Without a start, any end is fine.
    $this->postJson(route('api.admin.shop.coupons.store'), couponInput(['valid_from' => null]))->assertCreated();
    // A free-tokens coupon carries no plan restrictions.
    $category = ShopCategory::query()->forceCreate(['name' => 'Minecraft', 'slug' => 'mc-'.uniqid(), 'sort' => 0, 'visible' => true]);
    $this->postJson(route('api.admin.shop.coupons.store'), couponInput(['code' => 'FREE', 'type' => 'tokens', 'value' => 500, 'category_ids' => [$category->id]]))
        ->assertCreated()
        ->assertJsonPath('category_ids', []);
});

test('a used coupon cannot be deleted or change its type, and its redemptions are listed', function (): void {
    $coupon = Coupon::query()->forceCreate(['code' => 'USED', 'type' => Coupon::TYPE_PERCENT, 'value' => 10, 'enabled' => true, 'redemptions_count' => 1]);
    $user = User::factory()->create();
    CouponRedemption::query()->create(['coupon_id' => $coupon->id, 'user_id' => $user->id, 'tokens' => 50]);

    $this->deleteJson(route('api.admin.shop.coupons.delete', $coupon->id))
        ->assertStatus(409)
        ->assertJsonPath('errors.0.detail', 'This coupon was used, so it is kept for the audit list. Disable it instead.');

    $this->putJson(route('api.admin.shop.coupons.update', $coupon->id), couponInput(['code' => 'USED', 'type' => 'fixed', 'value' => 10]))->assertUnprocessable();
    $this->putJson(route('api.admin.shop.coupons.update', $coupon->id), couponInput(['code' => 'USED', 'enabled' => false]))->assertOk()->assertJsonPath('enabled', false);

    $this->getJson(route('api.admin.shop.coupons.redemptions', ['coupon_id' => $coupon->id]))
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.coupon.code', 'USED')
        ->assertJsonPath('data.0.user.id', $user->id)
        ->assertJsonPath('data.0.tokens', 50)
        ->assertJsonPath('data.0.server', null);
});

test('non-admins cannot manage coupons', function (): void {
    $this->actingAsNonAdmin();

    $this->getJson(route('api.admin.shop.coupons'))->assertForbidden();
    $this->postJson(route('api.admin.shop.coupons.store'), couponInput(['type' => 'tokens', 'value' => 100]))->assertForbidden();
    expect(Coupon::query()->count())->toBe(0);
});
