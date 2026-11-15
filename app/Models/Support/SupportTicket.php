<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Support;

use Carbon\CarbonImmutable;
use Database\Factories\Support\SupportTicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Pterodactyl\Enum\SupportTicketStatus;
use Pterodactyl\Models\Model;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;

/**
 * A support request from a player, with its thread of messages.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $server_id
 * @property int|null $category_id
 * @property int|null $assigned_to
 * @property string $subject
 * @property SupportTicketStatus $status
 * @property int|null $last_message_id
 * @property CarbonImmutable|null $last_message_at
 * @property bool $last_message_by_staff
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 * @property-read Server|null $server
 * @property-read SupportCategory|null $category
 * @property-read User|null $assignee
 */
#[Table('support_tickets')]
#[Fillable(['subject', 'server_id', 'category_id', 'assigned_to'])]
#[RouteKey('id')]
class SupportTicket extends Model
{
    /** @use HasFactory<SupportTicketFactory> */
    use HasFactory;

    /** A reply from the player reopens a closed ticket for this many days after it was closed. */
    public const int REOPEN_DAYS = 7;

    protected $attributes = [
        'status' => 'open',
        'last_message_by_staff' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'int',
            'server_id' => 'int',
            'category_id' => 'int',
            'assigned_to' => 'int',
            'last_message_id' => 'int',
            'status' => SupportTicketStatus::class,
            'last_message_at' => 'immutable_datetime',
            'last_message_by_staff' => 'boolean',
            'closed_at' => 'immutable_datetime',
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
     * @return BelongsTo<SupportCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(SupportCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return HasMany<SupportMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'ticket_id');
    }

    /** Whether the player may still reply: the ticket is not closed, or was closed within REOPEN_DAYS. */
    public function acceptsUserReply(): bool
    {
        if ($this->status !== SupportTicketStatus::Closed) {
            return true;
        }

        return $this->closed_at instanceof CarbonImmutable
            && $this->closed_at->greaterThan(CarbonImmutable::now()->subDays(self::REOPEN_DAYS));
    }
}
