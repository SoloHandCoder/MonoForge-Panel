<?php

declare(strict_types=1);

namespace Pterodactyl\Data;

/**
 * A free trial server a player asked for in the store: a trialable plan as it is (no period,
 * add-ons or subdomain), where to put it and its name.
 */
final readonly class StoreTrialOrder
{
    public function __construct(
        public int $planId,
        public int $eggId,
        public string $name,
        public string $idempotencyKey,
        public ?int $locationId = null,
        public ?int $nodeId = null,
    ) {}
}
