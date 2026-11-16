<?php

declare(strict_types=1);

namespace Pterodactyl\Models;

use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Touches;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Models\Traits\HasHashid;

/**
 * @property-read Schedule $schedule
 * @property-read Server $server
 */
#[Fillable([
    'schedule_id',
    'sequence_id',
    'action',
    'payload',
    'keep_backups',
    'time_offset',
    'is_queued',
    'continue_on_failure',
])]
#[Touches(['schedule'])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    use HasHashid;

    public const string RESOURCE_NAME = 'schedule_task';

    public const string ACTION_POWER = 'power';

    public const string ACTION_COMMAND = 'command';

    public const string ACTION_BACKUP = 'backup';

    /** Restarts the server once its game has reported nobody online for `payload` hours. */
    public const string ACTION_RESTART_IF_EMPTY = 'restart_if_empty';

    /** Deletes the server's `logs/*.log(.gz)` files older than `payload` days (Minecraft). */
    public const string ACTION_DELETE_LOGS = 'delete_logs';

    /**
     * Every action a task can perform.
     *
     * @var list<string>
     */
    public const array ACTIONS = [self::ACTION_COMMAND, self::ACTION_POWER, self::ACTION_BACKUP, self::ACTION_RESTART_IF_EMPTY, self::ACTION_DELETE_LOGS];

    /** The most hours a restart-when-empty task waits. */
    public const int MAX_EMPTY_HOURS = 72;

    /** The most days a log cleanup task keeps logs for. */
    public const int MAX_LOG_DAYS = 365;

    /**
     * The signals a power task may send.
     *
     * @var list<string>
     */
    public const array POWER_ACTIONS = ['start', 'stop', 'restart', 'kill'];

    protected $attributes = [
        'time_offset' => 0,
        'is_queued' => false,
        'continue_on_failure' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'schedule_id' => 'integer',
            'sequence_id' => 'integer',
            'keep_backups' => 'integer',
            'time_offset' => 'integer',
            'is_queued' => 'boolean',
            'continue_on_failure' => 'boolean',
        ];
    }

    /**
     * The permission a user needs to run a task with the given action and payload, or
     * null when the action or power signal is not one a task can perform.
     */
    public static function permissionForAction(string $action, ?string $payload = null): ?Permissions
    {
        return match ($action) {
            self::ACTION_COMMAND => Permissions::ControlConsole,
            self::ACTION_BACKUP => Permissions::BackupCreate,
            self::ACTION_RESTART_IF_EMPTY => Permissions::ControlRestart,
            self::ACTION_DELETE_LOGS => Permissions::FileDelete,
            self::ACTION_POWER => match (mb_trim((string) $payload)) {
                'start' => Permissions::ControlStart,
                'stop', 'kill' => Permissions::ControlStop,
                'restart' => Permissions::ControlRestart,
                default => null,
            },
            default => null,
        };
    }

    public function getRouteKeyName(): string
    {
        return $this->getKeyName();
    }

    /**
     * @return BelongsTo<Schedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    /**
     * The server a task runs against is whatever its schedule belongs to, so this
     * resolves through the schedule with local keys.
     *
     * @return HasOneThrough<Server, Schedule, $this>
     */
    public function server(): HasOneThrough
    {
        return $this->hasOneThrough(Server::class, Schedule::class, 'id', 'id', 'schedule_id', 'server_id');
    }
}
