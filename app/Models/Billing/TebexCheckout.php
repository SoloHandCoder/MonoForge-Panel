<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\Model;
use Pterodactyl\Models\User;

/**
 * A token pack checkout a player started on Tebex. Its UUID is in the return URL and in the
 * basket's custom data, so the payment webhook links the payment back to it and the player's
 * return page can show what Tebex confirmed, and nothing else.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int|null $token_pack_id
 * @property string|null $basket_ident
 * @property int|null $tebex_payment_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property User $user
 * @property TokenPack|null $pack
 * @property TebexPayment|null $payment
 */
#[Fillable(['uuid', 'user_id', 'token_pack_id', 'basket_ident', 'tebex_payment_id'])]
class TebexCheckout extends Model
{
    /** No payment confirmed yet. */
    public const string STATUS_PENDING = 'pending';

    /** Paid and the tokens are in the wallet. */
    public const string STATUS_COMPLETED = 'completed';

    /** Paid, but held for a team member to check (a dispute, or a payment that matched no pack). */
    public const string STATUS_REVIEW = 'review';

    /** Refunded or charged back. */
    public const string STATUS_REFUNDED = 'refunded';

    /** Tebex declined the payment. */
    public const string STATUS_DECLINED = 'declined';

    public const array STATUSES = [self::STATUS_PENDING, self::STATUS_COMPLETED, self::STATUS_REVIEW, self::STATUS_REFUNDED, self::STATUS_DECLINED];

    /** The key of the checkout's UUID in the basket's custom data. */
    public const string CUSTOM_KEY = 'checkout';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'token_pack_id' => 'integer',
            'tebex_payment_id' => 'integer',
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
     * @return BelongsTo<TokenPack, $this>
     */
    public function pack(): BelongsTo
    {
        return $this->belongsTo(TokenPack::class, 'token_pack_id');
    }

    /**
     * @return BelongsTo<TebexPayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(TebexPayment::class, 'tebex_payment_id');
    }

    /** What the player sees, from the payment Tebex reported for this checkout. */
    public function status(): string
    {
        return match ($this->payment?->status) {
            TebexPayment::STATUS_COMPLETED => self::STATUS_COMPLETED,
            TebexPayment::STATUS_REFUNDED, TebexPayment::STATUS_CHARGEBACK => self::STATUS_REFUNDED,
            TebexPayment::STATUS_DECLINED => self::STATUS_DECLINED,
            TebexPayment::STATUS_DISPUTED, TebexPayment::STATUS_UNMATCHED, TebexPayment::STATUS_UNDERPAID => self::STATUS_REVIEW,
            default => self::STATUS_PENDING,
        };
    }
}
