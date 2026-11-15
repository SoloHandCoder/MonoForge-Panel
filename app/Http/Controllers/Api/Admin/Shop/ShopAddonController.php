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
use Pterodactyl\Http\Requests\Api\Admin\Shop\StoreShopAddonRequest;
use Pterodactyl\Models\Billing\ShopAddon;
use Pterodactyl\Services\Billing\ShopPresenter;

#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Shop', 'The token shop catalog: categories, plans, billing periods, price multipliers, add-ons and limits.')]
class ShopAddonController extends AdminApiController
{
    private const array EXAMPLE = [
        'id' => 1,
        'key' => 'extra-backup',
        'name' => 'Extra backup',
        'description' => 'One more backup slot.',
        'resource' => 'backups',
        'amount' => 1,
        'price_tokens' => 50,
        'max_units' => 5,
        'enabled' => true,
    ];

    #[Endpoint('List shop add-ons', 'Returns every add-on.')]
    #[ScribeResponse(['data' => [self::EXAMPLE]], description: 'Add-ons returned.')]
    public function index(GetShopRequest $request): JsonResponse
    {
        $addons = ShopAddon::query()->orderBy('name')->orderBy('id')->get();

        return new JsonResponse(['data' => $addons->map(ShopPresenter::addon(...))->all()]);
    }

    #[Endpoint('Create shop add-on', 'Creates an add-on, priced per unit per 30 days.')]
    #[ScribeResponse(self::EXAMPLE, status: 201, description: 'Add-on created.')]
    public function store(StoreShopAddonRequest $request): JsonResponse
    {
        $addon = ShopAddon::query()->create($request->addonAttributes());

        Activity::event('admin:shop.addon.create')->property('name', $addon->name)->log();

        return new JsonResponse(ShopPresenter::addon($addon), Response::HTTP_CREATED);
    }

    #[Endpoint('Update shop add-on', 'Updates an add-on. Servers already sold keep the price they were bought at.')]
    #[ScribeResponse(self::EXAMPLE, description: 'Add-on updated.')]
    public function update(StoreShopAddonRequest $request, ShopAddon $addon): JsonResponse
    {
        $addon->update($request->addonAttributes());

        Activity::event('admin:shop.addon.update')->property('name', $addon->name)->log();

        return new JsonResponse(ShopPresenter::addon($addon));
    }

    #[Endpoint('Delete shop add-on', 'Deletes an add-on. Servers that bought it keep it.')]
    #[ScribeResponse(status: 204, description: 'Add-on deleted.')]
    public function destroy(DeleteShopItemRequest $request, ShopAddon $addon): Response
    {
        $addon->delete();

        Activity::event('admin:shop.addon.delete')->property('name', $addon->name)->log();

        return $this->returnNoContent();
    }
}
