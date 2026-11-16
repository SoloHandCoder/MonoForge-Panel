<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Pterodactyl\Exceptions\Billing\InvalidQuoteException;
use Pterodactyl\Exceptions\Billing\SubscriptionRenewalException;
use Pterodactyl\Models\Billing\ServerSubscription;
use Pterodactyl\Models\Billing\ShopPeriod;
use Pterodactyl\Models\Billing\ShopTrial;
use Pterodactyl\Models\Billing\TokenTransaction;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;

/**
 * Free trial servers: who may start one, the record of every trial (kept after the server is
 * gone), what keeping a trial server costs and the conversion rate.
 *
 * Anti-abuse: one trial per account, only for accounts that never bought a server, one per
 * linked Discord or Google account, one per network (/24 or /48, the one the trial is started
 * from and the one the account signed up from) per 30 days, a linked account when the admin
 * requires one, and at most `max_concurrent` running trials. The store-wide capacity lock
 * serialises trial starts, so these checks are race-free when made under it.
 */
class TrialService
{
    /** A network that started a trial cannot start another one for this many days. */
    public const int NETWORK_WINDOW_DAYS = 30;

    public function __construct(private readonly PricingService $pricing) {}

    /** Whether the store offers free trials at all right now. */
    public static function offered(): bool
    {
        return ShopSettings::enabled() && TrialSettings::enabled() && TrialSettings::planIds() !== [];
    }

    /**
     * Why the user cannot start a free trial from this address, or null when they can.
     */
    public function problem(User $user, ?string $ip): ?string
    {
        if (! self::offered()) {
            return 'Free trials are not available right now.';
        }

        if (ShopTrial::query()->where('user_id', $user->id)->exists()) {
            return 'You have already used your free trial.';
        }

        if ($this->hasBought($user)) {
            return 'Free trials are for new players, and your account already has a server from the store.';
        }

        $identities = TrialFingerprint::linkedIdentities($user);
        if (TrialSettings::requireVerified() && $identities === []) {
            return 'Link a Discord or Google account on your account page to start a free trial.';
        }

        if ($identities !== [] && ShopTrial::query()->where(function (Builder $query) use ($identities): void {
            foreach ($identities as $column => $hash) {
                $query->orWhere($column, $hash);
            }
        })->exists()) {
            return 'Your linked Discord or Google account was already used for a free trial.';
        }

        $networks = array_values(array_unique(array_filter([TrialFingerprint::subnet($ip), $this->signupNetwork($user)])));
        if ($networks !== [] && ShopTrial::query()
            ->where('created_at', '>=', CarbonImmutable::now()->subDays(self::NETWORK_WINDOW_DAYS))
            ->where(fn (Builder $query) => $query->whereIn('subnet_hash', $networks)->orWhereIn('signup_subnet_hash', $networks))
            ->exists()) {
            return 'A free trial was already started from your network recently.';
        }

        if ($this->running() >= TrialSettings::maxConcurrent()) {
            return 'All free trial slots are taken right now. Please try again later.';
        }

        if ($this->startedToday() >= TrialSettings::dailyCap()) {
            return "Today's free trials are all taken. Please try again tomorrow.";
        }

        return null;
    }

    /**
     * What the store shows about trials to this user.
     *
     * @return array{enabled: bool, hours: int, plan_ids: list<int>, eligible: bool, reason: string|null}
     */
    public function offer(User $user, ?string $ip): array
    {
        if (! self::offered()) {
            return ['enabled' => false, 'hours' => TrialSettings::hours(), 'plan_ids' => [], 'eligible' => false, 'reason' => null];
        }

        $problem = $this->problem($user, $ip);

        return [
            'enabled' => true,
            'hours' => TrialSettings::hours(),
            'plan_ids' => TrialSettings::planIds(),
            'eligible' => $problem === null,
            'reason' => $problem,
        ];
    }

    /** Free trials started since midnight in the panel's timezone (panel-wide). */
    public function startedToday(): int
    {
        return ShopTrial::query()->where('created_at', '>=', CarbonImmutable::now()->startOfDay())->count();
    }

    /** Free trials running now (panel-wide). */
    public function running(): int
    {
        return ServerSubscription::query()->where('status', ServerSubscription::STATUS_TRIAL)->count();
    }

