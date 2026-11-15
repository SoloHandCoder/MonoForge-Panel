<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Billing;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Pterodactyl\Exceptions\Billing\InsufficientTokensException;
use Pterodactyl\Models\Billing\TokenTransaction;
use Pterodactyl\Models\User;

/**
 * The only way a user's token balance changes. Every change locks the user row, writes a
 * ledger row with the balance after it and updates `users.token_balance` in one database
 * transaction, so the balance always equals the sum of the user's ledger.
 *
 * Called inside an outer transaction, the lock is held until that transaction commits.
 */
class TokenWallet
{
    /** The most tokens a single change may move; keeps every sum far inside a signed 64-bit integer. */
    public const int MAX_TOKENS = 1_000_000_000_000;

    /** The user's current balance, read from the database rather than the model in memory. */
    public function balance(User $user): int
    {
        $balance = User::query()->whereKey($user->getKey())->value('token_balance');

        return is_numeric($balance) ? (int) $balance : 0;
    }

    /**
     * Adds tokens to the user's balance.
     *
     * @param  array<string, mixed>  $meta
     *
     * @throws InvalidArgumentException when the amount or type is invalid
     */
    public function credit(User $user, int $tokens, string $type, string $description, ?string $refType = null, ?string $refId = null, array $meta = []): TokenTransaction
    {
        $this->assertTokens($tokens);

        return $this->apply($user, $tokens, $type, $description, $refType, $refId, $meta, true);
    }

    /**
     * Takes tokens from the user's balance.
     *
     * @param  array<string, mixed>  $meta
     *
     * @throws InsufficientTokensException when the balance would go below zero and that is not allowed
     * @throws InvalidArgumentException when the amount or type is invalid
     */
    public function debit(User $user, int $tokens, string $type, string $description, ?string $refType = null, ?string $refId = null, array $meta = [], bool $allowNegative = false): TokenTransaction
    {
        $this->assertTokens($tokens);

        return $this->apply($user, -$tokens, $type, $description, $refType, $refId, $meta, $allowNegative);
    }

    /**
     * Whether a ledger row of this type already exists for the reference, so an external event
     * (a Tebex transaction, a renewal period) is applied once. For a check that cannot race, call it
     * inside the caller's own transaction after a row lock, or keep a unique key of your own.
     */
    public function hasTransaction(string $refType, string $refId, string $type): bool
    {
        return TokenTransaction::query()
            ->where('reference_type', $refType)
            ->where('reference_id', $refId)
            ->where('type', $type)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function apply(User $user, int $amount, string $type, string $description, ?string $refType, ?string $refId, array $meta, bool $allowNegative): TokenTransaction
    {
        throw_unless(in_array($type, TokenTransaction::TYPES, true), InvalidArgumentException::class, "Unknown token transaction type [{$type}].");

        $description = mb_trim($description);
        throw_if($description === '', InvalidArgumentException::class, 'A token transaction needs a description.');

        $transaction = DB::transaction(function () use ($user, $amount, $type, $description, $refType, $refId, $meta, $allowNegative): TokenTransaction {
            $current = User::query()->whereKey($user->getKey())->lockForUpdate()->value('token_balance');
            throw_unless(is_numeric($current), InvalidArgumentException::class, 'The user does not exist.');

            $balance = (int) $current;
            $after = $balance + $amount;

            throw_if($amount < 0 && $after < 0 && ! $allowNegative, InsufficientTokensException::class, $balance, -$amount);

            // Through the base query builder: no model events, and the profile's updated_at stays put.
            User::query()->whereKey($user->getKey())->toBase()->update(['token_balance' => $after]);

            return TokenTransaction::query()->create([
                'user_id' => $user->getKey(),
                'amount' => $amount,
                'balance_after' => $after,
                'type' => $type,
                'reference_type' => $refType,
                'reference_id' => $refId,
                'description' => mb_substr($description, 0, 191),
                'meta' => $meta === [] ? null : $meta,
            ]);
        });

        $user->forceFill(['token_balance' => $transaction->balance_after])->syncOriginalAttribute('token_balance');

        return $transaction;
    }

    private function assertTokens(int $tokens): void
    {
        throw_if($tokens <= 0 || $tokens > self::MAX_TOKENS, InvalidArgumentException::class, 'A token amount must be a positive whole number of at most '.self::MAX_TOKENS.'.');
    }
}
