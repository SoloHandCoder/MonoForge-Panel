<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Billing;

use LogicException;
use Pterodactyl\Exceptions\Billing\InsufficientTokensException;
use Pterodactyl\Models\Billing\TokenTransaction;
use Pterodactyl\Models\User;

/**
 * STAND-IN for Task Q (phase 3). Task Q owns the real wallet (users.token_balance and the
 * token_transactions ledger): on merge, keep Task Q's version of this file.
 *
 * This stand-in keeps balances in memory so Task T's tests can run before Task Q is merged,
 * with the public signatures from the phase 3 plan. Outside tests it refuses to work, so a
 * branch without Task Q can never charge, credit or suspend anybody.
 */
class TokenWallet
{
    /** @var array<int, int> */
    private array $balances = [];

    /** @var list<TokenTransaction> */
    private array $transactions = [];

    public function balance(User $user): int
    {
        $this->guard();

        return $this->balances[$user->id] ?? 0;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function credit(User $user, int $tokens, string $type, string $description, ?string $refType = null, ?string $refId = null, array $meta = []): TokenTransaction
    {
        $this->guard();
        throw_if($tokens <= 0, LogicException::class, 'A credit must be positive.');

        return $this->record($user, $tokens, $type, $description, $refType, $refId, $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     *
     * @throws InsufficientTokensException
     */
    public function debit(User $user, int $tokens, string $type, string $description, ?string $refType = null, ?string $refId = null, array $meta = [], bool $allowNegative = false): TokenTransaction
    {
        $this->guard();
        throw_if($tokens <= 0, LogicException::class, 'A debit must be positive.');
        throw_if(! $allowNegative && $this->balance($user) < $tokens, InsufficientTokensException::class, 'You do not have enough tokens.');

        return $this->record($user, -$tokens, $type, $description, $refType, $refId, $meta);
    }

    public function hasTransaction(string $refType, string $refId, string $type): bool
    {
        $this->guard();

        foreach ($this->transactions as $transaction) {
            if ($transaction->reference_type === $refType && $transaction->reference_id === $refId && $transaction->type === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function record(User $user, int $amount, string $type, string $description, ?string $refType, ?string $refId, array $meta): TokenTransaction
    {
        $this->balances[$user->id] = $this->balance($user) + $amount;
        $transaction = new TokenTransaction([
            'user_id' => $user->id,
            'amount' => $amount,
            'balance_after' => $this->balances[$user->id],
            'type' => $type,
            'reference_type' => $refType,
            'reference_id' => $refId,
            'description' => $description,
            'meta' => $meta,
        ]);
        $this->transactions[] = $transaction;

        return $transaction;
    }

    private function guard(): void
    {
        throw_unless(app()->runningUnitTests(), LogicException::class, 'The Task Q token wallet is not merged yet; billing is unavailable.');
    }
}
