<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\Model;
use Pterodactyl\Models\User;

/**
 * A sign-up through a referral link, and the reward it earns once the referred user's first
 * Tebex token purchase has stayed paid for the hold period. Changed only by ReferralService.
 *
 * - `registered`: signed up, no completed token purchase yet;
 * - `held`: the first purchase completed; paid out after `payout_after` unless it is refunded;
 * - `paid`: both users got `reward_tokens`;
 * - `reversed`: the first purchase was refunded, charged back or disputed;
 * - `rejected`: an administrator refused it;
 * - `capped`: the referrer had reached the monthly cap when it was due.
 *
 * A flagged referral (`flagged_at`, e.g. the same network as the referrer) is not paid until an
 * administrator approves it (`reviewed_at`).
 *
 * @property int $id
 * @property int $referrer_id
 * @property int $referred_id
 * @property string $status
 * @property string|null $network_hash
 * @property string|null $discord_hash the referred user's linked Discord account when it qualified, hashed
 * @property string|null $google_hash the referred user's linked Google account when it qualified, hashed
 * @property CarbonImmutable|null $flagged_at
 * @property string|null $flag_reason
 * @property int|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property int|null $tebex_payment_id
 * @property CarbonImmutable|null $qualified_at
 * @property CarbonImmutable|null $payout_after
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $reversed_at
 * @property int|null $reward_tokens
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property User $referrer
 * @property User $referred
 * @property User|null $reviewer
 * @property TebexPayment|null $payment
 */
#[Fillable(['referrer_id', 'referred_id', 'status', 'network_hash', 'flagged_at', 'flag_reason'])]
#[RouteKey('id')]
#[Table(name: 'referrals')]
class Referral extends Model
{
    public const string STATUS_REGISTERED = 'registered';

    public const string STATUS_HELD = 'held';

    public const string STATUS_PAID = 'paid';

    public const string STATUS_REVERSED = 'reversed';

    public const string STATUS_REJECTED = 'rejected';

    public const string STATUS_CAPPED = 'capped';

    /** @var list<string> */
    public const array STATUSES = [self::STATUS_REGISTERED, self::STATUS_HELD, self::STATUS_PAID, self::STATUS_REVERSED, self::STATUS_REJECTED, self::STATUS_CAPPED];

    /** The reference type of referral rewards in the token ledger. */
    public const string LEDGER_REFERENCE = 'referral';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'referrer_id' => 'integer',
            'referred_id' => 'integer',
            'reviewed_by' => 'integer',
            'tebex_payment_id' => 'integer',
            'reward_tokens' => 'integer',
            'flagged_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'qualified_at' => 'immutable_datetime',
            'payout_after' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
        ];
    }

    /** Whether it waits for an administrator before it can be paid. */
    public function awaitingReview(): bool
    {
        return $this->flagged_at !== null && $this->reviewed_at === null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return BelongsTo<TebexPayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(TebexPayment::class, 'tebex_payment_id');
    }

    /**
     * The administrator's view of the referral.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $user = fn (?User $user): ?array => $user instanceof User ? ['id' => $user->id, 'username' => $user->username, 'email' => $user->email] : null;

        return [
            'id' => $this->id,
            'referrer' => $user($this->referrer),
            'referred' => $user($this->referred),
            'status' => $this->status,
            'flagged' => $this->flagged_at !== null,
            'flag_reason' => $this->flag_reason,
            'awaiting_review' => $this->awaitingReview(),
            'reviewed_by' => $user($this->reviewer),
            'reviewed_at' => $this->reviewed_at?->toAtomString(),
            'tebex_transaction_id' => $this->payment?->transaction_id,
            'reward_tokens' => $this->reward_tokens,
            'qualified_at' => $this->qualified_at?->toAtomString(),
            'payout_after' => $this->payout_after?->toAtomString(),
            'paid_at' => $this->paid_at?->toAtomString(),
            'reversed_at' => $this->reversed_at?->toAtomString(),
            'created_at' => $this->created_at?->toAtomString(),
        ];
    }
}
