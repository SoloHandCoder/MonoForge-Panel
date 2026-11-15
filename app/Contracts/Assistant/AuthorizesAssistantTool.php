<?php

declare(strict_types=1);

namespace Pterodactyl\Contracts\Assistant;

use Pterodactyl\Services\Assistant\ToolContext;

/**
 * Optional for a tool: one that implements it is only offered to the model when allowed()
 * is true, so the model never sees tools the user could not use. ToolRegistry::for() also
 * re-checks it right before a call runs; execute() must still check permissions itself.
 */
interface AuthorizesAssistantTool
{
    public function allowed(ToolContext $context): bool;
}
