<?php

declare(strict_types=1);

namespace Pterodactyl\Data;

use Pterodactyl\Models\Server;

/**
 * A completed store purchase. `tokensCharged` is what the first period cost, after the
 * coupon's `discountTokens` when one was used. A replay of an idempotency key that already
 * bought a server returns that server with `replayed` set and nothing charged again.
 * `warning` says when the purchase stands but a step after it (such as linking the coupon to
 * the server) failed and setup continues.
 */
final readonly class StorePurchaseResult
{
    public function __construct(
        public Server $server,
        public int $tokensCharged,
        public int $periodDays,
        public bool $replayed = false,
        public ?string $address = null,
        public int $discountTokens = 0,
        public ?string $couponCode = null,
        public ?string $warning = null,
    ) {}
}
