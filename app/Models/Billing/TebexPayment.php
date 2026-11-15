<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\Model;
use Pterodactyl\Models\User;

/**
 * One Tebex transaction and every webhook received for it. The transaction ID is unique, so a
 * payment is credited once however often Tebex sends it.
 *
 * @property int $id
 * @property string $transaction_id
 * @property int|null $user_id
 * @property string $status
 * @property int $tokens
 * @property int $tokens_reversed
 * @property string $amount
 * @property string $currency
 * @property list<array<array-key, mixed>> $raw
 * @property CarbonImmutable|null $raw_pruned_at when the webhook bodies were cut down to id, type and date
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $flagged_at
 * @property string|null $flag_reason why the payment was not credited (underpaid or unmatched)
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property User|null $user
 */
#[Fillable(['transaction_id', 'user_id', 'status', 'tokens', 'tokens_reversed', 'amount', 'currency', 'raw', 'raw_pruned_at', 'processed_at', 'flagged_at', 'flag_reason'])]
#[RouteKey('id')]
class TebexPayment extends Model
{
    /** Recorded, but no webhook has settled it yet. */
    public const string STATUS_PENDING = 'pending';

    public const string STATUS_COMPLETED = 'completed';

    public const string STATUS_REFUNDED = 'refunded';

    public const string STATUS_CHARGEBACK = 'chargeback';

    public const string STATUS_DISPUTED = 'disputed';

    public const string STATUS_DECLINED = 'declined';

    /** Paid, but no panel user or token pack matched it, so nothing was credited. */
    public const string STATUS_UNMATCHED = 'unmatched';

    /** Paid less than the package price (a coupon, a sale, another currency), so nothing was credited. */
    public const string STATUS_UNDERPAID = 'underpaid';

    /** The reference type of this payment's entries in the token ledger. */
    public const string LEDGER_REFERENCE = 'tebex_payment';

    /** Full webhook bodies (buyer name, email, address, IP) are kept this long after the last one. */
    public const int PAYLOAD_RETENTION_DAYS = 90;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'tokens' => 'integer',
            'tokens_reversed' => 'integer',
            'amount' => 'decimal:2',
            'raw' => 'array',
            'raw_pruned_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'flagged_at' => 'immutable_datetime',
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
     * Cuts every stored webhook down to its id, type and date: the duplicate check and the
     * admin's event list keep working, the buyer's personal details are gone. The payment's
     * `updated_at` is left alone.
     */
    public function pruneRaw(): void
    {
        $this->raw = array_map(fn (array $event): array => array_intersect_key($event, array_flip(['id', 'type', 'date'])), $this->raw);
        $this->raw_pruned_at = CarbonImmutable::now();
        $this->timestamps = false;

        try {
            $this->save();
        } finally {
            $this->timestamps = true;
        }
    }

    /** Whether a webhook with this ID was already recorded for the transaction. */
    public function hasEvent(string $webhookId): bool
    {
        foreach ($this->raw as $event) {
            if (($event['id'] ?? null) === $webhookId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'transaction_id' => $this->transaction_id,
            'user' => $this->user ? ['id' => $this->user->id, 'username' => $this->user->username, 'email' => $this->user->email] : null,
            'status' => $this->status,
            'tokens' => $this->tokens,
            'tokens_reversed' => $this->tokens_reversed,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'events' => array_map(fn (array $event): string => is_string($event['type'] ?? null) ? $event['type'] : 'unknown', $this->raw),
            'flagged' => $this->flagged_at !== null,
            'flag_reason' => $this->flag_reason,
            'processed_at' => $this->processed_at?->toAtomString(),
            'created_at' => $this->created_at?->toAtomString(),
        ];
    }
}
