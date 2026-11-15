<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools\Admin;

use Pterodactyl\Exceptions\Assistant\ToolException;
use Pterodactyl\Exceptions\Billing\InsufficientTokensException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Requests\Api\Admin\Users\AdjustUserTokensRequest;
use Pterodactyl\Models\Billing\TokenTransaction;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolResult;
use Pterodactyl\Services\Billing\TokenWallet;

/**
 * Adds tokens to or removes them from a user's wallet, under the limits of the admin token
 * endpoint: root admins only, never their own wallet, at most 1,000,000 tokens either way,
 * a reason of 3 to 191 characters, and a balance that cannot go below zero.
 */
final class AdjustTokensTool extends AdminTool
{
    public const int MAX_ADJUSTMENT = AdjustUserTokensRequest::MAX_ADJUSTMENT;

    public function __construct(private readonly TokenWallet $wallet) {}

    public function name(): string
    {
        return 'adjust_tokens';
    }

    public function description(): string
    {
        return "Add tokens to (positive amount) or remove them from (negative amount) a user's wallet, with a reason that is kept in their ledger. At most ".number_format(self::MAX_ADJUSTMENT).' tokens either way; your own wallet cannot be adjusted. The admin is asked to confirm first.';
    }

    public function parameters(): array
    {
        return $this->schema([
            'user' => ['type' => 'string', 'description' => 'The user id, username or email.'],
            'amount' => ['type' => 'integer', 'minimum' => -self::MAX_ADJUSTMENT, 'maximum' => self::MAX_ADJUSTMENT, 'description' => 'Tokens to add (positive) or remove (negative); not 0.'],
            'reason' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 191],
        ], ['user', 'amount', 'reason']);
    }

    public function needsConfirmation(array $arguments, ToolContext $context): bool
    {
        return true;
    }

    public function describeCall(array $arguments): string
    {
        $amount = $arguments['amount'] ?? null;
        $amount = is_numeric($amount) ? (int) $amount : 0;

        return sprintf('%s %s tokens %s %s', $amount < 0 ? 'Remove' : 'Add', number_format(abs($amount)), $amount < 0 ? 'from' : 'to', $this->labelArgument($arguments, 'user'));
    }

    /** Root administrators only, which allowed() already requires. */
    protected function permissions(): array
    {
        return [];
    }

    protected function run(array $arguments, ToolContext $context): ToolResult
    {
        $raw = $arguments['amount'] ?? null;
        $amount = $this->integer($arguments, 'amount', 0, PHP_INT_MIN, PHP_INT_MAX);
        if ($raw === null || $amount === 0 || abs($amount) > self::MAX_ADJUSTMENT) {
            throw new ToolException(sprintf('The amount must be a whole number between -%1$s and %1$s, and not 0.', number_format(self::MAX_ADJUSTMENT)));
        }

        $reason = mb_trim($this->string($arguments, 'reason', null, 191));
        throw_if(mb_strlen($reason) < 3, ToolException::class, 'The reason must be at least 3 characters.');

        $user = $this->findUser($arguments);
        throw_if($user->id === $context->user->id, ToolException::class, 'You cannot adjust your own token balance.');

        $admin = (string) $context->user->id;
        $meta = ['admin_id' => $context->user->id, 'via' => 'assistant'];

        try {
            $transaction = $amount > 0
                ? $this->wallet->credit($user, $amount, TokenTransaction::TYPE_ADMIN_ADJUST, $reason, 'admin', $admin, $meta)
                : $this->wallet->debit($user, -$amount, TokenTransaction::TYPE_ADMIN_ADJUST, $reason, 'admin', $admin, $meta);
        } catch (InsufficientTokensException $insufficientTokensException) {
            throw new ToolException(sprintf('%s only has %d tokens, so %d cannot be removed.', $user->username, $insufficientTokensException->balance, -$amount), 0, $insufficientTokensException);
        }

        Activity::event('admin:user.adjust-tokens')
            ->subject($user)
            ->property(['email' => $user->email, 'amount' => $amount, 'balance' => $transaction->balance_after, 'reason' => $reason])
            ->log();

        return new ToolResult(
            sprintf('%s %d tokens %s %s. Their balance is now %d.', $amount > 0 ? 'Added' : 'Removed', abs($amount), $amount > 0 ? 'to' : 'from', $user->username, $transaction->balance_after),
            ['user_id' => $user->id, 'amount' => $amount, 'balance' => $transaction->balance_after],
        );
    }
}
