<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\ResponseField;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Requests\Api\Client\Store\PurchaseServerRequest;
use Pterodactyl\Http\Resources\Api\Client\StorePurchaseResource;
use Pterodactyl\Services\Billing\StorePurchaseService;
use Pterodactyl\Services\Legal\LegalSettings;

#[Group('Client API', 'Endpoints authenticated as a panel user using a client API token.')]
#[Subgroup('Store', 'Browse the server store and buy servers with tokens.')]
class StorePurchaseController extends ClientApiController
{
    public const array PURCHASE_EXAMPLE = [
        'object' => StorePurchaseResource::RESOURCE_NAME,
        'attributes' => [
            'server' => [
                'identifier' => '1a7ce997',
                'uuid' => '1a7ce997-259b-452e-8b4e-cecc464142ca',
                'name' => 'Survival',
            ],
            'tokens_charged' => 500,
            'period_days' => 30,
            'replayed' => false,
            'address' => 'example.joinmc.link',
            'discount_tokens' => 0,
            'coupon' => null,
        ],
    ];

    public function __construct(private readonly StorePurchaseService $purchases) {}

    /**
     * Buy a server with tokens.
     */
    #[Endpoint('Purchase server', 'Charges the first billing period of a store plan and creates the server, which starts installing. Repeating a request with the same idempotency key returns the server it already bought without charging again; a failure after the charge refunds it. A discount coupon lowers the first period only; expected_total is then the discounted amount (coupon.first_period_tokens of the quote), and a failed purchase gives the coupon back.')]
    #[ScribeResponse(self::PURCHASE_EXAMPLE, status: 201, description: 'Server bought and installing.')]
    #[ScribeResponse(self::PURCHASE_EXAMPLE, status: 200, description: 'The idempotency key already bought this server; nothing was charged again.')]
    #[ScribeResponse(status: 409, description: 'The user has to accept the updated Terms of Service or Privacy Policy first.')]
    #[ResponseField('attributes.address', 'string', 'The playit.gg address of the server once its tunnel is ready; null until then.', nullable: true)]
    #[ResponseField('attributes.tokens_charged', 'integer', 'What the first period cost, after the coupon. Renewals cost the full price.')]
    #[ResponseField('attributes.discount_tokens', 'integer', 'Tokens the coupon took off the first period; 0 without one.')]
    #[ResponseField('attributes.coupon', 'string', 'The coupon code used, or null.', nullable: true)]
    public function store(PurchaseServerRequest $request): JsonResponse
    {
        // Buying accepts the Terms of Service, so only the version the user accepted counts.
        LegalSettings::ensureAccepted($request->user());

        $result = $this->purchases->purchase($request->user(), $request->order());

        if (! $result->replayed) {
            Activity::event('user:store.purchase')
                ->subject($result->server)
                ->property('name', $result->server->name)
                ->property('tokens', $result->tokensCharged)
                ->property('coupon', $result->couponCode ?? '')
                ->log();
        }

        return StorePurchaseResource::make([
            'server' => $result->server,
            'tokens_charged' => $result->tokensCharged,
            'period_days' => $result->periodDays,
            'replayed' => $result->replayed,
            'address' => $result->address,
            'discount_tokens' => $result->discountTokens,
            'coupon' => $result->couponCode,
        ])->respond($result->replayed ? Response::HTTP_OK : Response::HTTP_CREATED);
    }
}
