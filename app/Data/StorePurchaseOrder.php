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
     * @param  int|null  $expectedTotal  the price per period the player confirmed; a different quote is refused
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
    ) {}
}
