<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Assistant;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Pterodactyl\Models\Model;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;

/**
 * One chat between a user and the assistant, on a server page (scope "server") or in the
 * admin area (scope "admin"). Only the user who started it can see it.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $scope
 * @property int|null $server_id
 * @property int|null $provider_id
 * @property string $title
 * @property string|null $summary
 * @property int|null $summarized_until_message_id
 * @property string $status
 * @property string|null $error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 * @property-read Server|null $server
 * @property-read AiProvider|null $provider
 */
#[Fillable([
    'uuid',
    'user_id',
    'scope',
    'server_id',
    'provider_id',
    'title',
    'summary',
    'summarized_until_message_id',
    'status',
    'error',
])]
#[RouteKey('uuid')]
#[Table(name: 'ai_conversations')]
class AiConversation extends Model
{
    public const string RESOURCE_NAME = 'ai_conversation';

    public const string SCOPE_SERVER = 'server';

    public const string SCOPE_ADMIN = 'admin';

    public const string STATUS_IDLE = 'idle';

    public const string STATUS_RUNNING = 'running';

    public const string STATUS_AWAITING_APPROVAL = 'awaiting_approval';

    public const string STATUS_ERROR = 'error';

    /** A turn still "running" after this long has crashed; the user may continue. */
    public const int STALE_AFTER_SECONDS = 180;

    protected $attributes = [
        'status' => self::STATUS_IDLE,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'int',
            'server_id' => 'int',
            'provider_id' => 'int',
            'summarized_until_message_id' => 'int',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsTo<AiProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    /**
     * @return HasMany<AiMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'conversation_id');
    }

    /**
     * @return HasMany<AiToolCall, $this>
     */
    public function toolCalls(): HasMany
    {
        return $this->hasMany(AiToolCall::class, 'conversation_id');
    }

    /** Whether a turn is in progress or waiting for the user, so a new message must wait. */
    public function isBusy(): bool
    {
        return $this->status === self::STATUS_RUNNING || $this->status === self::STATUS_AWAITING_APPROVAL;
    }

    /**
     * A turn whose job crashed leaves the conversation "running" forever; after a few minutes
     * without progress it is marked as failed so the user can continue.
     */
    public function recoverIfStale(): void
    {
        if ($this->status !== self::STATUS_RUNNING) {
            return;
        }

        $updated = $this->updated_at;
        if ($updated instanceof CarbonImmutable && $updated->diffInSeconds(CarbonImmutable::now()) < self::STALE_AFTER_SECONDS) {
            return;
        }

        $changed = self::query()
            ->whereKey($this->id)
            ->where('status', self::STATUS_RUNNING)
            ->where('updated_at', '<=', CarbonImmutable::now()->subSeconds(self::STALE_AFTER_SECONDS))
            ->update(['status' => self::STATUS_ERROR, 'error' => 'The assistant stopped responding. Send your message again to continue.', 'updated_at' => CarbonImmutable::now()]);

        if ($changed > 0) {
            $this->refresh();
        }
    }

    /**
     * Moves the conversation from one of the given states to another in one conditional
     * update, so two requests can never both start a turn.
     *
     * @param  list<string>  $from
     */
    public function transition(array $from, string $to, ?string $error = null): bool
    {
        $changed = self::query()
            ->whereKey($this->id)
            ->whereIn('status', $from)
            ->update(['status' => $to, 'error' => $error, 'updated_at' => CarbonImmutable::now()]);

        if ($changed > 0) {
            $this->refresh();

            return true;
        }

        return false;
    }
}
