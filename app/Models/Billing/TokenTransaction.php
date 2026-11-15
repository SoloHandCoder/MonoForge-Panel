<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Billing;

use Carbon\CarbonImmutable;
use Database\Factories\Billing\TokenTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\Model;
use Pterodactyl\Models\User;

/**
 * One change to a user's token balance. Rows are written only by TokenWallet and never
 * updated: a mistake is fixed with a `correction` row, so the ledger always adds up.
 *
 * @property int $id
 * @property int $user_id
 * @property int $amount Signed: positive credits, negative debits.
 * @property int $balance_after
 * @property string $type One of TokenTransaction::TYPES.
 * @property string|null $reference_type
 * @property string|null $reference_id
 * @property string $description
 * @property array<string, mixed>|null $meta
 * @property CarbonImmutable $created_at
 */
#[Fillable([
    'user_id',
    'amount',
    'balance_after',
    'type',
    'reference_type',
    'reference_id',
    'description',
    'meta',
])]
#[RouteKey('id')]
#[Table(name: 'token_transactions')]
class TokenTransaction extends Model
{
    /** @use HasFactory<TokenTransactionFactory> */
    use HasFactory;

    public const string RESOURCE_NAME = 'token_transaction';

    public const string TYPE_PURCHASE = 'purchase';

    public const string TYPE_REFUND = 'refund';

    public const string TYPE_CHARGEBACK = 'chargeback';

    public const string TYPE_SERVER_CHARGE = 'server_charge';

    public const string TYPE_ADDON_CHARGE = 'addon_charge';

    public const string TYPE_AI_USAGE = 'ai_usage';

    public const string TYPE_ADMIN_ADJUST = 'admin_adjust';

    public const string TYPE_CORRECTION = 'correction';

    /** @var list<string> */
    public const array TYPES = [
        self::TYPE_PURCHASE,
        self::TYPE_REFUND,
        self::TYPE_CHARGEBACK,
        self::TYPE_SERVER_CHARGE,
        self::TYPE_ADDON_CHARGE,
        self::TYPE_AI_USAGE,
        self::TYPE_ADMIN_ADJUST,
        self::TYPE_CORRECTION,
    ];

    public const null UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'int',
            'amount' => 'int',
            'balance_after' => 'int',
            'meta' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
