<?php

declare(strict_types=1);

namespace Pterodactyl\Data;

use Pterodactyl\Models\Billing\Coupon;

/**
 * What a discount coupon does to the first period of a store purchase. Renewals are charged
 * at the full price.
 */
final readonly class CouponDiscount
{
    /**
     * @param  int  $discount  tokens taken off the first period
     * @param  int  $firstPeriod  what the first period costs with the coupon (never below 1)
     */
    public function __construct(
        public Coupon $coupon,
        public int $discount,
        public int $firstPeriod,
    ) {}
}
