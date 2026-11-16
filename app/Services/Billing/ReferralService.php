<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Billing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Models\ActivityLog;
use Pterodactyl\Models\Billing\Referral;
use Pterodactyl\Models\Billing\TebexPayment;
use Pterodactyl\Models\Billing\TokenTransaction;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Notices\NoticeService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * The referral programme. A sign-up through a referral link is recorded (and flagged for review
 * when it looks like the referrer's own second account); the referred user's first completed
 * Tebex token purchase starts a hold, and once that purchase has stayed paid for the hold
 * period both users get the reward. A refund, chargeback or dispute before the payout cancels
 * it, and one after the payout takes both rewards back.
 *
 * Locks are always taken in the same order, so the payout cannot deadlock with a webhook:
 * the Tebex payment, then the users (by id), then the referral row.
 */
class ReferralService
{
    public const string PATH_PREFIX = '/r/';

    /** No 0/O or 1/I/L, so a code read aloud or typed from a screenshot comes out right. */
    private const string CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    private const int CODE_LENGTH = 8;

    /** How many of the referrer's recent activity log addresses are compared with a sign-up. */
    private const int RECENT_ADDRESSES = 200;

    public function __construct(private readonly TokenWallet $wallet, private readonly NoticeService $notices) {}

    /** The user's referral code, created the first time it is asked for. */
    public function codeFor(User $user): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = DB::table('referral_codes')->where('user_id', $user->id)->value('code');
            if (is_string($code)) {
                return $code;
            }

            // A clash with another user's code (or a concurrent request for the same user) inserts nothing.
            DB::table('referral_codes')->insertOrIgnore(['user_id' => $user->id, 'code' => $this->randomCode(), 'created_at' => now()]);
        }

        throw new ConflictHttpException('A referral code could not be created. Please try again.');
    }

    /** The user a referral code belongs to, whatever its case. */
    public function referrerFor(string $code): ?User
    {
        $code = mb_strtoupper(mb_trim($code));
        if (preg_match('/^['.self::CODE_ALPHABET.']{'.self::CODE_LENGTH.'}$/', $code) !== 1) {
            return null;
        }

        $userId = DB::table('referral_codes')->where('code', $code)->value('user_id');

        return is_numeric($userId) ? User::query()->find((int) $userId) : null;
    }

    /**
     * Records the sign-up of a user who arrived through a referral link. A failure here never
     * fails the sign-up.
     *
     * @param  mixed  $code  the referral cookie's value, as the request carried it
     */
    public function recordSignup(User $user, mixed $code, ?string $ip): ?Referral
    {
        if (! is_string($code) || ! ReferralSettings::enabled()) {
            return null;
        }

        try {
            $referrer = $this->referrerFor($code);
            if (! $referrer instanceof User || $referrer->id === $user->id) {
                return null;
            }

            $network = NetworkHash::of($ip);
            $reason = $this->suspicion($referrer, $network);

            return Referral::query()->create([
                'referrer_id' => $referrer->id,
                'referred_id' => $user->id,
                'status' => Referral::STATUS_REGISTERED,
                'network_hash' => $network,
                'flagged_at' => $reason === null ? null : now(),
                'flag_reason' => $reason,
            ]);
        } catch (Throwable $throwable) {
            report($throwable);

            return null;
        }
    }

    /**
     * A Tebex payment was credited to the user. Their first one starts the referral's hold.
     * Called inside the payment's locked transaction.
     */
    public function paymentCredited(TebexPayment $payment, User $user): void
    {
        // Most payments are not a referred user's first: skip the lock for them.
        if (! Referral::query()->where('referred_id', $user->id)->where('status', Referral::STATUS_REGISTERED)->exists()) {
            return;
        }

        $referral = Referral::query()->where('referred_id', $user->id)->lockForUpdate()->first();
        if (! $referral instanceof Referral || $referral->status !== Referral::STATUS_REGISTERED) {
            return;
        }

        $now = now()->toImmutable();
        $referral->forceFill([
            'status' => Referral::STATUS_HELD,
            'tebex_payment_id' => $payment->id,
            'qualified_at' => $now,
            'payout_after' => $now->addDays(ReferralSettings::HOLD_DAYS),
        ])->save();
    }

    /**
     * A Tebex payment was refunded, charged back or disputed. A referral it qualified is
     * cancelled before the payout; after the payout both rewards are taken back. Called inside
     * the payment's locked transaction.
     */
    public function paymentReversed(TebexPayment $payment, string $why): void
    {
        $referralId = Referral::query()->where('tebex_payment_id', $payment->id)->value('id');
        if (! is_numeric($referralId)) {
            return;
        }

        $ids = Referral::query()->whereKey((int) $referralId)->first(['referrer_id', 'referred_id']);
        if (! $ids instanceof Referral) {
            return;
        }

        $this->lockUsers([$ids->referrer_id, $ids->referred_id]);
        $referral = Referral::query()->whereKey((int) $referralId)->lockForUpdate()->firstOrFail();

        if (in_array($referral->status, [Referral::STATUS_REGISTERED, Referral::STATUS_HELD], true)) {
            $referral->forceFill([
                'status' => Referral::STATUS_REVERSED,
                'reversed_at' => now(),
                'flag_reason' => mb_substr("The first purchase was {$why} before the reward was paid.", 0, 255),
            ])->save();

            return;
        }

        if ($referral->status !== Referral::STATUS_PAID) {
            return;
        }

        $tokens = (int) $referral->reward_tokens;
        foreach (['referrer' => $referral->referrer_id, 'referred' => $referral->referred_id] as $side => $userId) {
            $user = User::query()->find($userId);
            $reference = "{$referral->id}:{$side}:reversal";
            if (! $user instanceof User || $tokens < 1 || $this->wallet->hasTransaction(Referral::LEDGER_REFERENCE, $reference, TokenTransaction::TYPE_REFERRAL)) {
                continue;
            }

            $this->wallet->debit($user, $tokens, TokenTransaction::TYPE_REFERRAL, 'Referral reward taken back: the purchase was '.$why, Referral::LEDGER_REFERENCE, $reference, ['referral_id' => $referral->id], true);
            $this->notices->notify($user, 'warning', 'Referral reward taken back', number_format($tokens)." tokens of a referral reward were taken back because the purchase it was for was {$why}.", null, 'Open wallet', '/account/wallet');
        }

        $referral->forceFill([
            'status' => Referral::STATUS_REVERSED,
            'reversed_at' => now(),
            'flagged_at' => $referral->flagged_at ?? now(),
            'flag_reason' => mb_substr("The first purchase was {$why} after the reward was paid; both rewards were taken back.", 0, 255),
        ])->save();

        Log::warning('A referral reward was taken back after the purchase it was for was reversed.', ['referral' => $referral->id]);
        User::query()->where('root_admin', true)->each(function (User $admin) use ($referral, $why): void {
            $this->notices->notify($admin, 'warning', 'Referral reversed after payout', "Referral #{$referral->id} was paid, then its purchase was {$why}. Both rewards were taken back; check the accounts in Shop > Referrals.", null, 'Open referrals', '/panel/shop/referrals');
        });
    }

    /**
     * Pays every referral whose hold has passed. Paused while the programme is off.
     */
    public function payoutDue(): int
    {
        if (! ReferralSettings::enabled()) {
            return 0;
        }

        $paid = 0;
        Referral::query()
            ->where('status', Referral::STATUS_HELD)
            ->where('payout_after', '<=', now())
            ->where(fn ($query) => $query->whereNull('flagged_at')->orWhereNotNull('reviewed_at'))
            ->orderBy('id')
            ->chunkById(100, function ($referrals) use (&$paid): void {
                foreach ($referrals as $referral) {
                    try {
                        $paid += $this->payout($referral) === Referral::STATUS_PAID ? 1 : 0;
                    } catch (Throwable $throwable) {
                        report($throwable);
                    }
                }
            });

        return $paid;
    }

    /**
     * Pays one referral if it is due: its purchase must still be completed and unrefunded, it
     * must not wait for review, and the referrer must be under the monthly cap. Returns the
     * referral's status afterwards.
     */
    public function payout(Referral $referral): string
    {
        return DB::transaction(function () use ($referral): string {
            if ($referral->tebex_payment_id !== null) {
                TebexPayment::query()->whereKey($referral->tebex_payment_id)->lockForUpdate()->first();
            }

            $this->lockUsers([$referral->referrer_id, $referral->referred_id]);
            $locked = Referral::query()->whereKey($referral->id)->lockForUpdate()->first();
            if (! $locked instanceof Referral) {
                return Referral::STATUS_REVERSED;
            }

            if ($locked->status !== Referral::STATUS_HELD || $locked->payout_after === null || $locked->payout_after->isFuture() || $locked->awaitingReview()) {
                return $locked->status;
            }

            $payment = $locked->tebex_payment_id === null ? null : TebexPayment::query()->find($locked->tebex_payment_id);
            if (! $payment instanceof TebexPayment || $payment->status !== TebexPayment::STATUS_COMPLETED || $payment->tokens_reversed > 0) {
                $locked->forceFill(['status' => Referral::STATUS_REVERSED, 'reversed_at' => now(), 'flag_reason' => 'The first purchase was not paid in full when the reward was due.'])->save();

                return Referral::STATUS_REVERSED;
            }

            $cap = ReferralSettings::monthlyCap();
            if ($cap > 0) {
                $paidThisMonth = Referral::query()
                    ->where('referrer_id', $locked->referrer_id)
                    ->where('status', Referral::STATUS_PAID)
                    ->where('paid_at', '>=', now()->startOfMonth())
                    ->count();
                if ($paidThisMonth >= $cap) {
                    $locked->forceFill(['status' => Referral::STATUS_CAPPED, 'flag_reason' => "The referrer had already been paid {$cap} referral rewards this month."])->save();

                    return Referral::STATUS_CAPPED;
                }
            }

            $tokens = ReferralSettings::reward();
            $referrer = User::query()->findOrFail($locked->referrer_id);
            $referred = User::query()->findOrFail($locked->referred_id);
            $this->reward($locked, $referrer, 'referrer', $tokens, "Referral reward: {$referred->username} made their first purchase");
            $this->reward($locked, $referred, 'referred', $tokens, 'Referral reward: welcome bonus for joining through a referral link');

            $locked->forceFill(['status' => Referral::STATUS_PAID, 'paid_at' => now(), 'reward_tokens' => $tokens])->save();
            $referral->setRawAttributes($locked->getAttributes(), true);

            return Referral::STATUS_PAID;
        });
    }

    /**
     * An administrator clears a flagged referral, so it is paid once its hold passes.
     *
     * @throws ConflictHttpException
     */
    public function approve(Referral $referral, User $admin): Referral
    {
        return $this->review($referral, $admin, function (Referral $locked) use ($admin): void {
            throw_unless($locked->awaitingReview(), ConflictHttpException::class, 'Only a flagged referral waiting for review can be approved.');
            throw_if($admin->id === $locked->referrer_id || $admin->id === $locked->referred_id, ConflictHttpException::class, 'You cannot approve a referral of your own account.');
        });
    }

    /**
     * An administrator refuses a referral that has not been paid; it is never paid.
     *
     * @throws ConflictHttpException
     */
    public function reject(Referral $referral, User $admin): Referral
    {
        return $this->review($referral, $admin, function (Referral $locked): void {
            $locked->status = Referral::STATUS_REJECTED;
        });
    }

    /**
     * Statistics and the latest referrals for the referrer's own page.
     *
     * @return array{signed_up: int, pending: int, paid: int, tokens_earned: int}
     */
    public function stats(User $referrer): array
    {
        $counts = [];
        $tokens = 0;
        $rows = Referral::query()
            ->where('referrer_id', $referrer->id)
            ->toBase()
            ->selectRaw('status, COUNT(*) as aggregate, COALESCE(SUM(reward_tokens), 0) as tokens')
            ->groupBy('status')
            ->get();
        foreach ($rows as $row) {
            $status = is_string($row->status ?? null) ? $row->status : '';
            $counts[$status] = is_numeric($row->aggregate ?? null) ? (int) $row->aggregate : 0;
            if ($status === Referral::STATUS_PAID && is_numeric($row->tokens ?? null)) {
                $tokens = (int) $row->tokens;
            }
        }

        return [
            'signed_up' => array_sum($counts),
            'pending' => ($counts[Referral::STATUS_REGISTERED] ?? 0) + ($counts[Referral::STATUS_HELD] ?? 0),
            'paid' => $counts[Referral::STATUS_PAID] ?? 0,
            // Each paid referral rewarded the referrer with its reward_tokens.
            'tokens_earned' => $tokens,
        ];
    }

    /**
     * @param  callable(Referral): void  $change
     */
    private function review(Referral $referral, User $admin, callable $change): Referral
    {
        return DB::transaction(function () use ($referral, $admin, $change): Referral {
            if ($referral->tebex_payment_id !== null) {
                TebexPayment::query()->whereKey($referral->tebex_payment_id)->lockForUpdate()->first();
            }

            $this->lockUsers([$referral->referrer_id, $referral->referred_id]);
            $locked = Referral::query()->whereKey($referral->id)->lockForUpdate()->firstOrFail();
            throw_unless(in_array($locked->status, [Referral::STATUS_REGISTERED, Referral::STATUS_HELD], true), ConflictHttpException::class, 'Only a referral that has not been paid, reversed or rejected can be reviewed.');

            $change($locked);
            $locked->forceFill(['reviewed_by' => $admin->id, 'reviewed_at' => now()])->save();
            $referral->setRawAttributes($locked->getAttributes(), true);

            return $referral;
        });
    }

    private function reward(Referral $referral, User $user, string $side, int $tokens, string $description): void
    {
        $reference = "{$referral->id}:{$side}";
        if ($this->wallet->hasTransaction(Referral::LEDGER_REFERENCE, $reference, TokenTransaction::TYPE_REFERRAL)) {
            return;
        }

        $this->wallet->credit($user, $tokens, TokenTransaction::TYPE_REFERRAL, $description, Referral::LEDGER_REFERENCE, $reference, ['referral_id' => $referral->id]);
        $this->notices->notify($user, 'success', 'Referral reward', number_format($tokens).' tokens from a referral were added to your wallet.', null, 'Open referrals', '/account/referrals');
    }

    /**
     * Why a sign-up looks like the referrer's own second account, or null.
     */
    private function suspicion(User $referrer, ?string $network): ?string
    {
        if ($network === null) {
            return null;
        }

        $own = Referral::query()->where('referred_id', $referrer->id)->value('network_hash');
        if ($own === $network || in_array($network, $this->recentNetworks($referrer), true)) {
            return 'Signed up from the same network (/24 or /48) the referrer uses.';
        }

        if (Referral::query()->where('referrer_id', $referrer->id)->where('network_hash', $network)->exists()) {
            return 'Another account this user referred signed up from the same network (/24 or /48).';
        }

        return null;
    }

    /**
     * The hashed networks of the referrer's latest activity log entries (sign-ins and the like).
     *
     * @return list<string>
     */
    private function recentNetworks(User $referrer): array
    {
        $ips = ActivityLog::query()
            ->where('actor_type', $referrer->getMorphClass())
            ->where('actor_id', $referrer->id)
            ->latest('id')
            ->limit(self::RECENT_ADDRESSES)
            ->pluck('ip')
            ->unique();

        $networks = [];
        foreach ($ips as $ip) {
            $network = NetworkHash::of(is_string($ip) ? $ip : null);
            if ($network !== null) {
                $networks[] = $network;
            }
        }

        return array_values(array_unique($networks));
    }

    /**
     * Locks the users' rows in id order, the order every referral change uses.
     *
     * @param  list<int>  $ids
     */
    private function lockUsers(array $ids): void
    {
        $ids = array_values(array_unique($ids));
        sort($ids);
        User::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->pluck('id');
    }

    private function randomCode(): string
    {
        $code = '';
        $last = mb_strlen(self::CODE_ALPHABET) - 1;
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, $last)];
        }

        return $code;
    }
}