    /**
     * Records a trial that was just started, with the hashes the anti-abuse checks compare.
     */
    public function record(User $user, Server $server, int $planId, int $hours, ?string $ip): ShopTrial
    {
        $identities = TrialFingerprint::linkedIdentities($user);

        return ShopTrial::query()->create([
            'user_id' => $user->id,
            'server_id' => $server->id,
            'plan_id' => $planId,
            'subnet_hash' => TrialFingerprint::subnet($ip),
            'signup_subnet_hash' => $this->signupNetwork($user),
            'discord_hash' => $identities['discord_hash'] ?? null,
            'google_hash' => $identities['google_hash'] ?? null,
            'hours' => $hours,
            'ends_at' => CarbonImmutable::now()->addHours($hours),
        ]);
    }

    /**
     * What keeping a trial server costs for each enabled billing period: the plan on the
     * server's node, without add-ons. Empty when the plan is gone.
     *
     * @return list<array{period_id: int, name: string, days: int, price_tokens: int}>
     */
    public function options(ServerSubscription $subscription): array
    {
        $plan = $subscription->plan;
        $node = $subscription->server->node;
        if ($plan === null) {
            return [];
        }

        return array_values(ShopPeriod::query()->ordered()->where('enabled', true)->get()
            ->map(fn (ShopPeriod $period): array => [
                'period_id' => $period->id,
                'name' => $period->name,
                'days' => $period->days,
                'price_tokens' => $this->pricing->quote($plan, $period, $node, [])->totalPerPeriod,
            ])
            ->all());
    }

    /**
     * Keeps a trial server by paying its first period ("Keep this server").
     *
     * @throws SubscriptionRenewalException
     */
    public function convert(ServerSubscription $subscription, int $periodId, ?int $expectedTotal, SubscriptionService $subscriptions): void
    {
        throw_unless($subscription->is_trial, SubscriptionRenewalException::class, 'This server is not a free trial any more.');

        $plan = $subscription->plan;
        throw_if($plan === null, SubscriptionRenewalException::class, 'This plan is not sold any more. Please contact support to keep this server.');

        $period = ShopPeriod::query()->whereKey($periodId)->where('enabled', true)->first();
        throw_unless($period instanceof ShopPeriod, SubscriptionRenewalException::class, 'That billing period is not offered.');

        try {
            $quote = $this->pricing->quote($plan, $period, $subscription->server->node, []);
        } catch (InvalidQuoteException $invalidQuoteException) {
            throw new SubscriptionRenewalException($invalidQuoteException->getMessage(), $invalidQuoteException);
        }

        throw_if(
            $expectedTotal !== null && $expectedTotal !== $quote->totalPerPeriod,
            SubscriptionRenewalException::class,
            sprintf('The price changed to %s tokens. Please check and confirm again.', number_format($quote->totalPerPeriod)),
        );

        $subscriptions->convertTrial($subscription, $period, $quote);
    }

    /**
     * The trial numbers for Shop > Trials: started, running, kept (converted), ended without
     * being kept, and the conversion rate (kept / trials that are over).
     *
     * @return array{started: int, running: int, converted: int, lapsed: int, conversion_rate: float}
     */
    public function stats(): array
    {
        $started = ShopTrial::query()->count();
        $converted = ShopTrial::query()->whereNotNull('converted_at')->count();
        $over = ShopTrial::query()->where(fn (Builder $query) => $query->whereNotNull('converted_at')
            ->orWhereNotNull('expired_at')
            ->orWhereNull('server_id'))->count();

        return [
            'started' => $started,
            'running' => $this->running(),
            'converted' => $converted,
            'lapsed' => $over - $converted,
            'conversion_rate' => $over === 0 ? 0.0 : round($converted / $over * 100, 1),
        ];
    }

    /**
     * Whether the account ever paid for a shop server (a server charge in the ledger) or has a
     * shop server now.
     */
    private function hasBought(User $user): bool
    {
        return TokenTransaction::query()->where('user_id', $user->id)->where('type', SubscriptionService::CHARGE_TYPE)->exists()
            || ServerSubscription::query()->where('user_id', $user->id)->exists();
    }

    /** The hashed network the account signed up from (read fresh: a loaded user may not have it). */
    private function signupNetwork(User $user): ?string
    {
        $hash = User::query()->whereKey($user->id)->value('signup_subnet_hash');

        return is_string($hash) && $hash !== '' ? $hash : null;
    }
}
