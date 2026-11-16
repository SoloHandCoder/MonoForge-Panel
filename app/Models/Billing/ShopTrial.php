<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\Model;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;

/**
 * A free trial server that was started from the store. The row outlives the server and the
 * account (both are set null), so the anti-abuse checks and the conversion rate keep counting it.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $server_id
 * @property int|null $plan_id
 * @property string|null $subnet_hash
 * @property string|null $signup_subnet_hash
 * @property string|null $discord_hash
 * @property string|null $google_hash
 * @property int $hours
 * @property CarbonImmutable $ends_at
 * @property CarbonImmutable|null $converted_at
 * @property CarbonImmutable|null $expired_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $user
 * @property-read Server|null $server
 * @property-read ShopPlan|null $plan
 */
#[Fillable([
    'user_id',
    'server_id',
    'plan_id',
    'subnet_hash',
    'signup_subnet_hash',
    'discord_hash',
    'google_hash',
    'hours',
    'ends_at',
    'converted_at',
    'expired_at',
])]
#[Hidden(['subnet_hash', 'signup_subnet_hash', 'discord_hash', 'google_hash'])]
#[Table(name: 'shop_trials')]
class ShopTrial extends Model
{
    public const string RESOURCE_NAME = 'shop_trial';

    public const string STATE_RUNNING = 'running';

    public const string STATE_EXPIRED = 'expired';

    public const string STATE_CONVERTED = 'converted';

    public const string STATE_DELETED = 'deleted';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'int',
            'server_id' => 'int',
            'plan_id' => 'int',
            'hours' => 'int',
            'ends_at' => 'immutable_datetime',
            'converted_at' => 'immutable_datetime',
            'expired_at' => 'immutable_datetime',
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
     * @return BelongsTo<ShopPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ShopPlan::class, 'plan_id');
    }

    /** Converted (kept and paid for), deleted, expired (ended, server suspended) or running. */
    public function state(): string
    {
        return match (true) {
            $this->converted_at !== null => self::STATE_CONVERTED,
            $this->server_id === null => self::STATE_DELETED,
            $this->expired_at !== null => self::STATE_EXPIRED,
            default => self::STATE_RUNNING,
        };
    }
}
