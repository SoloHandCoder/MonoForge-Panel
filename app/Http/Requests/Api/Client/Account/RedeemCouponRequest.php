<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Account;

use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class RedeemCouponRequest extends ClientApiRequest
{
    /**
     * The coupon code, in any case; CouponService checks it.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32'],
        ];
    }
}
