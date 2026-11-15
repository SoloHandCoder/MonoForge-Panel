<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools\Server;

use Pterodactyl\Contracts\Servers\SendsServerPower;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Exceptions\Assistant\ToolException;
use Pterodactyl\Models\Task;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolResult;

/**
 * Starts, stops, restarts or kills the server, needing the matching control permission
 * (kill counts as stop, as in the UI).
 */
final class PowerTool extends ServerTool
{
    public function __construct(private readonly SendsServerPower $power) {}

    public function name(): string
    {
        return 'power';
    }

    public function description(): string
    {
        return 'Start, stop, restart or kill the server. Kill ends the process at once and can lose unsaved data, so prefer stop. The user is asked to confirm first.';
    }

    public function parameters(): array
    {
        return $this->schema([
            'signal' => ['type' => 'string', 'enum' => Task::POWER_ACTIONS, 'description' => 'The power action.'],
        ], ['signal']);
    }

    public function needsConfirmation(array $arguments, ToolContext $context): bool
    {
        return true;
    }

    public function describeCall(array $arguments): string
    {
        return match ($arguments['signal'] ?? null) {
            'start' => 'Start the server',
            'stop' => 'Stop the server',
            'restart' => 'Restart the server',
            'kill' => 'Kill the server',
            default => 'Change the power state',
        };
    }

    protected function permissions(): array
    {
        return [Permissions::ControlStart, Permissions::ControlStop, Permissions::ControlRestart];
    }

    protected function run(array $arguments, ToolContext $context): ToolResult
    {
        $signal = mb_strtolower(mb_trim($this->string($arguments, 'signal', null, 16)));
        $permission = in_array($signal, Task::POWER_ACTIONS, true) ? Task::permissionForAction(Task::ACTION_POWER, $signal) : null;
        if (! $permission instanceof Permissions) {
            throw new ToolException('The signal must be one of: '.implode(', ', Task::POWER_ACTIONS).'.');
        }

        $this->requirePermission($context, $permission);
        $this->power->send($this->server($context), $signal);

        return new ToolResult("Sent the {$signal} signal. Check server_status in a moment to see the new state.", ['signal' => $signal]);
    }
}
