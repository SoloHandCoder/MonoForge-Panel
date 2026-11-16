<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Billing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Pterodactyl\Data\CouponDiscount;
use Pterodactyl\Exceptions\Billing\CouponException;
use Pterodactyl\Models\Billing\Coupon;
use Pterodactyl\Models\Billing\CouponRedemption;
use Pterodactyl\Models\Billing\ServerSubscription;
use Pterodactyl\Models\Billing\ShopPlan;
use Pterodactyl\Models\Billing\TebexPayment;
use Pterodactyl\Models\Billing\TokenTransaction;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;

/**
 * Checks and redeems coupons. A redemption is atomic: it runs in one transaction under the
 * coupon's row lock (so the redemption limit cannot be overrun by concurrent requests), the
 * database allows one redemption per coupon and user, and a store purchase records its
 * redemption with the purchase's idempotency reference, so a replayed purchase never uses a
 * coupon twice. A purchase that fails after the charge gives its redemption back.
 */
class CouponService
{
    /** The reference type of a coupon's wallet credit in the token ledger. */
    public const string LEDGER_REFERENCE = 'coupon';

    /** Why a free-tokens coupon is refused to an account without a linked login. */
    public const string LINK_ACCOUNT = 'Link a Discord or Google account on your account page to redeem coupons for free tokens.';

    /** Unknown or refused codes a user may try in FAILURE_WINDOW seconds, so codes cannot be guessed. */
    public const int MAX_FAILURES = 10;

    public const int FAILURE_WINDOW = 600;

    public function __construct(private readonly TokenWallet $wallet) {}

    /**
     * Runs a coupon check, counting refused codes per user: after MAX_FAILURES within
     * FAILURE_WINDOW seconds every code is refused until the window passes.
     *
     * @template T
     *
     * @param  callable(): T  $check
     * @return T
     *
     * @throws CouponException
     */
    public function attempt(User $user, callable $check): mixed
    {
        $key = 'coupon-failures:'.$user->id;
        throw_if(RateLimiter::tooManyAttempts($key, self::MAX_FAILURES), CouponException::class, 'Too many coupon codes were tried. Please wait a few minutes and try again.');

        try {
            return $check();
        } catch (CouponException $couponException) {
            RateLimiter::hit($key, self::FAILURE_WINDOW);

            throw $couponException;
        }
    }

    /** The coupon with this code, whatever its case, or null. */
    public function find(string $code): ?Coupon
    {
        $code = Coupon::normalize($code);
        if (preg_match(Coupon::CODE_PATTERN, $code) !== 1) {
            return null;
        }

        return Coupon::query()->where('code', $code)->first();
    }

    /**
     * What the coupon takes off a first period of `$total` tokens, without redeeming it, for
     * a quote and for the checks before a purchase charges.
     *
     * @throws CouponException
     */
    public function preview(User $user, string $code, ShopPlan $plan, int $total): CouponDiscount
    {
        $coupon = $this->find($code) ?? throw CouponException::invalid();
        $this->check($coupon, $user, $plan, null);

        return $this->discount($coupon, $total);
    }

    /**
     * Redeems a discount coupon for a store purchase: locks the coupon, checks it again and
     * records the redemption under the purchase's reference. Run it in the same transaction as
     * the charge, so a refused charge gives the redemption back with it.
     *
     * @throws CouponException
     */
    public function redeemForPurchase(User $user, string $code, ShopPlan $plan, int $total, string $reference, ?string $ip): CouponDiscount
    {
        return DB::transaction(function () use ($user, $code, $plan, $total, $reference, $ip): CouponDiscount {
            $coupon = $this->lock($code);
            $this->lockUser($user);
            $this->check($coupon, $user, $plan, null);

            $discount = $this->discount($coupon, $total);
            $this->record($coupon, $user, $discount->discount, $reference, NetworkHash::of($ip));

            return $discount;
        });
    }

