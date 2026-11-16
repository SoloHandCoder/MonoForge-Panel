<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\ResponseField;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Requests\Api\Client\Account\RedeemCouponRequest;
use Pterodactyl\Models\Billing\CouponRedemption;
use Pterodactyl\Services\Billing\CouponService;
use Pterodactyl\Services\Billing\TokenWallet;

#[Group('Client API', 'Endpoints authenticated as a panel user using a client API token.')]
#[Subgroup('Account Wallet', 'The token balance and token history of the authenticated user.')]
class CouponController extends ClientApiController
{
    private const array REDEEMED_EXAMPLE = [
        'code' => 'LAUNCH',
        'tokens' => 250,
        'balance' => 1750,
    ];

    private const array REFUSED_EXAMPLE = [
        'errors' => [[
            'code' => 'CouponException',
            'status' => '422',
            'detail' => 'You have already used this coupon.',
        ]],
    ];

    public function __construct(private readonly CouponService $coupons, private readonly TokenWallet $wallet) {}

    #[Endpoint('Redeem coupon', 'Redeems a free-tokens coupon: its tokens are added to the wallet once. Discount coupons are entered at the store checkout instead. A coupon is redeemed once per user and once per network, and after 10 refused codes in 10 minutes every code is refused for a while.')]
    #[ResponseField('tokens', 'integer', 'The tokens added to the wallet.')]
    #[ResponseField('balance', 'integer', 'The token balance afterwards.')]
    #[ScribeResponse(self::REDEEMED_EXAMPLE, description: 'Coupon redeemed.')]
    #[ScribeResponse(self::REFUSED_EXAMPLE, status: 422, description: 'The coupon cannot be redeemed.')]
    public function store(RedeemCouponRequest $request): JsonResponse
    {
        $user = $request->user();
        $redemption = $this->coupons->attempt($user, fn (): CouponRedemption => $this->coupons->redeemTokens($user, $request->string('code')->toString(), $request->ip()));

        Activity::event('user:coupon.redeem')
            ->property(['code' => $redemption->coupon->code, 'tokens' => $redemption->tokens])
            ->log();

        return new JsonResponse([
            'code' => $redemption->coupon->code,
            'tokens' => $redemption->tokens,
            'balance' => $this->wallet->balance($user),
        ]);
    }
}
