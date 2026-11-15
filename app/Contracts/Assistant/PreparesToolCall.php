<?php

declare(strict_types=1);

namespace Pterodactyl\Contracts\Assistant;

use Pterodactyl\Exceptions\Assistant\ToolException;
use Pterodactyl\Services\Assistant\ToolContext;

/**
 * Optional for a tool: lets it settle its arguments before the call is recorded, so the label
 * and details the user approves are built from exactly the values that will run.
 */
interface PreparesToolCall
{
    /**
     * The arguments as they will run, such as paths with "." and ".." resolved. They are
     * stored instead of the model's arguments and passed to execute() unchanged.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     *
     * @throws ToolException when the arguments can never run; the call then fails right away
     */
    public function prepareArguments(array $arguments, ToolContext $context): array;

    /**
     * Every argument the user should see before allowing the call, in full (not shortened).
     *
     * @param  array<string, mixed>  $arguments  the prepared arguments
     * @return list<array{label: string, value: string}>
     */
    public function callDetails(array $arguments, ToolContext $context): array;
}
