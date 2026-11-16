<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\ResponseField;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Data\CouponDiscount;
use Pterodactyl\Exceptions\Billing\CouponException;
use Pterodactyl\Exceptions\Billing\InvalidQuoteException;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Http\Requests\Api\Client\Store\GetStoreQuoteRequest;
use Pterodactyl\Models\Billing\Coupon;
use Pterodactyl\Models\Billing\ShopPeriod;
use Pterodactyl\Models\Billing\ShopPlan;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Billing\CapacityService;
use Pterodactyl\Services\Billing\CouponService;
use Pterodactyl\Services\Billing\PricingService;
use Pterodactyl\Services\Billing\Quote;
use Pterodactyl\Services\Billing\ShopSettings;
use Pterodactyl\Services\Billing\StoreCatalogService;
use Pterodactyl\Services\Billing\TokenWallet;

#[Group('Client API', 'Endpoints authenticated as a panel user using a client API token.')]
#[Subgroup('Store', 'The token shop: plans for sale and what they cost.')]
class StoreController extends ClientApiController
{
    private const array CATALOG_EXAMPLE = [
        'enabled' => true,
        'subdomains_enabled' => true,
        'subdomain_base' => 'monoforge.net',
        'balance' => 1500,
        'categories' => [[
            'id' => 1,
            'name' => 'Minecraft',
            'slug' => 'minecraft',
            'description' => 'Java edition servers.',
            'sort' => 0,
            'visible' => true,
            'plans' => [[
                'id' => 3,
                'name' => 'Stone',
                'description' => 'A small survival server.',
                'price_tokens' => 400,
                'from_price_tokens' => 400,
                'resources' => ['memory' => 2048, 'swap' => 0, 'disk' => 10240, 'io' => 500, 'cpu' => 100, 'databases' => 1, 'backups' => 1, 'allocations' => 0],
                'can_provision' => true,
                'eggs' => [['id' => 1, 'name' => 'Paper', 'description' => 'High performance Spigot fork.', 'image' => null, 'tags' => [['name' => 'Minecraft', 'color' => '#22c55e']]]],
                'locations' => [['id' => 1, 'short' => 'de-fra', 'long' => 'Frankfurt', 'multiplier' => 1.0, 'can_provision' => true, 'nodes' => [['id' => 2, 'name' => 'Frankfurt 1', 'multiplier' => 1.0, 'can_provision' => true]]]],
            ]],
        ]],
        'periods' => [['id' => 1, 'name' => 'Monthly', 'days' => 30, 'price_modifier_percent' => 0]],
        'addons' => [['key' => 'extra-backup', 'name' => 'Extra backup', 'description' => 'One more backup slot.', 'resource' => 'backups', 'amount' => 1, 'price_tokens' => 50, 'max_units' => 5]],
    ];

    private const array QUOTE_EXAMPLE = [
        'node_id' => 2,
        'can_provision' => true,
        'balance' => 1500,
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
        'coupon' => [
            'code' => 'WELCOME',
            'valid' => true,
            'message' => null,
            'type' => 'percent',
            'discount_tokens' => 204,
            'first_period_tokens' => 1836,
        ],
    ];

    public function __construct(
        private readonly StoreCatalogService $catalog,
        private readonly PricingService $pricing,
        private readonly CapacityService $capacity,
        private readonly TokenWallet $wallet,
        private readonly CouponService $coupons,
    ) {}

    #[Endpoint('Get the store catalog', 'Returns the visible categories with their plans cheapest first, where each plan can be placed and at what multiplier, the billing periods, the add-ons and whether each plan still fits within the capacity the store sells. A node or location at its own shop limits stays listed with can_provision false.')]
    #[ResponseField('subdomains_enabled', 'boolean', 'Whether checkout offers a custom subdomain (Tunnels settings).')]
    #[ResponseField('subdomain_base', 'string', 'The domain a custom subdomain is created under, or null when subdomains are off.', required: false)]
    #[ResponseField('balance', 'integer', 'The token balance of the requesting user.')]
    #[ScribeResponse(self::CATALOG_EXAMPLE, description: 'Catalog returned.')]
    public function catalog(ClientApiRequest $request): JsonResponse
    {
        return new JsonResponse([...$this->catalog->catalog(), 'balance' => $this->wallet->balance($request->user())]);
    }

