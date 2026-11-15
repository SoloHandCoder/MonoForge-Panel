<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Resources\Api\Client;

use Illuminate\Http\Request;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseField;
use Pterodactyl\Models\Billing\TokenTransaction;

/**
 * A row of a user's token ledger.
 *
 * @extends ClientResource<TokenTransaction>
 */
#[ResponseField('amount', 'integer', 'Signed change in tokens: positive credits, negative debits.', example: -500)]
#[ResponseField('balance_after', 'integer', 'The token balance right after this change.', example: 1500)]
#[ResponseField('type', example: 'server_charge', enum: TokenTransaction::TYPES)]
#[ResponseField('reference_type', example: 'server', nullable: true)]
#[ResponseField('reference_id', example: '12', nullable: true)]
class TokenTransactionResource extends ClientResource
{
    public function resourceName(): string
    {
        return TokenTransaction::RESOURCE_NAME;
    }

    public function toArray(Request $request): array
    {
        $transaction = $this->resource;

        return [
            'id' => $transaction->id,
            'amount' => $transaction->amount,
            'balance_after' => $transaction->balance_after,
            'type' => $transaction->type,
            'reference_type' => $transaction->reference_type,
            'reference_id' => $transaction->reference_id,
            'description' => $transaction->description,
            'created_at' => $this->formatTimestamp($transaction->created_at),
        ];
    }
}
