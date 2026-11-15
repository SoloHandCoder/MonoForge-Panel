<?php

declare(strict_types=1);

namespace Pterodactyl\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A friend request one user sent by typing a username or email. It is stored whether or not a user
 * matched, so the sender cannot tell which names exist; `recipient_id` is null when none did.
 *
 * @property int $id
 * @property int $sender_id
 * @property int|null $recipient_id
 * @property string $identifier the username or email the sender typed, lowercased
 * @property CarbonImmutable|null $declined_at hidden from the recipient once set; the sender still sees it pending
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $sender
 * @property-read User|null $recipient
 */
#[Fillable([
    'sender_id',
    'recipient_id',
    'identifier',
    'declined_at',
])]
#[RouteKey('id')]
class FriendRequest extends Model
{
    public const string RESOURCE_NAME = 'friend_request';

    /** How many requests a user may have waiting at once. */
    public const int MAX_PENDING = 25;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sender_id' => 'int',
            'recipient_id' => 'int',
            'declined_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    /**
     * The requests a recipient can answer: those matched to them and not declined.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNull('declined_at');
    }
}