    /** Links the redemption of a finished purchase to its server, for the audit list. */
    public function attachServer(string $reference, Server $server): void
    {
        CouponRedemption::query()->where('purchase_reference', $reference)->update(['server_id' => $server->id]);
    }

    /**
     * Gives back the redemption of a purchase that failed after the charge, so the player can
     * use the coupon again. Nothing happens when the purchase used no coupon.
     */
    public function release(string $reference): void
    {
        DB::transaction(function () use ($reference): void {
            $couponId = CouponRedemption::query()->where('purchase_reference', $reference)->value('coupon_id');
            if (! is_numeric($couponId)) {
                return;
            }

            Coupon::query()->whereKey((int) $couponId)->lockForUpdate()->first();
            $deleted = CouponRedemption::query()->where('purchase_reference', $reference)->delete();
            if ($deleted > 0) {
                Coupon::query()->whereKey((int) $couponId)->where('redemptions_count', '>', 0)->decrement('redemptions_count');
            }
        });
    }

    /**
     * Redeems a free-tokens coupon on the Wallet page: credits its tokens once.
     *
     * Free tokens are the easiest thing to farm with new accounts, so on top of the usual
     * limits the account needs a linked Discord or Google account, and a free-tokens coupon is
     * redeemed once per linked account and once per network (/24 or /48), and never by the
     * administrator who created it.
     *
     * @throws CouponException
     */
    public function redeemTokens(User $user, string $code, ?string $ip): CouponRedemption
    {
        // Asked before the code is looked at, so it says nothing about the code.
        $identities = TrialFingerprint::linkedIdentities($user);
        throw_if($identities === [], CouponException::class, self::LINK_ACCOUNT);

        return DB::transaction(function () use ($user, $code, $ip, $identities): CouponRedemption {
            $coupon = $this->lock($code);
            $this->lockUser($user);
            $network = NetworkHash::of($ip);
            $this->check($coupon, $user, null, $network);

            $sameIdentity = CouponRedemption::query()->where('coupon_id', $coupon->id)->where(function (Builder $query) use ($identities): void {
                foreach ($identities as $column => $hash) {
                    $query->orWhere($column, $hash);
                }
            })->exists();
            throw_if($sameIdentity, CouponException::invalid());

            $tokens = min($coupon->value, Coupon::MAX_TOKENS);
            throw_if($tokens < 1, CouponException::invalid());

            $redemption = $this->record($coupon, $user, $tokens, null, $network, $identities);
            $this->wallet->credit(
                $user,
                $tokens,
                TokenTransaction::TYPE_COUPON,
                sprintf('Coupon %s', $coupon->code),
                self::LEDGER_REFERENCE,
                (string) $redemption->id,
                ['coupon_id' => $coupon->id],
            );

            return $redemption;
        });
    }

    /**
     * Whether the user is not a new customer: any Tebex payment, any server subscription (a free
     * trial included), any server or add-on charge other than a store purchase that failed and
     * was refunded (renewals and a kept trial count), or a Tebex token purchase.
     */
    public function hasPurchased(User $user): bool
    {
        if (TebexPayment::query()->where('user_id', $user->id)->exists()
            || ServerSubscription::query()->where('user_id', $user->id)->exists()) {
            return true;
        }

        return TokenTransaction::query()
            ->where('user_id', $user->id)
            ->where(fn (Builder $query): Builder => $query
                ->where('type', TokenTransaction::TYPE_PURCHASE)
                ->orWhere(fn (Builder $query): Builder => $query
                    ->whereIn('type', [TokenTransaction::TYPE_SERVER_CHARGE, TokenTransaction::TYPE_ADDON_CHARGE])
                    ->where(fn (Builder $query): Builder => $query->whereNull('reference_type')->orWhere('reference_type', '!=', StorePurchaseService::REFERENCE_TYPE)))
                ->orWhere(fn (Builder $query): Builder => $query
                    ->where('type', TokenTransaction::TYPE_SERVER_CHARGE)
                    ->where('reference_type', StorePurchaseService::REFERENCE_TYPE)
                    ->whereNotExists(fn (QueryBuilder $refunds) => $refunds
                        ->from('token_transactions', 'refunds')
                        ->whereColumn('refunds.reference_id', 'token_transactions.reference_id')
                        ->where('refunds.reference_type', StorePurchaseService::REFERENCE_TYPE)
                        ->where('refunds.type', TokenTransaction::TYPE_REFUND))))
            ->exists();
    }

