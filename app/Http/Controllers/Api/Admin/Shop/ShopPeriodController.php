<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Shop;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Shop\DeleteShopItemRequest;
use Pterodactyl\Http\Requests\Api\Admin\Shop\GetShopRequest;
use Pterodactyl\Http\Requests\Api\Admin\Shop\StoreShopPeriodRequest;
use Pterodactyl\Models\Billing\ShopPeriod;
use Pterodactyl\Services\Billing\ShopPresenter;

#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Shop', 'The token shop catalog: categories, plans, billing periods, price multipliers, add-ons and limits.')]
class ShopPeriodController extends AdminApiController
{
    private const array EXAMPLE = [
        'id' => 1,
        'name' => 'Quarterly',
        'days' => 90,
        'price_modifier_percent' => -10,
        'sort' => 1,
        'enabled' => true,
    ];

    #[Endpoint('List billing periods', 'Returns every billing period in the order the store shows them.')]
    #[ScribeResponse(['data' => [self::EXAMPLE]], description: 'Billing periods returned.')]
    public function index(GetShopRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => ShopPeriod::query()->ordered()->get()->map(ShopPresenter::period(...))->all()]);
    }

    #[Endpoint('Create billing period', 'Creates a billing period; a negative modifier is a discount and a positive one a surcharge.')]
    #[ScribeResponse(self::EXAMPLE, status: 201, description: 'Billing period created.')]
    public function store(StoreShopPeriodRequest $request): JsonResponse
    {
        $period = ShopPeriod::query()->create($request->periodAttributes());

        Activity::event('admin:shop.period.create')->property('name', $period->name)->log();

        return new JsonResponse(ShopPresenter::period($period), Response::HTTP_CREATED);
    }

    #[Endpoint('Update billing period', 'Updates a billing period. Servers already sold keep the period and price they were bought with.')]
    #[ScribeResponse(self::EXAMPLE, description: 'Billing period updated.')]
    public function update(StoreShopPeriodRequest $request, ShopPeriod $period): JsonResponse
    {
        $period->update($request->periodAttributes());

        Activity::event('admin:shop.period.update')->property('name', $period->name)->log();

        return new JsonResponse(ShopPresenter::period($period));
    }

    #[Endpoint('Delete billing period', 'Deletes a billing period. Servers already sold keep renewing every same number of days.')]
    #[ScribeResponse(status: 204, description: 'Billing period deleted.')]
    public function destroy(DeleteShopItemRequest $request, ShopPeriod $period): Response
    {
        $period->delete();

        Activity::event('admin:shop.period.delete')->property('name', $period->name)->log();

        return $this->returnNoContent();
    }
}
