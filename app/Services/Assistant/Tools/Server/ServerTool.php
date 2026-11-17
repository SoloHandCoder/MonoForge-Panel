<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools\Server;

use Illuminate\Support\Facades\App;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Exceptions\Assistant\ToolException;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\Tools\AbstractTool;
use Pterodactyl\Services\Tunnels\TunnelService;

/**
 * A tool of the server assistant. It is offered to the server's owner, root admins and
 * subusers holding one of permissions(), the same access the matching page of the UI needs.
 */
abstract class ServerTool extends AbstractTool
{
    /**
     * The subuser permissions of which the user needs at least one. An empty list means any
     * access to the server is enough. Tools that pick the permission from the arguments
     * check it again with requirePermission().
     *
     * @return list<Permissions>
     */
    abstract protected function permissions(): array;

    /**
     * Whether the user is the owner, a root admin or a subuser of the server, the same rule
     * the client API applies before any permission.
     */
    public static function canAccess(User $user, Server $server): bool
    {
        return $user->root_admin
            || $user->id === $server->owner_id
            || $server->subusers()->where('user_id', $user->id)->exists();
    }

    public function allowed(ToolContext $context): bool
    {
        $server = $context->server;
        if ($context->scope !== self::SCOPE_SERVER || ! $server instanceof Server || ! self::canAccess($context->user, $server)) {
            return false;
        }

        $permissions = $this->permissions();
        if ($permissions === []) {
            return true;
        }

        foreach ($permissions as $permission) {
            if ($context->user->can($permission->value, $server)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the tool still works while the server is suspended, installing, restoring a
     * backup or being transferred. Only reading the state does.
     */
    protected function availableWhileUnavailable(): bool
    {
        return false;
    }

    protected function refresh(ToolContext $context): ToolContext
    {
        $context = parent::refresh($context);
        $server = $context->server instanceof Server ? Server::query()->find($context->server->id) : null;
        throw_unless($server instanceof Server, ToolException::class, 'This server no longer exists.');

        return new ToolContext($context->user, $context->scope, $server, $context->conversation);
    }

    protected function guard(ToolContext $context): void
    {
        if (! $this->availableWhileUnavailable()) {
            $this->server($context)->validateCurrentState();
        }
    }

    /**
     * The server's public playit address is shown as it is.
     */
    protected function keep(ToolContext $context): array
    {
        $server = $context->server;
        $address = $server instanceof Server ? App::make(TunnelService::class)->displayAddress($server) : null;

        return $address === null ? [] : [$address];
    }

    /**
     * @throws ToolException
     */
    protected function server(ToolContext $context): Server
    {
        return $context->server ?? throw new ToolException('This tool needs a server.');
    }

    /**
     * @throws ToolException
     */
    protected function requirePermission(ToolContext $context, Permissions $permission): void
    {
        throw_unless($context->user->can($permission->value, $this->server($context)), ToolException::class, self::DENIED);
    }
}
