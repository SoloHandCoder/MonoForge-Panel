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
use Pterodactyl\Http\Requests\Api\Admin\Shop\StoreShopPlanRequest;
use Pterodactyl\Models\Billing\ShopPlan;
use Pterodactyl\Services\Billing\ShopPresenter;

#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Shop', 'The token shop catalog: categories, plans, billing periods, price multipliers, add-ons and limits.')]
class ShopPlanController extends AdminApiController
{
    private const array EXAMPLE = [
        'id' => 1,
        'category_id' => 1,
        'name' => 'Stone',
        'description' => 'For a few friends.',
        'visible' => true,
        'sort' => 0,
        'price_tokens' => 500,
        'resources' => ['memory' => 2048, 'swap' => 0, 'disk' => 10240, 'io' => 500, 'cpu' => 100, 'databases' => 1, 'backups' => 2, 'allocations' => 0],
        'egg_ids' => [1, 2],
        'node_ids' => null,
    ];

    #[Endpoint('List shop plans', 'Returns every plan, cheapest first, as the store sorts them.')]
    #[ScribeResponse(['data' => [self::EXAMPLE]], description: 'Plans returned.')]
    public function index(GetShopRequest $request): JsonResponse
    {
        $plans = ShopPlan::query()->cheapestFirst()->get();

        return new JsonResponse(['data' => $plans->map(ShopPresenter::plan(...))->all()]);
    }

    #[Endpoint('Get shop plan', 'Returns one plan.')]
    #[ScribeResponse(self::EXAMPLE, description: 'Plan returned.')]
    public function show(GetShopRequest $request, ShopPlan $plan): JsonResponse
    {
        return new JsonResponse(ShopPresenter::plan($plan));
    }

    #[Endpoint('Create shop plan', 'Creates a plan: its price per 30 days, resources, allowed eggs and, optionally, the nodes it may be placed on.')]
    #[ScribeResponse(self::EXAMPLE, status: 201, description: 'Plan created.')]
    public function store(StoreShopPlanRequest $request): JsonResponse
    {
        $plan = ShopPlan::query()->create($request->planAttributes());

        Activity::event('admin:shop.plan.create')->property('name', $plan->name)->log();

        return new JsonResponse(ShopPresenter::plan($plan->refresh()), Response::HTTP_CREATED);
    }

    #[Endpoint('Update shop plan', 'Updates a plan. Servers already sold keep the price they were bought at.')]
    #[ScribeResponse(self::EXAMPLE, description: 'Plan updated.')]
    public function update(StoreShopPlanRequest $request, ShopPlan $plan): JsonResponse
    {
        $plan->update($request->planAttributes());

        Activity::event('admin:shop.plan.update')->property('name', $plan->name)->log();

        return new JsonResponse(ShopPresenter::plan($plan));
    }

    #[Endpoint('Delete shop plan', 'Deletes a plan. Servers already sold keep running on the price they were bought at.')]
    #[ScribeResponse(status: 204, description: 'Plan deleted.')]
    public function destroy(DeleteShopItemRequest $request, ShopPlan $plan): Response
    {
        $plan->delete();

        Activity::event('admin:shop.plan.delete')->property('name', $plan->name)->log();

        return $this->returnNoContent();
    }
}
