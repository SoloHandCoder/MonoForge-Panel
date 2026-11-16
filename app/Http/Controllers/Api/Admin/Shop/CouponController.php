<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Shop;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\QueryParam;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\ResponseField;
use Knuckles\Scribe\Attributes\Subgroup;
use Knuckles\Scribe\Attributes\UrlParam;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Shop\DeleteShopItemRequest;
use Pterodactyl\Http\Requests\Api\Admin\Shop\GetShopRequest;
use Pterodactyl\Http\Requests\Api\Admin\Shop\StoreCouponRequest;
use Pterodactyl\Models\Billing\Coupon;
use Pterodactyl\Models\Billing\CouponRedemption;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Shop', 'The token shop: coupons and their redemptions.')]
class CouponController extends AdminApiController
{
    public const array EXAMPLE = [
        'id' => 1,
        'code' => 'WELCOME',
        'type' => 'percent',
        'value' => 20,
        'description' => '20% off the first month for new players.',
        'max_redemptions' => 100,
        'redemptions_count' => 12,
        'valid_from' => '2026-11-16T00:00:00+00:00',
        'valid_until' => '2026-12-31T23:59:59+00:00',
        'plan_ids' => [3],
        'category_ids' => [1],
        'new_customers_only' => true,
        'enabled' => true,
        'created_at' => '2026-11-16T12:00:00+00:00',
    ];

    private const array REDEMPTIONS_EXAMPLE = [
        'data' => [[
            'id' => 1,
            'coupon' => ['id' => 1, 'code' => 'WELCOME', 'type' => 'percent'],
            'user' => ['id' => 4, 'username' => 'alex', 'email' => 'alex@example.com'],
            'tokens' => 100,
            'server' => ['id' => 7, 'uuid' => 'd3aac109-e5a0-4331-b03e-3454f7e02bbe', 'name' => 'Survival'],
            'created_at' => '2026-11-16T12:00:00+00:00',
        ]],
        'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1],
    ];

    #[Endpoint('List coupons', 'Returns the coupons, newest first, optionally filtered by a part of the code.')]
    #[QueryParam('search', 'string', 'Part of the code to look for.', required: false, example: 'WEL')]
    #[QueryParam('page', 'integer', 'Page number to return.', required: false, example: 1)]
    #[ResponseField('data[].type', 'string', 'percent (value % off the first period), fixed (value tokens off the first period) or tokens (value tokens credited to the wallet).', enum: Coupon::TYPES)]
    #[ResponseField('data[].max_redemptions', 'integer', 'The most redemptions in total; null is no limit.', nullable: true)]
    #[ResponseField('data[].valid_from', 'string', 'When the coupon starts to work; null is at once.', nullable: true)]
    #[ResponseField('data[].valid_until', 'string', 'When the coupon stops working; null is never.', nullable: true)]
    #[ResponseField('data[].description', 'string', 'A note for administrators.', nullable: true)]
    #[ScribeResponse(['data' => [self::EXAMPLE], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1]], description: 'Coupons returned.')]
    public function index(GetShopRequest $request): JsonResponse
    {
        $search = Coupon::normalize($request->string('search')->toString());
        $coupons = Coupon::query()
            ->when($search !== '', fn (Builder $query): Builder => $query->where('code', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->latest('id')
            ->paginate(50);

        return new JsonResponse([
            'data' => array_map(fn (Coupon $coupon): array => $coupon->toApiArray(), $coupons->items()),
            'meta' => ['current_page' => $coupons->currentPage(), 'last_page' => $coupons->lastPage(), 'total' => $coupons->total()],
        ]);
    }

    #[Endpoint('Create coupon', 'Creates a coupon. Codes are case-insensitive and unique. A free-tokens coupon (type tokens) can only be created by a root administrator, is redeemed once per network and never by the administrator who created it.')]
    #[ScribeResponse(self::EXAMPLE, status: 201, description: 'Coupon created.')]
    public function store(StoreCouponRequest $request): JsonResponse
    {
        $coupon = new Coupon($request->couponAttributes());
        $coupon->created_by = $request->user()->id;
        $coupon->save();

        Activity::event('admin:shop.coupon.create')->property(['code' => $coupon->code, 'type' => $coupon->type, 'value' => $coupon->value])->log();

        return new JsonResponse($coupon->toApiArray(), Response::HTTP_CREATED);
    }

    #[Endpoint('Update coupon', 'Updates a coupon. Redemptions already made keep what they gave; the type of a coupon that was already used cannot change.')]
    #[UrlParam('coupon_id', 'integer', 'The ID of the coupon.', example: 1)]
    #[ScribeResponse(self::EXAMPLE, description: 'Coupon updated.')]
    public function update(StoreCouponRequest $request, Coupon $coupon): JsonResponse
    {
        $coupon->update($request->couponAttributes());

        Activity::event('admin:shop.coupon.update')->property(['code' => $coupon->code, 'type' => $coupon->type, 'value' => $coupon->value])->log();

        return new JsonResponse($coupon->toApiArray());
    }

    #[Endpoint('Delete coupon', 'Deletes a coupon that was never used. A used coupon is kept for its audit trail: disable it instead (409).')]
    #[UrlParam('coupon_id', 'integer', 'The ID of the coupon.', example: 1)]
    #[ScribeResponse(status: 204, description: 'Coupon deleted.')]
    #[ScribeResponse(['errors' => [['code' => 'ConflictHttpException', 'status' => '409', 'detail' => 'This coupon was used, so it is kept for the audit list. Disable it instead.']]], status: 409, description: 'The coupon was used.')]
    public function destroy(DeleteShopItemRequest $request, Coupon $coupon): Response
    {
        throw_if($coupon->redemptions()->exists(), ConflictHttpException::class, 'This coupon was used, so it is kept for the audit list. Disable it instead.');

        $coupon->delete();

        Activity::event('admin:shop.coupon.delete')->property('code', $coupon->code)->log();

        return $this->returnNoContent();
    }

    #[Endpoint('List coupon redemptions', 'The audit list of coupon redemptions, newest first: who used which coupon, what it was worth and, for a store purchase, on which server.')]
    #[QueryParam('coupon_id', 'integer', 'Only the redemptions of this coupon.', required: false, example: 1)]
    #[QueryParam('page', 'integer', 'Page number to return.', required: false, example: 1)]
    #[ResponseField('data[].tokens', 'integer', 'Tokens taken off the first period, or tokens credited.')]
    #[ResponseField('data[].user', 'object', 'The user who redeemed it; null once the account is deleted.', nullable: true)]
    #[ResponseField('data[].server', 'object', 'The server the discount was for; null for a wallet credit or once the server is deleted.', nullable: true)]
    #[ScribeResponse(self::REDEMPTIONS_EXAMPLE, description: 'Redemptions returned.')]
    public function redemptions(GetShopRequest $request): JsonResponse
    {
        $redemptions = CouponRedemption::query()
            ->with(['coupon', 'user', 'server'])
            ->when($request->filled('coupon_id'), fn (Builder $query): Builder => $query->where('coupon_id', $request->integer('coupon_id')))
            ->latest('id')
            ->paginate(50);

        return new JsonResponse([
            'data' => array_map(fn (CouponRedemption $redemption): array => $redemption->toApiArray(), $redemptions->items()),
            'meta' => ['current_page' => $redemptions->currentPage(), 'last_page' => $redemptions->lastPage(), 'total' => $redemptions->total()],
        ]);
    }
}
