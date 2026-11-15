<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\Model;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;

/**
 * A shop-sold server's billing: the price locked at purchase, charged in tokens every
 * `period_days`. An unpaid renewal suspends the server, and it is deleted at `delete_at`.
 *
 * @property int $id
 * @property int $server_id
 * @property int $user_id
 * @property int|null $plan_id
 * @property int|null $period_id
 * @property int $period_days
 * @property int $price_tokens
 * @property list<array{key: string, units: int}>|null $addons
 * @property string $status
 * @property CarbonImmutable $next_renewal_at
 * @property CarbonImmutable|null $suspended_at
 * @property CarbonImmutable|null $server_suspended_at when billing's suspension reached the server
 * @property CarbonImmutable|null $delete_at
 * @property bool $auto_renew
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Server $server
 * @property-read User $user
 * @property-read ShopPlan|null $plan
 * @property-read ShopPeriod|null $period
 */
#[Fillable([
    'server_id',
    'user_id',
    'plan_id',
    'period_id',
    'period_days',
    'price_tokens',
    'addons',
    'status',
    'next_renewal_at',
    'suspended_at',
    'server_suspended_at',
    'delete_at',
    'auto_renew',
])]
class ServerSubscription extends Model
{
    public const string RESOURCE_NAME = 'server_subscription';

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_SUSPENDED = 'suspended';

    public const string STATUS_CANCELLED = 'cancelled';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'auto_renew' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'server_id' => 'int',
            'user_id' => 'int',
            'plan_id' => 'int',
            'period_id' => 'int',
            'period_days' => 'int',
            'price_tokens' => 'int',
            'addons' => 'array',
            'next_renewal_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'server_suspended_at' => 'immutable_datetime',
            'delete_at' => 'immutable_datetime',
            'auto_renew' => 'bool',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ShopPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ShopPlan::class, 'plan_id');
    }

    /**
     * @return BelongsTo<ShopPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(ShopPeriod::class, 'period_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    /**
     * "Renew now" pays a suspended subscription, or an active one at most one period ahead.
     */
    public function canRenewNow(): bool
    {
        return $this->isSuspended()
            || ($this->isActive() && $this->next_renewal_at->lessThanOrEqualTo(CarbonImmutable::now()->addDays($this->period_days)));
    }

    /**
     * Identifies the period that starts at the current `next_renewal_at`, so each period is
     * charged at most once (the wallet reference of its renewal charge).
     */
    public function periodKey(): string
    {
        return $this->id.'@'.$this->next_renewal_at->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
