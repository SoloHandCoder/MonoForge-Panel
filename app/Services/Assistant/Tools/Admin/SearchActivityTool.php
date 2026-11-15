<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools\Admin;

use Illuminate\Database\Eloquent\Builder;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Models\ActivityLog;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolResult;

/**
 * Recent activity across the panel. Entries never carry an IP: the ip column is left out,
 * as are properties that may hold addresses, and the output is redacted as well.
 */
final class SearchActivityTool extends AdminTool
{
    public const int MAX_LIMIT = 50;

    /** Property keys that hold addresses. */
    private const array ADDRESS_KEYS = ['ip', 'fqdn', 'node_ip', 'ip_alias', 'allocation'];

    public function name(): string
    {
        return 'search_activity';
    }

    public function description(): string
    {
        return 'Search the activity log, newest first. Filter by event name (such as "server:power" or "auth:fail"), by user (username or email) and by server (id, UUID or short UUID).';
    }

    public function parameters(): array
    {
        return $this->schema([
            'event' => ['type' => 'string', 'description' => 'Part of the event name.'],
            'user' => ['type' => 'string', 'description' => 'Username or email of the user who did it.'],
            'server' => ['type' => 'string', 'description' => 'The server the activity was about.'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'description' => 'How many entries to return (default 25).'],
        ]);
    }

    public function describeCall(array $arguments): string
    {
        $filters = array_filter([
            $this->labelArgument($arguments, 'event'),
            $this->labelArgument($arguments, 'user'),
            $this->labelArgument($arguments, 'server'),
        ], fn (string $filter): bool => $filter !== '');

        return $filters === [] ? 'Read recent activity' : 'Search activity for '.implode(', ', $filters);
    }

    protected function permissions(): array
    {
        return [Permissions::AdminActivityRead];
    }

    protected function run(array $arguments, ToolContext $context): ToolResult
    {
        $limit = $this->integer($arguments, 'limit', 25, 1, self::MAX_LIMIT);
        $event = mb_trim($this->string($arguments, 'event', '', 191));
        $user = mb_trim($this->string($arguments, 'user', '', 191));

        $query = ActivityLog::query()
            ->with('actor')
            ->whereNotIn('event', ActivityLog::DISABLED_EVENTS)
            ->orderByDesc('timestamp')
            ->orderByDesc('id');

        if ($event !== '') {
            $query->where('event', 'like', $this->like($event));
        }

        if ($user !== '') {
            $like = $this->like($user);
            $query->whereHasMorph('actor', [User::class], fn (Builder $actor): Builder => $actor->where('username', 'like', $like)->orWhere('email', 'like', $like));
        }

        if (($arguments['server'] ?? '') !== '') {
            $server = $this->findServer($arguments);
            $query->whereHas('subjects', fn (Builder $subject): Builder => $subject->where('subject_type', $server->getMorphClass())->where('subject_id', $server->id));
        }

        $rows = $query->limit($limit)->get()->map(fn (ActivityLog $log): array => [
            'event' => $log->event,
            'actor' => $log->actor instanceof User ? $log->actor->username : 'system',
            'via_api_key' => ($log->api_key_id ?? 0) > 0,
            'description' => $log->description,
            'properties' => array_diff_key($log->propertyValues(), array_flip(self::ADDRESS_KEYS)),
            'timestamp' => $log->timestamp->toAtomString(),
        ])->all();

        return new ToolResult($this->json(['entries' => $rows]), ['count' => count($rows)]);
    }
}
