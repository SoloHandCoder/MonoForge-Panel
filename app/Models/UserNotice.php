<?php

declare(strict_types=1);

namespace Pterodactyl\Models;

use Carbon\CarbonImmutable;
use Database\Factories\UserNoticeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Enum\NoticeLevel;

/**
 * A message for one user, such as a low balance warning, shown until they mark it read.
 *
 * @property int $id
 * @property int $user_id
 * @property NoticeLevel $level
 * @property string $title
 * @property string $body
 * @property string|null $action_label
 * @property string|null $action_url
 * @property string|null $kind
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'level',
    'title',
    'body',
    'action_label',
    'action_url',
    'kind',
    'read_at',
])]
#[RouteKey('id')]
class UserNotice extends Model
{
    /** @use HasFactory<UserNoticeFactory> */
    use HasFactory;

    public const string RESOURCE_NAME = 'user_notice';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'int',
            'level' => NoticeLevel::class,
            'read_at' => 'immutable_datetime',
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
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function unread(Builder $query): void
    {
        $query->whereNull('read_at');
    }
}
