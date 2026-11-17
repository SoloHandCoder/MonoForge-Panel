<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Assistant;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\Model;
use Pterodactyl\Models\User;

/**
 * A user's own assistant settings: the "Pause the assistant" switch. While paused, no turn
 * of the user starts or continues, so nothing is spent by accident. Users without a row are
 * not paused.
 *
 * @property int $user_id
 * @property bool $paused
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'paused'])]
#[Table(name: 'ai_user_settings', key: 'user_id', incrementing: false)]
class AiUserSetting extends Model
{
    public const string RESOURCE_NAME = 'ai_user_setting';

    protected $attributes = [
        'paused' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'int',
            'paused' => 'bool',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public static function paused(User $user): bool
    {
        return self::query()->whereKey($user->id)->where('paused', true)->exists();
    }

    public static function setPaused(User $user, bool $paused): void
    {
        self::query()->updateOrCreate(['user_id' => $user->id], ['paused' => $paused]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
