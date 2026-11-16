<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Services\Billing\CouponConcurrencyTest;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Pterodactyl\Enum\OAuthProvider;
use Pterodactyl\Models\Billing\Coupon;
use Pterodactyl\Models\Billing\CouponRedemption;
use Pterodactyl\Models\Billing\TokenTransaction;
use Pterodactyl\Models\User;
use Pterodactyl\Models\UserOAuthAccount;
use Pterodactyl\Services\Billing\TokenWallet;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use RuntimeException;

// No DatabaseTransactions: the worker processes must see the committed coupon and users.
uses(IntegrationTestCase::class);

/**
 * Starts a worker process that redeems the coupon once.
 *
 * @return array{0: resource, 1: resource, 2: resource}
 */
function startRedeem(int $userId, string $code, string $ip): array
{
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, __DIR__.'/coupon-race-worker.php', (string) $userId, $code, $ip],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    throw_unless(is_resource($process), RuntimeException::class, 'Could not start the coupon worker.');

    return [$process, $pipes[1], $pipes[2]];
}

/**
 * @param  array{0: resource, 1: resource, 2: resource}  $worker
 * @return array<string, mixed>
 */
function finishRedeem(array $worker): array
{
    [$process, $stdout, $stderr] = $worker;
    $output = (string) stream_get_contents($stdout);
    $errors = (string) stream_get_contents($stderr);
    proc_close($process);

    $result = json_decode($output, true);
    throw_unless(is_array($result), RuntimeException::class, 'The coupon worker failed: '.$output.$errors);

    return $result;
}

/** Connections other than this one that are waiting on the coupon's row lock. */
function waitingOnCoupon(): int
{
    $count = DB::selectOne("select count(*) as waiting from information_schema.processlist where id <> connection_id() and info like 'select * from `coupons` where%for update'");

    return is_object($count) && isset($count->waiting) && is_numeric($count->waiting) ? (int) $count->waiting : 0;
}

/**
 * Holds the coupon's row lock until both workers queue on it, then lets them race.
 *
 * @param  list<array{int, string}>  $redeemers  user id and address of each worker
 * @return array{bool, list<array<string, mixed>>}
 */
function race(Coupon $coupon, array $redeemers): array
{
    DB::beginTransaction();
    Coupon::query()->whereKey($coupon->id)->lockForUpdate()->value('id');

    $workers = array_map(fn (array $redeemer): array => startRedeem($redeemer[0], mb_strtolower($coupon->code), $redeemer[1]), $redeemers);

    $deadline = microtime(true) + 30;
    while (waitingOnCoupon() < count($redeemers) && microtime(true) < $deadline) {
        Sleep::usleep(50_000);
    }

    $allWaited = waitingOnCoupon() >= count($redeemers);
    DB::commit();

    return [$allWaited, array_map(finishRedeem(...), $workers)];
}

/** A user with a linked Discord account, which a free-tokens coupon needs. */
function linkedUser(): User
{
    $user = User::factory()->create();
    UserOAuthAccount::query()->create(['user_id' => $user->id, 'provider' => OAuthProvider::Discord, 'provider_user_id' => (string) (700000 + $user->id)]);

    return $user;
}

function cleanUp(Coupon $coupon, User ...$users): void
{
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    CouponRedemption::query()->where('coupon_id', $coupon->id)->delete();
    $coupon->delete();
    foreach ($users as $user) {
        UserOAuthAccount::query()->where('user_id', $user->id)->delete();
        $user->delete();
    }
}

test('two users racing for the last redemption: exactly one gets it', function (): void {
    $coupon = Coupon::query()->forceCreate(['code' => 'RACE'.mb_strtoupper(bin2hex(random_bytes(3))), 'type' => Coupon::TYPE_TOKENS, 'value' => 300, 'max_redemptions' => 1, 'enabled' => true]);
    $first = linkedUser();
    $second = linkedUser();

    try {
        [$allWaited, $results] = race($coupon, [[$first->id, '198.51.100.7'], [$second->id, '203.0.113.9']]);

        expect($allWaited)->toBeTrue('Both workers should have waited on the coupon row lock.')
            ->and(collect($results)->pluck('ok')->sort()->values()->all())->toBe([false, true])
            ->and(collect($results)->firstWhere('ok', false)['message'] ?? null)->toBe("This code can't be used.")
            ->and($coupon->refresh()->redemptions_count)->toBe(1)
            ->and(CouponRedemption::query()->where('coupon_id', $coupon->id)->count())->toBe(1)
            ->and(resolve(TokenWallet::class)->balance($first) + resolve(TokenWallet::class)->balance($second))->toBe(300)
            ->and(TokenTransaction::query()->whereIn('user_id', [$first->id, $second->id])->count())->toBe(1);
    } finally {
        cleanUp($coupon, $first, $second);
    }
});

test('one user redeeming the same coupon twice at once is credited once', function (): void {
    $coupon = Coupon::query()->forceCreate(['code' => 'TWICE'.mb_strtoupper(bin2hex(random_bytes(3))), 'type' => Coupon::TYPE_TOKENS, 'value' => 300, 'enabled' => true]);
    $user = linkedUser();

    try {
        [$allWaited, $results] = race($coupon, [[$user->id, '198.51.100.7'], [$user->id, '198.51.100.7']]);

        expect($allWaited)->toBeTrue('Both workers should have waited on the coupon row lock.')
            ->and(collect($results)->pluck('ok')->sort()->values()->all())->toBe([false, true])
            ->and($coupon->refresh()->redemptions_count)->toBe(1)
            ->and(resolve(TokenWallet::class)->balance($user))->toBe(300);
    } finally {
        cleanUp($coupon, $user);
    }
});
