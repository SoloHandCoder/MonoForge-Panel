<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Assistant;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\Model;
use Pterodactyl\Models\User;

/**
 * The AI tokens one model call used, how many of them came out of the user's free monthly
 * allowance and how many panel tokens the rest cost.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $conversation_id
 * @property string $scope
 * @property string $period YYYY-MM
 * @property int $ai_tokens
 * @property int $free_tokens
 * @property int $panel_tokens_charged
 * @property CarbonImmutable|null $created_at
 */
#[Fillable([
    'user_id',
    'conversation_id',
    'scope',
    'period',
    'ai_tokens',
    'free_tokens',
    'panel_tokens_charged',
])]
#[RouteKey('id')]
#[Table(name: 'ai_usage')]
class AiUsage extends Model
{
    public const string RESOURCE_NAME = 'ai_usage';

    public const null UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'int',
            'conversation_id' => 'int',
            'ai_tokens' => 'int',
            'free_tokens' => 'int',
            'panel_tokens_charged' => 'int',
            'created_at' => 'immutable_datetime',
        ];
    }

    public static function currentPeriod(): string
    {
        return CarbonImmutable::now()->format('Y-m');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
