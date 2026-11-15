<?php

declare(strict_types=1);

namespace Pterodactyl\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\Assistant\AiConversation;

/**
 * A server that stopped without a stop, restart or kill asked for ("crashed"), or that went
 * offline again while starting ("start_failed"), as the crash doctor saw it. The server page
 * offers the AI crash doctor while the newest one is open.
 *
 * @property int $id
 * @property int $server_id
 * @property string $kind
 * @property string $source
 * @property CarbonImmutable $detected_at
 * @property string|null $console the last console lines, with secrets and addresses masked
 * @property string $status
 * @property int|null $conversation_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Server $server
 * @property-read AiConversation|null $conversation
 */
#[Fillable(['server_id', 'kind', 'source', 'detected_at', 'console', 'status', 'conversation_id'])]
#[RouteKey('id')]
#[Table(name: 'server_crashes')]
class ServerCrash extends Model
{
    use MassPrunable;

    public const string RESOURCE_NAME = 'server_crash';

    public const string KIND_CRASHED = 'crashed';

    public const string KIND_START_FAILED = 'start_failed';

    public const string SOURCE_SCHEDULER = 'scheduler';

    public const string SOURCE_CONSOLE = 'console';

    public const string STATUS_OPEN = 'open';

    public const string STATUS_DIAGNOSING = 'diagnosing';

    public const string STATUS_DISMISSED = 'dismissed';

    /** Crashes are kept this long. */
    public const int RETENTION_DAYS = 30;

    /** The banner offers the doctor for a crash this recent. */
    public const int BANNER_HOURS = 24;

    protected $attributes = [
        'status' => self::STATUS_OPEN,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'server_id' => 'int',
            'conversation_id' => 'int',
            'detected_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsTo<AiConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('detected_at', '<', CarbonImmutable::now()->subDays(self::RETENTION_DAYS));
    }
}
