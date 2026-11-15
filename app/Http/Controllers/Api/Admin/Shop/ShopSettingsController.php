<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Shop;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Shop\GetShopRequest;
use Pterodactyl\Http\Requests\Api\Admin\Shop\UpdateShopSettingsRequest;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Tag;
use Pterodactyl\Services\Billing\CapacityService;
use Pterodactyl\Services\Billing\ShopSettings;

#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Shop', 'The token shop catalog: categories, plans, billing periods, price multipliers, add-ons and limits.')]
class ShopSettingsController extends AdminApiController
{
    private const array SETTINGS_EXAMPLE = [
        'enabled' => true,
        'grace_days' => 7,
        'low_balance_days' => 3,
        'limits' => ['memory' => 20480, 'disk' => 512000, 'cpu' => 1500],
        'used' => ['memory' => 8192, 'disk' => 40960, 'cpu' => 400],
        'remaining' => ['memory' => 12288, 'disk' => 471040, 'cpu' => 1100],
    ];

    private const array OPTIONS_EXAMPLE = [
        'eggs' => [['id' => 1, 'name' => 'Paper', 'tags' => [['id' => 1, 'name' => 'Minecraft', 'color' => '#22c55e']]]],
        'nodes' => [['id' => 1, 'name' => 'Home 1', 'location_id' => 1, 'public' => true, 'maintenance' => false]],
        'locations' => [['id' => 1, 'short' => 'de', 'long' => 'Germany']],
    ];

    public function __construct(private readonly CapacityService $capacity) {}

    #[Endpoint('Get shop settings', 'Returns whether the store is open, the grace period before suspended servers are deleted, and the capacity limits with what shop servers use now.')]
    #[ScribeResponse(self::SETTINGS_EXAMPLE, description: 'Shop settings returned.')]
    public function index(GetShopRequest $request): JsonResponse
    {
        return new JsonResponse($this->settings());
    }

    #[Endpoint('Update shop settings', 'Opens or closes the store and sets the grace period, the low-balance warning and the capacity limits (0 = no limit).')]
    #[ScribeResponse(self::SETTINGS_EXAMPLE, description: 'Shop settings updated.')]
    public function update(UpdateShopSettingsRequest $request): JsonResponse
    {
        ShopSettings::store($request->settings());

        Activity::event('admin:shop.settings.update')->log();

        return new JsonResponse($this->settings());
    }

    #[Endpoint('Get shop editor options', 'Returns the eggs (with their tags), nodes and locations the plan and multiplier editors pick from.')]
    #[ScribeResponse(self::OPTIONS_EXAMPLE, description: 'Options returned.')]
    public function options(GetShopRequest $request): JsonResponse
    {
        $eggs = Egg::query()->with('tags')->orderBy('name')->get()->map(fn (Egg $egg): array => [
            'id' => $egg->id,
            'name' => $egg->name,
            'tags' => $egg->tags->map(fn (Tag $tag): array => ['id' => $tag->id, 'name' => $tag->name, 'color' => $tag->color])->values()->all(),
        ]);

        $nodes = Node::query()->orderBy('name')->get()->map(fn (Node $node): array => [
            'id' => $node->id,
            'name' => $node->name,
            'location_id' => $node->location_id,
            'public' => $node->public,
            'maintenance' => $node->isUnderMaintenance(),
        ]);

        $locations = Location::query()->orderBy('short')->get()->map(fn (Location $location): array => [
            'id' => $location->id,
            'short' => $location->short,
            'long' => $location->long,
        ]);

        return new JsonResponse(['eggs' => $eggs->all(), 'nodes' => $nodes->all(), 'locations' => $locations->all()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        return [
            'enabled' => ShopSettings::enabled(),
            'grace_days' => ShopSettings::graceDays(),
            'low_balance_days' => ShopSettings::lowBalanceDays(),
            'limits' => ShopSettings::limits(),
            'used' => $this->capacity->used(),
            'remaining' => $this->capacity->remaining(),
        ];
    }
}
