<?php

declare(strict_types=1);

namespace Pterodactyl\Data;

/**
 * What a player asked to buy at the store checkout. The location or node narrows where the
 * server is deployed; with neither, any node the plan allows is used.
 */
final readonly class StorePurchaseOrder
{
    /**
     * @param  array<string, int>  $addons  add-on key => units, without the ones left at zero
     * @param  int|null  $expectedTotal  what the player confirmed paying now (the first period, after any coupon); a different amount is refused
     * @param  string|null  $couponCode  a discount coupon for the first period
     * @param  string|null  $ip  the buyer's address, recorded (hashed by network) with a coupon redemption
     */
    public function __construct(
        public int $planId,
        public int $periodId,
        public int $eggId,
        public string $name,
        public string $idempotencyKey,
        public ?int $locationId = null,
        public ?int $nodeId = null,
        public array $addons = [],
        public ?int $expectedTotal = null,
        public ?string $subdomain = null,
        public ?string $couponCode = null,
        public ?string $ip = null,
    ) {}
}
