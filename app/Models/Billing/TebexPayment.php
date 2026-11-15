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
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $flagged_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property User|null $user
 */
#[Fillable(['transaction_id', 'user_id', 'status', 'tokens', 'tokens_reversed', 'amount', 'currency', 'raw', 'processed_at', 'flagged_at'])]
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

    /** The reference type of this payment's entries in the token ledger. */
    public const string LEDGER_REFERENCE = 'tebex_payment';

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
            'processed_at' => $this->processed_at?->toAtomString(),
            'created_at' => $this->created_at?->toAtomString(),
        ];
    }
}