    /**
     * @throws InvalidQuoteException
     */
    #[Endpoint('Quote a server', 'Prices a plan for a billing period on a node (or the cheapest allowed node in a location) with add-ons, and says whether the store can provision it within the shop-wide limits and within the limits of the node itself. In a location, the cheapest node with room is quoted. With a coupon, coupon says what it takes off the first period (renewals are charged at the full total_per_period) or why it cannot be used; the coupon is not redeemed until the purchase. After 10 refused codes in 10 minutes every code is refused for a while.')]
    #[ResponseField('balance', 'integer', 'The token balance of the requesting user.')]
    #[ResponseField('coupon', 'object', 'The coupon that was sent, checked but not redeemed; null without one.', nullable: true)]
    #[ResponseField('coupon.code', 'string', 'The coupon code, upper case.')]
    #[ResponseField('coupon.valid', 'boolean', 'Whether the coupon can be used on this order.')]
    #[ResponseField('coupon.message', 'string', 'Why the coupon cannot be used; null when it can.', nullable: true)]
    #[ResponseField('coupon.type', 'string', 'percent or fixed; null when the coupon cannot be used.', enum: ['percent', 'fixed'], nullable: true)]
    #[ResponseField('coupon.discount_tokens', 'integer', 'Tokens taken off the first period.')]
    #[ResponseField('coupon.first_period_tokens', 'integer', 'What the first period costs with the coupon: the expected_total to send with the purchase.')]
    #[ScribeResponse(self::QUOTE_EXAMPLE, description: 'Quote returned.')]
    public function quote(GetStoreQuoteRequest $request): JsonResponse
    {
        throw_unless(ShopSettings::enabled(), InvalidQuoteException::class, 'The store is closed right now.');

        $plan = ShopPlan::query()->with('category')->find($request->integer('plan_id'));
        throw_if(! $plan instanceof ShopPlan || ! $plan->category->visible, InvalidQuoteException::class, 'This plan is not for sale.');

        $period = ShopPeriod::query()->find($request->integer('period_id'));
        throw_unless($period instanceof ShopPeriod, InvalidQuoteException::class, 'This billing period is not available.');

        $node = $request->filled('node_id')
            ? Node::query()->where('public', true)->where('shop_enabled', true)->find($request->integer('node_id'))
            : $this->catalog->cheapestNodeIn($plan, $request->integer('location_id'));
        throw_unless($node instanceof Node, InvalidQuoteException::class, 'This plan is not available in the selected location.');

        $this->pricing->assertSellable($plan, $period, $node);
        $quote = $this->pricing->quote($plan, $period, $node, $request->addons());

        // In a location, prefer the cheapest node that still has room within its own shop limits.
        if (! $request->filled('node_id')) {
            $roomy = $this->catalog->cheapestNodeIn($plan, $request->integer('location_id'), $quote->resources);
            if ($roomy instanceof Node && $roomy->id !== $node->id) {
                $node = $roomy;
                $quote = $this->pricing->quote($plan, $period, $node, $request->addons());
            }
        }

        return new JsonResponse([
            'node_id' => $node->id,
            'can_provision' => $this->capacity->canProvision($quote->resources) && $this->capacity->canProvisionOn($node, $quote->resources),
            'balance' => $this->wallet->balance($request->user()),
            'quote' => $quote->toArray(),
            'coupon' => $this->couponFor($request->user(), $request->coupon(), $plan, $quote),
        ]);
    }

    /**
     * What the coupon would take off the first period, or why it cannot be used.
     *
     * @return array{code: string, valid: bool, message: string|null, type: string|null, discount_tokens: int, first_period_tokens: int}|null
     */
    private function couponFor(User $user, ?string $code, ShopPlan $plan, Quote $quote): ?array
    {
        if ($code === null) {
            return null;
        }

        try {
            $discount = $this->coupons->attempt($user, fn (): CouponDiscount => $this->coupons->preview($user, $code, $plan, $quote->totalPerPeriod));
        } catch (CouponException $couponException) {
            return [
                'code' => mb_substr(Coupon::normalize($code), 0, 32),
                'valid' => false,
                'message' => $couponException->getMessage(),
                'type' => null,
                'discount_tokens' => 0,
                'first_period_tokens' => $quote->totalPerPeriod,
            ];
        }

        return [
            'code' => $discount->coupon->code,
            'valid' => true,
            'message' => null,
            'type' => $discount->coupon->type,
            'discount_tokens' => $discount->discount,
            'first_period_tokens' => $discount->firstPeriod,
        ];
    }
}
