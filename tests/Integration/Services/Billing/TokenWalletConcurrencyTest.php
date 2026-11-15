<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Services\Billing\TokenWalletConcurrencyTest;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Pterodactyl\Models\Billing\TokenTransaction;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Billing\TokenWallet;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use RuntimeException;

// No DatabaseTransactions: the two worker processes must see the committed user.
uses(IntegrationTestCase::class);

/**
 * Starts a worker process that debits the user once.
 *
 * @return array{0: resource, 1: resource, 2: resource}
 */
function startDebit(int $userId, int $tokens): array
{
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, __DIR__.'/wallet-race-worker.php', (string) $userId, (string) $tokens],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    throw_unless(is_resource($process), RuntimeException::class, 'Could not start the wallet worker.');

    return [$process, $pipes[1], $pipes[2]];
}

/**
 * @param  array{0: resource, 1: resource, 2: resource}  $worker
 * @return array<string, mixed>
 */
function finishDebit(array $worker): array
{
    [$process, $stdout, $stderr] = $worker;
    $output = (string) stream_get_contents($stdout);
    $errors = (string) stream_get_contents($stderr);
    proc_close($process);

    $result = json_decode($output, true);
    throw_unless(is_array($result), RuntimeException::class, 'The wallet worker failed: '.$output.$errors);

    return $result;
}

/** Connections other than this one that are running the wallet's locking read of a user. */
function waitingTransactions(): int
{
    $count = DB::selectOne("select count(*) as waiting from information_schema.processlist where id <> connection_id() and info like 'select `token_balance` from `users`%for update'");

    return is_object($count) && isset($count->waiting) && is_numeric($count->waiting) ? (int) $count->waiting : 0;
}

test('two simultaneous debits never spend the same tokens twice', function (): void {
    $user = User::factory()->create();

    try {
        resolve(TokenWallet::class)->credit($user, 1000, TokenTransaction::TYPE_PURCHASE, 'Bought 1000 tokens');

        // Hold the user row so both workers queue on the lock and race the moment it is released.
        DB::beginTransaction();
        User::query()->whereKey($user->id)->lockForUpdate()->value('id');

        $workers = [startDebit($user->id, 600), startDebit($user->id, 600)];

        $deadline = microtime(true) + 30;
        while (waitingTransactions() < 2 && microtime(true) < $deadline) {
            Sleep::usleep(50_000);
        }

        $bothWaited = waitingTransactions() >= 2;
        DB::commit();

        $results = array_map(finishDebit(...), $workers);

        expect($bothWaited)->toBeTrue('Both workers should have waited on the user row lock.')
            ->and(collect($results)->pluck('ok')->sort()->values()->all())->toBe([false, true])
            ->and(resolve(TokenWallet::class)->balance($user))->toBe(400)
            ->and($user->tokenTransactions()->count())->toBe(2)
            ->and((int) $user->tokenTransactions()->sum('amount'))->toBe(400);
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        $user->delete();
    }
});
