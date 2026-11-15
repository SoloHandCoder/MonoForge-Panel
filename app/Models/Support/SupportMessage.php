<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Support;

use Carbon\CarbonImmutable;
use Database\Factories\Support\SupportMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Pterodactyl\Models\Model;
use Pterodactyl\Models\User;

/**
 * A reply on a ticket, or a staff-only internal note (`is_internal`), which players never see.
 *
 * @property int $id
 * @property int $ticket_id
 * @property int|null $user_id
 * @property bool $is_staff
 * @property bool $is_internal
 * @property string $body
 * @property int $expired_attachments the images removed once the ticket had been closed long enough
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read SupportTicket $ticket
 * @property-read User|null $user
 * @property-read Collection<int, SupportAttachment> $attachments
 */
#[Table('support_messages')]
#[Fillable(['user_id', 'is_staff', 'is_internal', 'body'])]
#[RouteKey('id')]
class SupportMessage extends Model
{
    /** @use HasFactory<SupportMessageFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = ['expired_attachments' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ticket_id' => 'int',
            'user_id' => 'int',
            'is_staff' => 'boolean',
            'is_internal' => 'boolean',
            'expired_attachments' => 'int',
        ];
    }

    /**
     * @return BelongsTo<SupportTicket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<SupportAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(SupportAttachment::class, 'message_id')->orderBy('id');
    }

    /**
     * The messages a player may see: everything but internal notes.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function visibleToUser(Builder $query): void
    {
        $query->where('is_internal', false);
    }
}
