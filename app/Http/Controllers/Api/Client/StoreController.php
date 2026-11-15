<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Exceptions\Billing\InvalidQuoteException;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Http\Requests\Api\Client\Store\GetStoreQuoteRequest;
use Pterodactyl\Models\Billing\ShopPeriod;
use Pterodactyl\Models\Billing\ShopPlan;
use Pterodactyl\Models\Node;
use Pterodactyl\Services\Billing\CapacityService;
use Pterodactyl\Services\Billing\PricingService;
use Pterodactyl\Services\Billing\ShopSettings;
use Pterodactyl\Services\Billing\StoreCatalogService;

#[Group('Client API', 'Endpoints authenticated as a panel user using a client API token.')]
#[Subgroup('Store', 'The token shop: plans for sale and what they cost.')]
class StoreController extends ClientApiController
{
    private const array QUOTE_EXAMPLE = [
        'node_id' => 2,
        'can_provision' => true,
        'quote' => [
            'base_per_period' => 1200,
            'multiplier' => 1.5,
            'addons_per_period' => 240,
            'total_per_period' => 2040,
            'period_days' => 90,
            'lines' => [
                ['label' => 'Stone · 90 days × 1.5', 'tokens' => 1800],
                ['label' => '2 × Extra backup', 'tokens' => 240],
            ],
            'resources' => ['memory' => 2048, 'swap' => 0, 'disk' => 10240, 'io' => 500, 'cpu' => 100, 'databases' => 1, 'backups' => 3, 'allocations' => 0],
        ],
    ];

    public function __construct(
        private readonly StoreCatalogService $catalog,
        private readonly PricingService $pricing,
        private readonly CapacityService $capacity,
    ) {}

    #[Endpoint('Get the store catalog', 'Returns the visible categories with their plans cheapest first, where each plan can be placed and at what multiplier, the billing periods, the add-ons and whether each plan still fits within the capacity the store sells.')]
    #[ScribeResponse(['enabled' => true, 'subdomains_enabled' => false, 'categories' => [], 'periods' => [], 'addons' => []], description: 'Catalog returned.')]
    public function catalog(ClientApiRequest $request): JsonResponse
    {
        return new JsonResponse($this->catalog->catalog());
    }

    /**
     * @throws InvalidQuoteException
     */
    #[Endpoint('Quote a server', 'Prices a plan for a billing period on a node (or the cheapest allowed node in a location) with add-ons, and says whether the store can provision it.')]
    #[ScribeResponse(self::QUOTE_EXAMPLE, description: 'Quote returned.')]
    public function quote(GetStoreQuoteRequest $request): JsonResponse
    {
        throw_unless(ShopSettings::enabled(), InvalidQuoteException::class, 'The store is closed right now.');

        $plan = ShopPlan::query()->with('category')->find($request->integer('plan_id'));
        throw_if(! $plan instanceof ShopPlan || ! $plan->category->visible, InvalidQuoteException::class, 'This plan is not for sale.');

        $period = ShopPeriod::query()->find($request->integer('period_id'));
        throw_unless($period instanceof ShopPeriod, InvalidQuoteException::class, 'This billing period is not available.');

        $node = $request->filled('node_id')
            ? Node::query()->find($request->integer('node_id'))
            : $this->catalog->cheapestNodeIn($plan, $request->integer('location_id'));
        throw_unless($node instanceof Node, InvalidQuoteException::class, 'This plan is not available in the selected location.');

        $this->pricing->assertSellable($plan, $period, $node);
        $quote = $this->pricing->quote($plan, $period, $node, $request->addons());

        return new JsonResponse([
            'node_id' => $node->id,
            'can_provision' => $this->capacity->canProvision($quote->resources),
            'quote' => $quote->toArray(),
        ]);
    }
}
