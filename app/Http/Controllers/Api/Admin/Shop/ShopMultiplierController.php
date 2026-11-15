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
use Pterodactyl\Http\Requests\Api\Admin\Shop\StoreShopMultiplierRequest;
use Pterodactyl\Models\Billing\ShopMultiplier;
use Pterodactyl\Services\Billing\ShopPresenter;

#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Shop', 'The token shop catalog: categories, plans, billing periods, price multipliers, add-ons and limits.')]
class ShopMultiplierController extends AdminApiController
{
    private const array EXAMPLE = [
        'id' => 1,
        'scope' => 'node',
        'scope_id' => 2,
        'multiplier' => 1.5,
        'label' => 'Pro hardware',
    ];

    #[Endpoint('List price multipliers', "Returns every location and node price multiplier. A node multiplier wins over its location's.")]
    #[ScribeResponse(['data' => [self::EXAMPLE]], description: 'Multipliers returned.')]
    public function index(GetShopRequest $request): JsonResponse
    {
        $multipliers = ShopMultiplier::query()->orderBy('scope')->orderBy('scope_id')->get();

        return new JsonResponse(['data' => $multipliers->map(ShopPresenter::multiplier(...))->all()]);
    }

    #[Endpoint('Create price multiplier', 'Scales plan prices on a location or a node. Each location and node takes one multiplier.')]
    #[ScribeResponse(self::EXAMPLE, status: 201, description: 'Multiplier created.')]
    public function store(StoreShopMultiplierRequest $request): JsonResponse
    {
        $multiplier = ShopMultiplier::query()->create($request->multiplierAttributes());

        Activity::event('admin:shop.multiplier.create')
            ->property(['scope' => $multiplier->scope->value, 'multiplier' => $multiplier->value()])
            ->log();

        return new JsonResponse(ShopPresenter::multiplier($multiplier), Response::HTTP_CREATED);
    }

    #[Endpoint('Update price multiplier', 'Updates a multiplier. Servers already sold keep the price they were bought at.')]
    #[ScribeResponse(self::EXAMPLE, description: 'Multiplier updated.')]
    public function update(StoreShopMultiplierRequest $request, ShopMultiplier $multiplier): JsonResponse
    {
        $multiplier->update($request->multiplierAttributes());

        Activity::event('admin:shop.multiplier.update')
            ->property(['scope' => $multiplier->scope->value, 'multiplier' => $multiplier->value()])
            ->log();

        return new JsonResponse(ShopPresenter::multiplier($multiplier));
    }

    #[Endpoint('Delete price multiplier', 'Deletes a multiplier; the location or node goes back to 1x.')]
    #[ScribeResponse(status: 204, description: 'Multiplier deleted.')]
    public function destroy(DeleteShopItemRequest $request, ShopMultiplier $multiplier): Response
    {
        $multiplier->delete();

        Activity::event('admin:shop.multiplier.delete')
            ->property(['scope' => $multiplier->scope->value, 'multiplier' => $multiplier->value()])
            ->log();

        return $this->returnNoContent();
    }
}
