<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools\Admin;

use Illuminate\Database\Eloquent\Builder;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolResult;

final class ListServersTool extends AdminTool
{
    public const int MAX_LIMIT = 50;

    /**
     * @return array<string, mixed>
     */
    public static function summary(Server $server): array
    {
        return [
            'id' => $server->id,
            'uuid_short' => $server->uuidShort,
            'name' => $server->name,
            'owner' => $server->user->username,
            'node' => $server->node->name,
            'status' => $server->status ?? 'installed',
            'suspended' => $server->isSuspended(),
            'memory_mib' => $server->memory,
            'disk_mib' => $server->disk,
            'cpu_percent' => $server->cpu,
        ];
    }

    public function name(): string
    {
        return 'list_servers';
    }

    public function description(): string
    {
        return "Search the panel's servers by name, UUID, short UUID, owner username or email. Returns id, short UUID, name, owner, node, status and limits.";
    }

    public function parameters(): array
    {
        return $this->schema([
            'search' => ['type' => 'string', 'description' => 'Text to look for. Leave out to list the newest servers.'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'description' => 'How many servers to return (default 20).'],
        ]);
    }

    public function describeCall(array $arguments): string
    {
        $search = $this->labelArgument($arguments, 'search');

        return $search === '' ? 'List servers' : "Search servers for \"{$search}\"";
    }

    protected function permissions(): array
    {
        return [Permissions::AdminServersRead];
    }

    protected function run(array $arguments, ToolContext $context): ToolResult
    {
        $search = mb_trim($this->string($arguments, 'search', '', 191));
        $limit = $this->integer($arguments, 'limit', 20, 1, self::MAX_LIMIT);

        $query = Server::query()->without('allocation')->with(['user', 'node'])->latest('id');
        if ($search !== '') {
            $like = $this->like($search);
            $query->where(function (Builder $query) use ($like, $search): void {
                $query->where('name', 'like', $like)
                    ->orWhere('uuid', $search)
                    ->orWhere('uuidShort', $search)
                    ->orWhere('external_id', $search)
                    ->orWhereHas('user', fn (Builder $user): Builder => $user->where('username', 'like', $like)->orWhere('email', 'like', $like));
            });
        }

        $total = (clone $query)->count();
        $rows = $query->limit($limit)->get()->map(fn (Server $server): array => self::summary($server))->all();

        return new ToolResult($this->json(['total' => $total, 'servers' => $rows]), ['count' => count($rows), 'total' => $total]);
    }
}
