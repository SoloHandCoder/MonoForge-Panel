<?php

declare(strict_types=1);

namespace Pterodactyl\Contracts\Assistant;

use Pterodactyl\Services\Assistant\ToolContext;

/**
 * Optional for a tool that needs confirmation: calls for which alwaysAsks() is true wait for
 * an explicit click even in "Allow everything" or after "Allow until my next message" (such
 * as deleting many files, the world folder, changing token balances or suspending servers).
 * When it throws, the call always asks.
 */
interface AlwaysAsks
{
    /**
     * @param  array<string, mixed>  $arguments  the prepared arguments
     */
    public function alwaysAsks(array $arguments, ToolContext $context): bool;
}
