<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Services\Billing\TokenWalletTest;

use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use InvalidArgumentException;
use Pterodactyl\Exceptions\Billing\InsufficientTokensException;
use Pterodactyl\Models\Billing\TokenTransaction;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Billing\TokenWallet;
use Pterodactyl\Tests\Integration\IntegrationTestCase;

uses(IntegrationTestCase::class, DatabaseTransactions::class);

function wallet(): TokenWallet
{
    return resolve(TokenWallet::class);
}

test('credits and debits write a ledger row with the balance after each change', function (): void {
    $user = User::factory()->create();

    $credit = wallet()->credit($user, 1000, TokenTransaction::TYPE_PURCHASE, 'Bought 1000 tokens', 'tebex', 'tbx-1', ['pack_id' => 3]);
    $debit = wallet()->debit($user, 300, TokenTransaction::TYPE_SERVER_CHARGE, 'Server renewal', 'server', '7');

    expect($credit->amount)->toBe(1000)->and($credit->balance_after)->toBe(1000)
        ->and($credit->meta)->toBe(['pack_id' => 3])
        ->and($debit->amount)->toBe(-300)->and($debit->balance_after)->toBe(700)
        ->and($debit->reference_type)->toBe('server')->and($debit->reference_id)->toBe('7')
        ->and(wallet()->balance($user))->toBe(700)
        ->and($user->token_balance)->toBe(700)
        ->and((int) $user->tokenTransactions()->sum('amount'))->toBe(700);
});

test('a debit beyond the balance throws and changes nothing', function (): void {
    $user = User::factory()->create();
    wallet()->credit($user, 100, TokenTransaction::TYPE_PURCHASE, 'Bought tokens');

    expect(fn (): TokenTransaction => wallet()->debit($user, 101, TokenTransaction::TYPE_SERVER_CHARGE, 'Too much'))
        ->toThrow(InsufficientTokensException::class, 'This needs 101 tokens, but your balance is 100 tokens.');

    expect(wallet()->balance($user))->toBe(100)->and($user->tokenTransactions()->count())->toBe(1);
});

test('a debit may go negative when allowed', function (): void {
    $user = User::factory()->create();
    wallet()->credit($user, 500, TokenTransaction::TYPE_PURCHASE, 'Bought tokens', 'tebex', 'tbx-9');

    $refund = wallet()->debit($user, 800, TokenTransaction::TYPE_REFUND, 'Refunded payment', 'tebex', 'tbx-9', allowNegative: true);

    expect($refund->balance_after)->toBe(-300)->and(wallet()->balance($user))->toBe(-300);
});

test('rejects amounts that are not positive and unknown types', function (string $method, int $tokens, string $type): void {
    $user = User::factory()->create();

    expect(fn (): TokenTransaction => wallet()->{$method}($user, $tokens, $type, 'Nope'))->toThrow(InvalidArgumentException::class);
    expect(TokenTransaction::query()->where('user_id', $user->id)->exists())->toBeFalse();
})->with([
    ['credit', 0, TokenTransaction::TYPE_PURCHASE],
    ['credit', -5, TokenTransaction::TYPE_PURCHASE],
    ['debit', 0, TokenTransaction::TYPE_SERVER_CHARGE],
    ['credit', 5, 'gift'],
    ['credit', TokenWallet::MAX_TOKENS + 1, TokenTransaction::TYPE_PURCHASE],
]);

test('hasTransaction finds a reference of the same type only', function (): void {
    $user = User::factory()->create();
    wallet()->credit($user, 50, TokenTransaction::TYPE_PURCHASE, 'Bought tokens', 'tebex', 'tbx-42');

    expect(wallet()->hasTransaction('tebex', 'tbx-42', TokenTransaction::TYPE_PURCHASE))->toBeTrue()
        ->and(wallet()->hasTransaction('tebex', 'tbx-42', TokenTransaction::TYPE_REFUND))->toBeFalse()
        ->and(wallet()->hasTransaction('tebex', 'tbx-43', TokenTransaction::TYPE_PURCHASE))->toBeFalse();
});

test('the balance cannot be mass assigned', function (): void {
    $user = User::factory()->create();

    expect(fn (): User => $user->fill(['token_balance' => 9999]))->toThrow(MassAssignmentException::class);
    expect(wallet()->balance($user))->toBe(0);
});

test('the low balance notice replaces its unread predecessor', function (): void {
    $user = User::factory()->create();
    wallet()->credit($user, 20, TokenTransaction::TYPE_PURCHASE, 'Bought tokens');

    wallet()->notifyLowBalance($user, 500);

    $notice = wallet()->notifyLowBalance($user, 500);

    expect($user->notices()->count())->toBe(1)
        ->and($notice->kind)->toBe(TokenWallet::LOW_BALANCE_KIND)
        ->and($notice->body)->toContain('20 tokens')->toContain('500')
        ->and($notice->action_url)->toBe('/account/wallet');
});
