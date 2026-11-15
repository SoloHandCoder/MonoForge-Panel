<?php

declare(strict_types=1);

namespace Pterodactyl\Contracts\Assistant;

use Pterodactyl\Exceptions\Assistant\ToolException;

/**
 * Something the assistant can do, such as reading a file or listing servers. A tool runs
 * with the requesting user's permissions and must re-check them in execute(): the model
 * decides only which tool to call, never what the user may do.
 */
interface AssistantTool
{
    /** snake_case, unique per scope. */
    public function name(): string;

    public function description(): string;

    /**
     * JSON schema of the parameters.
     *
     * @return array<string, mixed>
     */
    public function parameters(): array;

    /**
     * Whether this call must be confirmed by the user, given its arguments.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function needsConfirmation(array $arguments, ToolContext $context): bool;

    /**
     * Short human label for the UI, e.g. "Read config/server.properties".
     *
     * @param  array<string, mixed>  $arguments
     */
    public function describeCall(array $arguments): string;

    /**
     * Executes with the context user's permissions.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws ToolException with a user-safe message
     */
    public function execute(array $arguments, ToolContext $context): ToolResult;
}
