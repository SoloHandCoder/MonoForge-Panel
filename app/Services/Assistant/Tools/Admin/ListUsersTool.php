<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools\Admin;

use Illuminate\Database\Eloquent\Builder;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolResult;

/**
 * Users by search. Passwords, two-factor secrets and addresses are never part of the output.
 */
final class ListUsersTool extends AdminTool
{
    public const int MAX_LIMIT = 50;

    public function name(): string
    {
        return 'list_users';
    }

    public function description(): string
    {
        return "Search the panel's users by username, email or name. Returns id, username, email, name, whether they are an admin, whether 2FA is on, their token balance and how many servers they own.";
    }

    public function parameters(): array
    {
        return $this->schema([
            'search' => ['type' => 'string', 'description' => 'Text to look for. Leave out to list the newest users.'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'description' => 'How many users to return (default 20).'],
        ]);
    }

    public function describeCall(array $arguments): string
    {
        $search = $this->labelArgument($arguments, 'search');

        return $search === '' ? 'List users' : "Search users for \"{$search}\"";
    }

    protected function permissions(): array
    {
        return [Permissions::AdminUsersRead];
    }

    protected function run(array $arguments, ToolContext $context): ToolResult
    {
        $search = mb_trim($this->string($arguments, 'search', '', 191));
        $limit = $this->integer($arguments, 'limit', 20, 1, self::MAX_LIMIT);

        $query = User::query()->withCount('servers')->latest('id');
        if ($search !== '') {
            $like = $this->like($search);
            $query->where(function (Builder $query) use ($like): void {
                $query->where('username', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('name_first', 'like', $like)
                    ->orWhere('name_last', 'like', $like);
            });
        }

        $total = (clone $query)->count();
        $rows = $query->limit($limit)->get()->map(fn (User $user): array => [
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'name' => mb_trim($user->name_first.' '.$user->name_last),
            'root_admin' => $user->root_admin,
            'two_factor' => $user->use_totp,
            'token_balance' => $user->token_balance,
            'servers_owned' => $user->servers_count,
            'created_at' => $user->created_at?->toAtomString(),
        ])->all();

        return new ToolResult($this->json(['total' => $total, 'users' => $rows]), ['count' => count($rows), 'total' => $total]);
    }
}
