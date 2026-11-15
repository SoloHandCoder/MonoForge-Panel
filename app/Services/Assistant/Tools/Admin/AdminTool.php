<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools\Admin;

use Illuminate\Database\Eloquent\Builder;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Exceptions\Assistant\ToolException;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\Tools\AbstractTool;

/**
 * A tool of the admin assistant, offered to root administrators holding every admin
 * permission it lists, the same permissions the matching admin API endpoint needs.
 */
abstract class AdminTool extends AbstractTool
{
    /**
     * @return list<Permissions>
     */
    abstract protected function permissions(): array;

    public function allowed(ToolContext $context): bool
    {
        if ($context->scope !== self::SCOPE_ADMIN || ! $context->user->root_admin) {
            return false;
        }

        foreach ($this->permissions() as $permission) {
            if (! $context->user->can($permission->value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A server by id, UUID or short UUID.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws ToolException
     */
    protected function findServer(array $arguments, string $key = 'server'): Server
    {
        $value = mb_trim($this->string($arguments, $key, null, 191));

        $server = Server::query()
            ->where(function (Builder $query) use ($value): void {
                $query->where('uuid', $value)->orWhere('uuidShort', $value);
                if (ctype_digit($value)) {
                    $query->orWhere('id', (int) $value);
                }
            })
            ->first();

        return $server ?? throw new ToolException("No server matches \"{$value}\".");
    }

    /**
     * A user by id, username or email.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws ToolException
     */
    protected function findUser(array $arguments, string $key = 'user'): User
    {
        $value = mb_trim($this->string($arguments, $key, null, 191));

        $user = User::query()
            ->where(function (Builder $query) use ($value): void {
                $query->where('username', mb_strtolower($value))->orWhere('email', $value);
                if (ctype_digit($value)) {
                    $query->orWhere('id', (int) $value);
                }
            })
            ->first();

        return $user ?? throw new ToolException("No user matches \"{$value}\".");
    }

    /**
     * Escape the LIKE wildcards of a search term.
     */
    protected function like(string $term): string
    {
        return '%'.addcslashes($term, '%_\\').'%';
    }
}
