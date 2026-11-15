<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools\Admin;

use Pterodactyl\Contracts\Servers\TogglesServerSuspension;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Exceptions\Assistant\ToolException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolResult;

/**
 * Suspends or unsuspends a server, as the admin server page does. Registered twice, as
 * suspend_server and unsuspend_server.
 */
final class ServerSuspensionTool extends AdminTool
{
    public function __construct(
        private readonly TogglesServerSuspension $suspension,
        private readonly bool $suspend = true,
    ) {}

    public function name(): string
    {
        return $this->suspend ? 'suspend_server' : 'unsuspend_server';
    }

    public function description(): string
    {
        return $this->suspend
            ? 'Suspend a server: it is stopped and its owner can no longer use it until it is unsuspended. The admin is asked to confirm first.'
            : 'Unsuspend a suspended server so its owner can use it again. The admin is asked to confirm first.';
    }

    public function parameters(): array
    {
        return $this->schema([
            'server' => ['type' => 'string', 'description' => 'The server id, UUID or short UUID (from list_servers).'],
        ], ['server']);
    }

    public function needsConfirmation(array $arguments, ToolContext $context): bool
    {
        return true;
    }

    public function describeCall(array $arguments): string
    {
        return ($this->suspend ? 'Suspend server ' : 'Unsuspend server ').$this->labelArgument($arguments, 'server');
    }

    protected function permissions(): array
    {
        return [Permissions::AdminServersUpdate];
    }

    protected function run(array $arguments, ToolContext $context): ToolResult
    {
        $server = $this->findServer($arguments);
        if ($server->isSuspended() === $this->suspend) {
            throw new ToolException(sprintf('The server "%s" is already %s.', $server->name, $this->suspend ? 'suspended' : 'not suspended'));
        }

        $this->suspension->toggle($server, $this->suspend ? TogglesServerSuspension::ACTION_SUSPEND : TogglesServerSuspension::ACTION_UNSUSPEND);

        Activity::event($this->suspend ? 'admin:server.suspend' : 'admin:server.unsuspend')
            ->subject($server)
            ->property('name', $server->name)
            ->log();

        return new ToolResult(sprintf('%s the server "%s".', $this->suspend ? 'Suspended' : 'Unsuspended', $server->name), ['id' => $server->id, 'suspended' => $this->suspend]);
    }
}
