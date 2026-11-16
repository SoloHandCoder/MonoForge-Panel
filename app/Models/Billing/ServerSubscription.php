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
 * @property bool $is_trial a free trial nobody has paid for yet (running, or ended and suspended)
 * @property CarbonImmutable $next_renewal_at
 * @property CarbonImmutable|null $suspended_at
 * @property CarbonImmutable|null $server_suspended_at when billing's suspension reached the server
 * @property bool $suspended_by_billing whether billing's suspension changed the server (it was not suspended already)
 * @property CarbonImmutable|null $delete_at
 * @property bool $auto_renew
 * @property bool $auto_sleep the owner's auto-sleep switch (the plan must allow it as well)
 * @property int $idle_checks consecutive checks that found nobody online
 * @property CarbonImmutable|null $sleep_started_at set while auto-sleep keeps the server stopped
 * @property int $last_sleep_saved_tokens what the last renewal saved thanks to sleeping
 * @property int|null $terms_version the Terms of Service version in force when the server was bought
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
    'is_trial',
    'next_renewal_at',
    'suspended_at',
    'server_suspended_at',
    'suspended_by_billing',
    'delete_at',
    'auto_renew',
    'auto_sleep',
    'idle_checks',
    'sleep_started_at',
    'last_sleep_saved_tokens',
    'terms_version',
])]
class ServerSubscription extends Model
{
    public const string RESOURCE_NAME = 'server_subscription';

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_SUSPENDED = 'suspended';

    public const string STATUS_CANCELLED = 'cancelled';

    /** A free trial that is running; `next_renewal_at` is the end of the trial. */
    public const string STATUS_TRIAL = 'trial';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'is_trial' => false,
        'auto_renew' => true,
        'auto_sleep' => false,
        'idle_checks' => 0,
        'last_sleep_saved_tokens' => 0,
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
            'terms_version' => 'int',
            'addons' => 'array',
            'is_trial' => 'bool',
            'next_renewal_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'server_suspended_at' => 'immutable_datetime',
            'suspended_by_billing' => 'bool',
            'delete_at' => 'immutable_datetime',
            'auto_renew' => 'bool',
            'auto_sleep' => 'bool',
            'idle_checks' => 'int',
            'sleep_started_at' => 'immutable_datetime',
            'last_sleep_saved_tokens' => 'int',
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

    /** A free trial that is still running. */
    public function isTrial(): bool
    {
        return $this->status === self::STATUS_TRIAL;
    }

    /** Whether auto-sleep stopped the server and nobody has started it since. */
    public function isSleeping(): bool
    {
        return $this->isActive() && $this->sleep_started_at !== null;
    }

    /** The start of the period that ends at `next_renewal_at` (the one its renewal bills). */
    public function periodStart(): CarbonImmutable
    {
        return $this->next_renewal_at->subDays(max(1, $this->period_days));
    }

    /**
     * "Renew now" pays a suspended subscription, or an active one at most one period ahead.
     */
    public function canRenewNow(): bool
    {
        // A trial has no price yet: the player keeps it by picking a period ("Keep this server").
        if ($this->is_trial) {
            return false;
        }

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