    /**
     * @throws CouponException
     */
    private function lock(string $code): Coupon
    {
        $code = Coupon::normalize($code);
        throw_if(preg_match(Coupon::CODE_PATTERN, $code) !== 1, CouponException::invalid());

        return Coupon::query()->where('code', $code)->lockForUpdate()->first() ?? throw CouponException::invalid();
    }

    /** Serialises this user's redemptions, so the "new customers only" check cannot race a purchase. */
    private function lockUser(User $user): void
    {
        User::query()->whereKey($user->id)->lockForUpdate()->value('id');
    }

    /**
     * Every rule of the coupon. `$plan` is the plan of a store purchase, or null for a wallet
     * redemption; `$network` is checked for free-tokens coupons.
     *
     * @throws CouponException
     */
    private function check(Coupon $coupon, User $user, ?ShopPlan $plan, ?string $network): void
    {
        // One answer for every reason a code cannot be used, so codes cannot be probed for.
        throw_unless($coupon->enabled, CouponException::invalid());

        $now = now();
        throw_if($coupon->valid_from !== null && $coupon->valid_from->isAfter($now), CouponException::invalid());
        throw_if($coupon->valid_until !== null && ! $coupon->valid_until->isAfter($now), CouponException::invalid());

        if ($plan instanceof ShopPlan) {
            throw_unless($coupon->isDiscount(), CouponException::class, 'This coupon adds tokens to your wallet. Redeem it on the Wallet page.');
            throw_unless($coupon->allowsPlan($plan), CouponException::invalid());
        } else {
            throw_if($coupon->isDiscount(), CouponException::class, 'This coupon is a discount. Enter it at the store checkout.');
            throw_if($coupon->created_by === $user->id, CouponException::invalid());
        }

        throw_if($coupon->max_redemptions !== null && $coupon->redemptions_count >= $coupon->max_redemptions, CouponException::invalid());

        $used = CouponRedemption::query()->where('coupon_id', $coupon->id)->where('user_id', $user->id)->exists();
        throw_if($used, CouponException::class, 'You have already used this coupon.');

        throw_if($coupon->new_customers_only && $this->hasPurchased($user), CouponException::invalid());

        if (! $coupon->isDiscount() && $network !== null) {
            $sameNetwork = CouponRedemption::query()->where('coupon_id', $coupon->id)->where('network_hash', $network)->exists();
            throw_if($sameNetwork, CouponException::invalid());
        }
    }

    private function discount(Coupon $coupon, int $total): CouponDiscount
    {
        $discount = $coupon->discountFor($total);

        return new CouponDiscount($coupon, $discount, max(1, $total - $discount));
    }

    /**
     * @param  array<'discord_hash'|'google_hash', string>  $identities  the linked accounts of a free-tokens redemption
     *
     * @throws CouponException
     */
    private function record(Coupon $coupon, User $user, int $tokens, ?string $reference, ?string $network, array $identities = []): CouponRedemption
    {
        try {
            $redemption = CouponRedemption::query()->create([
                'coupon_id' => $coupon->id,
                'user_id' => $user->id,
                'tokens' => $tokens,
                'purchase_reference' => $reference,
                'network_hash' => $network,
                'discord_hash' => $identities['discord_hash'] ?? null,
                'google_hash' => $identities['google_hash'] ?? null,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new CouponException('You have already used this coupon.');
        }

        Coupon::query()->whereKey($coupon->id)->increment('redemptions_count');
        $coupon->redemptions_count++;
        $redemption->setRelation('coupon', $coupon);

        return $redemption;
    }
}
