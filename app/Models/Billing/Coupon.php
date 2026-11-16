<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Pterodactyl\Models\Model;
use Pterodactyl\Models\User;

/**
 * A shop coupon. Its code is stored upper case, so codes are case-insensitive and unique.
 *
 * - `percent`: `value` % off the first period of a store purchase (1-100);
 * - `fixed`: `value` tokens off the first period of a store purchase;
 * - `tokens`: `value` tokens credited to the wallet when it is redeemed there.
 *
 * Redemptions are counted only by CouponService, under this row's lock.
 *
 * @property int $id
 * @property string $code
 * @property string $type
 * @property int $value
 * @property string|null $description
 * @property int|null $max_redemptions
 * @property int $redemptions_count
 * @property CarbonImmutable|null $valid_from
 * @property CarbonImmutable|null $valid_until
 * @property list<int>|null $plan_ids
 * @property list<int>|null $category_ids
 * @property bool $new_customers_only
 * @property bool $enabled
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['code', 'type', 'value', 'description', 'max_redemptions', 'valid_from', 'valid_until', 'plan_ids', 'category_ids', 'new_customers_only', 'enabled'])]
#[RouteKey('id')]
#[Table(name: 'coupons')]
class Coupon extends Model
{
    public const string TYPE_PERCENT = 'percent';

    public const string TYPE_FIXED = 'fixed';

    public const string TYPE_TOKENS = 'tokens';

    /** @var list<string> */
    public const array TYPES = [self::TYPE_PERCENT, self::TYPE_FIXED, self::TYPE_TOKENS];

    /** Letters, digits, hyphens and underscores; stored and compared upper case. */
    public const string CODE_PATTERN = '/^[A-Z0-9_-]{3,32}$/';

    /** The most tokens a single coupon may give (the cap on an admin token adjustment). */
    public const int MAX_TOKENS = 1_000_000;

    protected $attributes = [
        'redemptions_count' => 0,
        'new_customers_only' => false,
        'enabled' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'max_redemptions' => 'integer',
            'redemptions_count' => 'integer',
            'valid_from' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
            'plan_ids' => 'array',
            'category_ids' => 'array',
            'new_customers_only' => 'boolean',
            'enabled' => 'boolean',
            'created_by' => 'integer',
        ];
    }

    /** The trimmed, upper-case form a code is stored and looked up in. */
    public static function normalize(string $code): string
    {
        return mb_strtoupper(mb_trim($code));
    }

    /** Whether the coupon applies to a store purchase (rather than to the wallet). */
    public function isDiscount(): bool
    {
        return $this->type !== self::TYPE_TOKENS;
    }

    /** Whether the coupon may be used on this plan: no restriction, or its plan or category is listed. */
    public function allowsPlan(ShopPlan $plan): bool
    {
        $plans = array_map(intval(...), $this->plan_ids ?? []);
        $categories = array_map(intval(...), $this->category_ids ?? []);
        if ($plans === [] && $categories === []) {
            return true;
        }

        return in_array($plan->id, $plans, true) || in_array((int) $plan->category_id, $categories, true);
    }

    /**
     * The tokens this coupon takes off a first period of `$total` tokens. At least one token is
     * always charged, so a purchase always has a ledger charge to refund and replay.
     */
    public function discountFor(int $total): int
    {
        if ($total <= 1 || ! $this->isDiscount()) {
            return 0;
        }

        $discount = $this->type === self::TYPE_PERCENT
            // Integer maths, rounded down in the shop's favour.
            ? intdiv($total * min(100, max(0, $this->value)), 100)
            : $this->value;

        return max(0, min($total - 1, $discount));
    }

    /**
     * @return HasMany<CouponRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'type' => $this->type,
            'value' => $this->value,
            'description' => $this->description,
            'max_redemptions' => $this->max_redemptions,
            'redemptions_count' => $this->redemptions_count,
            'valid_from' => $this->valid_from?->toAtomString(),
            'valid_until' => $this->valid_until?->toAtomString(),
            'plan_ids' => array_map(intval(...), $this->plan_ids ?? []),
            'category_ids' => array_map(intval(...), $this->category_ids ?? []),
            'new_customers_only' => $this->new_customers_only,
            'enabled' => $this->enabled,
            'created_at' => $this->created_at?->toAtomString(),
        ];
    }
}
