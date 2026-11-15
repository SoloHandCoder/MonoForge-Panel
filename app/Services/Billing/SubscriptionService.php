<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Contracts\Servers\DeletesServers;
use Pterodactyl\Contracts\Servers\TogglesServerSuspension;
use Pterodactyl\Exceptions\Billing\InsufficientTokensException;
use Pterodactyl\Exceptions\Billing\SubscriptionRenewalException;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Billing\ServerSubscription;
use Pterodactyl\Models\Billing\ShopPeriod;
use Pterodactyl\Models\Billing\ShopPlan;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Tunnels\TunnelService;
use Throwable;

/**
 * Bills shop servers in tokens. Every period is charged at most once (the wallet reference is
 * the subscription and the start of the period), a failed renewal suspends the server with
 * the existing suspension action, and the server is deleted with the existing deletion action
 * once the admin's grace period is over (0 days deletes it at the failed renewal).
 *
 * Every method locks the subscription row before it changes it, so the scheduler and a user's
 * "Renew now" can run at the same time, and every scheduler step is safe to run again.
 */
class SubscriptionService
{
    public const string REFERENCE_TYPE = 'server_subscription';

    public const string CHARGE_TYPE = 'server_charge';

    /**
     * A deletion that Wings refuses (node offline) is retried by the scheduler, and forced
     * (the panel records go, the node keeps the files) this long after `delete_at`, like an
     * admin who force deletes a server on a dead node.
     */
    public const int FORCE_DELETE_AFTER_HOURS = 24;

    private const string PAID = 'paid';

    private const string SKIPPED = 'skipped';

    private const string INSUFFICIENT = 'insufficient';

    private const string SUSPENDED = 'suspended';

    /** The renewal at `next_renewal_at` is due. */
    private const string MODE_DUE = 'due';

    /** A suspended subscription pays its unpaid renewal; the new period starts now. */
    private const string MODE_OVERDUE = 'overdue';

    /** "Renew now" on an active subscription; at most one period can be paid ahead. */
    private const string MODE_EARLY = 'early';

    public function __construct(
        private readonly TokenWallet $wallet,
        private readonly TogglesServerSuspension $suspension,
        private readonly DeletesServers $deletion,
        private readonly BillingNotifier $notifier,
        private readonly TunnelService $tunnels,
    ) {}

    /**
     * Starts billing a server bought in the shop. The caller has already paid the first period,
     * so the first renewal is one period from now.
     *
     * @param  array<array-key, mixed>  $addons  `[key => units]` as given to PricingService::quote(), or `[{key, units}]`
     */
    public function start(Server $server, User $user, ?ShopPlan $plan, ShopPeriod $period, Quote $quote, array $addons): ServerSubscription
    {
        $days = max(1, $quote->periodDays);

        $subscription = ServerSubscription::query()->firstOrNew(['server_id' => $server->id]);
        $subscription->fill([
            'user_id' => $user->id,
            'plan_id' => $plan?->getKey(),
            'period_id' => $period->getKey(),
            'period_days' => $days,
            'price_tokens' => max(1, $quote->totalPerPeriod),
            'addons' => $this->normalizeAddons($addons),
            'status' => ServerSubscription::STATUS_ACTIVE,
            'next_renewal_at' => CarbonImmutable::now()->addDays($days),
            'suspended_at' => null,
            'delete_at' => null,
            'auto_renew' => true,
        ])->save();

        return $subscription;
    }

    /**
     * Charges the renewal that is due, or suspends the subscription when the balance is short or
     * auto-renew is off. The charge and the suspension happen under the same row lock, so a
     * "Renew now" paid in between is seen and a paid server is never suspended.
     * Returns true when it is paid (or nothing is due yet), false when it was suspended.
     */
    public function renew(ServerSubscription $s): bool
    {
        $grace = ShopSettings::graceDays();

        $result = DB::transaction(function () use ($s, $grace): string {
            $locked = $this->lock($s);
            if (! $locked instanceof ServerSubscription || ! $this->chargeable($locked, self::MODE_DUE)) {
                return self::SKIPPED;
            }

            // auto_renew is read from the locked row, not from the model the scheduler loaded.
            if ($locked->auto_renew) {
                $result = $this->chargeLocked($s, $locked, self::MODE_DUE);
                if ($result !== self::INSUFFICIENT) {
                    return $result;
                }
            }

            return $this->markSuspended($s, $locked, $grace) ? self::SUSPENDED : self::SKIPPED;
        });

        if ($result === self::SUSPENDED) {
            $this->afterSuspension($s, $grace);

            return false;
        }

        return $result === self::PAID || ($s->fresh()?->isActive() ?? false);
    }

    /**
     * The user's "Renew now": pays a suspended subscription's unpaid renewal (and unsuspends
     * the server), or pays the next period of an active one ahead of time.
     *
     * @throws SubscriptionRenewalException
     */
    public function renewNow(ServerSubscription $s): void
    {
        if ($s->isSuspended()) {
            throw_unless($this->unsuspendIfPaid($s), SubscriptionRenewalException::class, $this->shortMessage($s));

            return;
        }

        $result = $this->charge($s, self::MODE_EARLY);

        throw_if($result === self::INSUFFICIENT, SubscriptionRenewalException::class, $this->shortMessage($s));
        throw_if($result === self::SKIPPED, SubscriptionRenewalException::class, 'This server is already paid for the next period.');

        $this->notifier->clear($s);
    }

    /**
     * Suspends the server of an unpaid subscription and starts the countdown to its deletion.
     * With a grace period of 0 days the server is deleted right away.
     */
    public function suspend(ServerSubscription $s): void
    {
        $grace = ShopSettings::graceDays();

        $suspended = DB::transaction(function () use ($s, $grace): bool {
            $locked = $this->lock($s);

            // Re-checked under the lock: a renewal paid since the caller loaded the model has
            // moved `next_renewal_at` on (or charged this period), and then the server stays.
            return $locked instanceof ServerSubscription
                && $this->chargeable($locked, self::MODE_DUE)
                && $this->markSuspended($s, $locked, $grace);
        });

        if ($suspended) {
            $this->afterSuspension($s, $grace);
        }
    }

    /**
     * Pays a suspended subscription's unpaid renewal from the balance and unsuspends its server.
     * False when it is not suspended or the balance is still short.
     */
    public function unsuspendIfPaid(ServerSubscription $s): bool
    {
        if ($this->charge($s, self::MODE_OVERDUE) !== self::PAID) {
            return false;
        }

        $this->restoreServer($s);
        $this->notifier->restored($s);

        return true;
    }

    /**
     * Stops billing the server: no more renewals, suspensions or deletion. A server that billing
     * had suspended is unsuspended. The server itself stays.
     */
    public function cancel(ServerSubscription $s): void
    {
        $cancelled = DB::transaction(function () use ($s): bool {
            $locked = $this->lock($s);
            if (! $locked instanceof ServerSubscription || $locked->status === ServerSubscription::STATUS_CANCELLED) {
                return false;
            }

            $locked->fill(['status' => ServerSubscription::STATUS_CANCELLED, 'auto_renew' => false, 'delete_at' => null])->save();
            $s->setRawAttributes($locked->getAttributes(), true);

            return true;
        });

        if ($cancelled) {
            $this->restoreServer($s);
            $this->notifier->clear($s);
        }
    }

    /**
     * Gives a subscription free days (admin): moves the next renewal later, and a suspended
     * subscription is active again from now.
     *
     * @throws SubscriptionRenewalException
     */
    public function extend(ServerSubscription $s, int $days): void
    {
        throw_if($days < 1, SubscriptionRenewalException::class, 'Extend by at least one day.');

        $wasSuspended = DB::transaction(function () use ($s, $days): bool {
            $locked = $this->lock($s);
            throw_if(! $locked instanceof ServerSubscription || $locked->status === ServerSubscription::STATUS_CANCELLED, SubscriptionRenewalException::class, 'A cancelled subscription cannot be extended.');

            $wasSuspended = $locked->isSuspended();
            $from = $wasSuspended ? CarbonImmutable::now() : $locked->next_renewal_at;
            $locked->fill([
                'status' => ServerSubscription::STATUS_ACTIVE,
                'next_renewal_at' => $from->addDays($days),
                'delete_at' => null,
            ])->save();
            $s->setRawAttributes($locked->getAttributes(), true);

            return $wasSuspended;
        });

        $this->notifier->clear($s);
        if ($wasSuspended) {
            $this->restoreServer($s);
        }
    }

    /**
     * Deletes the servers of suspended subscriptions whose `delete_at` has passed, through the
     * existing server deletion action. Returns how many were deleted.
     */
    public function deleteExpired(): int
    {
        $deleted = 0;

        $this->each(
            ServerSubscription::query()
                ->where('status', ServerSubscription::STATUS_SUSPENDED)
                ->where('delete_at', '<=', CarbonImmutable::now()),
            function (ServerSubscription $subscription) use (&$deleted): void {
                if ($this->deleteOne($subscription)) {
                    $deleted++;
                }
            },
        );

        return $deleted;
    }

    /**
     * Renews (or, with auto-renew off, suspends) every active subscription that is due.
     */
    public function renewDue(): int
    {
        $processed = 0;

        $this->each(
            ServerSubscription::query()
                ->where('status', ServerSubscription::STATUS_ACTIVE)
                ->where('next_renewal_at', '<=', CarbonImmutable::now()),
            function (ServerSubscription $subscription) use (&$processed): void {
                // renew() reads auto_renew under the row lock and suspends when it is off.
                $this->renew($subscription);

                $processed++;
            },
        );

        return $processed;
    }

    /**
     * Pays suspended subscriptions with auto-renew on once the balance covers them again (after
     * a top-up), which unsuspends their servers.
     */
    public function collectOverdue(): int
    {
        $restored = 0;

        $this->each(
            ServerSubscription::query()
                ->with('user')
                ->where('status', ServerSubscription::STATUS_SUSPENDED)
                ->where('auto_renew', true),
            function (ServerSubscription $subscription) use (&$restored): void {
                if ($this->wallet->balance($subscription->user) >= $subscription->price_tokens && $this->unsuspendIfPaid($subscription)) {
                    $restored++;
                }
            },
        );

        return $restored;
    }

    /**
     * Retries what Wings refused earlier: suspending the server of a suspended subscription whose
     * suspension never reached the server, and unsuspending the server of one that was paid (or
     * cancelled) since. A server billing did suspend and an admin then unsuspended by hand
     * (`server_suspended_at` is set) is left alone; the admin extends or cancels the subscription.
     */
    public function syncServerStates(): int
    {
        $synced = 0;

        $this->each(
            ServerSubscription::query()
                ->where('status', ServerSubscription::STATUS_SUSPENDED)
                ->where('delete_at', '>', CarbonImmutable::now())
                ->whereNull('server_suspended_at')
                ->whereHas('server', fn (Builder $query): Builder => $query->where(fn (Builder $state): Builder => $state->whereNull('status')->orWhere('status', '!=', Server::STATUS_SUSPENDED))),
            function (ServerSubscription $subscription) use (&$synced): void {
                if ($this->suspendServer($subscription)) {
                    $synced++;
                }
            },
        );

        $this->each(
            ServerSubscription::query()
                ->where('status', '!=', ServerSubscription::STATUS_SUSPENDED)
                ->whereNotNull('suspended_at'),
            function (ServerSubscription $subscription) use (&$synced): void {
                if ($this->restoreServer($subscription)) {
                    $synced++;
                }
            },
        );

        return $synced;
    }

    /**
     * Warns users whose balance will not cover their renewals in the next `low_balance_days`
     * days. Renewals are counted in order, so with two servers due, the one whose renewal the
     * balance no longer covers is the one warned about.
     */
    public function warnUpcoming(): int
    {
        $warned = 0;
        $window = CarbonImmutable::now()->addDays(ShopSettings::lowBalanceDays());

        ServerSubscription::query()
            ->with(['user', 'server'])
            ->where('status', ServerSubscription::STATUS_ACTIVE)
            ->where('auto_renew', true)
            ->where('next_renewal_at', '<=', $window)
            ->oldest('next_renewal_at')
            ->orderBy('id')
            ->get()
            ->groupBy('user_id')
            ->each(function (Collection $subscriptions) use (&$warned): void {
                $first = $subscriptions->first();
                if (! $first instanceof ServerSubscription) {
                    return;
                }

                try {
                    $balance = $this->wallet->balance($first->user);
                } catch (Throwable $throwable) {
                    report($throwable);

                    return;
                }

                $due = 0;
                foreach ($subscriptions as $subscription) {
                    $due += $subscription->price_tokens;
                    if ($due > $balance && $this->notifier->renewalShort($subscription, $balance)) {
                        $warned++;
                    }
                }
            });

        return $warned;
    }

    /**
     * Warns about a deletion about a day before it happens. With a grace period of a day or less
     * the suspension notice already said so, so it is not repeated.
     */
    public function warnDeletionSoon(): int
    {
        $warned = 0;
        $now = CarbonImmutable::now();

        $this->each(
            ServerSubscription::query()
                ->with(['user', 'server'])
                ->where('status', ServerSubscription::STATUS_SUSPENDED)
                ->where('delete_at', '>', $now)
                ->where('delete_at', '<=', $now->addDay()),
            function (ServerSubscription $subscription) use (&$warned): void {
                $deleteAt = $subscription->delete_at;
                $suspendedAt = $subscription->suspended_at;
                if ($deleteAt === null || ($suspendedAt !== null && $suspendedAt->greaterThanOrEqualTo($deleteAt->subDay()))) {
                    return;
                }

                if ($this->notifier->deletionSoon($subscription)) {
                    $warned++;
                }
            },
        );

        return $warned;
    }

    public function graceDays(): int
    {
        return ShopSettings::graceDays();
    }

    /**
     * Charges one period inside a transaction that holds the subscription row, so concurrent
     * runs serialise and see each other's result.
     */
    private function charge(ServerSubscription $s, string $mode): string
    {
        return DB::transaction(function () use ($s, $mode): string {
            $locked = $this->lock($s);
            if (! $locked instanceof ServerSubscription || ! $this->chargeable($locked, $mode)) {
                return self::SKIPPED;
            }

            return $this->chargeLocked($s, $locked, $mode);
        });
    }

    /**
     * Charges one period of a subscription row that the caller holds locked in its transaction.
     */
    private function chargeLocked(ServerSubscription $s, ServerSubscription $locked, string $mode): string
    {
        $now = CarbonImmutable::now();
        $key = $locked->periodKey();

        // The period was charged already (a run that died before saving): only move on.
        if (! $this->wallet->hasTransaction(self::REFERENCE_TYPE, $key, self::CHARGE_TYPE)) {
            try {
                $this->wallet->debit(
                    $locked->user,
                    $locked->price_tokens,
                    self::CHARGE_TYPE,
                    "Renewal of {$locked->server->name} ({$locked->period_days} days)",
                    self::REFERENCE_TYPE,
                    $key,
                    ['server_id' => $locked->server_id, 'period_days' => $locked->period_days],
                );
            } catch (InsufficientTokensException) {
                return self::INSUFFICIENT;
            }
        }

        $next = $mode === self::MODE_OVERDUE ? $now->addDays($locked->period_days) : $locked->next_renewal_at->addDays($locked->period_days);
        // A scheduler that was down for longer than a period charges once, from now on.
        if ($next->lessThanOrEqualTo($now)) {
            $next = $now->addDays($locked->period_days);
        }

        $locked->fill(['status' => ServerSubscription::STATUS_ACTIVE, 'next_renewal_at' => $next, 'delete_at' => null])->save();
        $s->setRawAttributes($locked->getAttributes(), true);

        return self::PAID;
    }

    /**
     * Marks a locked, due subscription as suspended, unless its current period was charged
     * already. The server itself is suspended (or deleted) by afterSuspension() once the
     * transaction has committed.
     */
    private function markSuspended(ServerSubscription $s, ServerSubscription $locked, int $grace): bool
    {
        if ($this->wallet->hasTransaction(self::REFERENCE_TYPE, $locked->periodKey(), self::CHARGE_TYPE)) {
            return false;
        }

        $now = CarbonImmutable::now();
        $locked->fill([
            'status' => ServerSubscription::STATUS_SUSPENDED,
            'suspended_at' => $now,
            'server_suspended_at' => null,
            'suspended_by_billing' => false,
            'delete_at' => $now->addDays($grace),
        ])->save();
        $s->setRawAttributes($locked->getAttributes(), true);

        return true;
    }

    /**
     * Suspends the server of a subscription that was just marked suspended and tells the user,
     * or deletes it right away with a grace period of 0 days.
     */
    private function afterSuspension(ServerSubscription $s, int $grace): void
    {
        if ($grace === 0) {
            $this->deleteOne($s);

            return;
        }

        $this->suspendServer($s);
        $this->notifier->suspended($s);
    }

    private function chargeable(ServerSubscription $s, string $mode): bool
    {
        $now = CarbonImmutable::now();

        return match ($mode) {
            self::MODE_DUE => $s->isActive() && $s->next_renewal_at->lessThanOrEqualTo($now),
            self::MODE_OVERDUE => $s->isSuspended(),
            self::MODE_EARLY => $s->isActive() && $s->next_renewal_at->lessThanOrEqualTo($now->addDays($s->period_days)),
            default => false,
        };
    }

    private function lock(ServerSubscription $s): ?ServerSubscription
    {
        return ServerSubscription::query()->with(['user', 'server'])->whereKey($s->getKey())->lockForUpdate()->first();
    }

    /**
     * Deletes one expired subscription's server while holding its row, so a renewal paid at
     * the same moment either lands first (and the server stays) or finds the row gone.
     */
    private function deleteOne(ServerSubscription $s): bool
    {
        $deleted = DB::transaction(function () use ($s): ?array {
            $locked = $this->lock($s);
            $deleteAt = $locked?->delete_at;
            if (! $locked instanceof ServerSubscription || ! $locked->isSuspended() || $deleteAt === null || $deleteAt->isFuture()) {
                return null;
            }

            $server = $locked->server;
            $this->releaseTunnel($server);

            try {
                $this->deletion->withForce($deleteAt->addHours(self::FORCE_DELETE_AFTER_HOURS)->isPast())->delete($server);
            } catch (DaemonConnectionException $daemonConnectionException) {
                Log::warning('Could not delete the unpaid shop server yet; retrying from the scheduler.', [
                    'server' => $server->uuid,
                    'exception' => $daemonConnectionException->getMessage(),
                ]);

                return null;
            }

            return ['user' => $locked->user, 'id' => $locked->id, 'name' => $server->name, 'uuid' => $server->uuid];
        });

        if ($deleted === null) {
            return false;
        }

        Log::info('Deleted an unpaid shop server after its grace period.', ['server' => $deleted['uuid'], 'subscription' => $deleted['id']]);
        $this->notifier->deleted($deleted['user'], $deleted['id'], $deleted['name']);

        return true;
    }

    /**
     * Suspends the server in the panel and on Wings. A refusal (node offline, transfer running)
     * is reported and retried by syncServerStates().
     *
     * A server that is already suspended (an administrator suspended it, for abuse say) is not
     * claimed: `suspended_by_billing` stays false, so a later payment never lifts that suspension.
     */
    private function suspendServer(ServerSubscription $s): bool
    {
        try {
            $server = $s->server()->firstOrFail();
            $claimed = ! $server->isSuspended();
            if ($claimed) {
                $this->suspension->toggle($server, TogglesServerSuspension::ACTION_SUSPEND);
            }

            $s->forceFill(['server_suspended_at' => CarbonImmutable::now(), 'suspended_by_billing' => $claimed])->save();

            return true;
        } catch (Throwable $throwable) {
            report($throwable);

            return false;
        }
    }

    /**
     * Ends billing's suspension (`suspended_at` is set until this succeeds). The server is only
     * unsuspended when billing's own suspension changed it (`suspended_by_billing`); a server
     * someone else suspended stays suspended.
     */
    private function restoreServer(ServerSubscription $s): bool
    {
        if ($s->suspended_at === null) {
            return false;
        }

        try {
            if ($s->suspended_by_billing) {
                $this->suspension->toggle($s->server()->firstOrFail(), TogglesServerSuspension::ACTION_UNSUSPEND);
            }

            $s->forceFill(['suspended_at' => null, 'server_suspended_at' => null, 'suspended_by_billing' => false])->save();

            return true;
        } catch (Throwable $throwable) {
            report($throwable);

            return false;
        }
    }

    private function releaseTunnel(Server $server): void
    {
        try {
            $this->tunnels->release($server);
        } catch (Throwable $throwable) {
            // A tunnel left behind must not keep an unpaid server alive.
            report($throwable);
        }
    }

    private function shortMessage(ServerSubscription $s): string
    {
        return "You need {$s->price_tokens} tokens to renew this server. Top up your wallet and try again.";
    }

    /**
     * Runs the callback for every subscription of the query, one at a time; a failure is
     * reported and the rest still run.
     *
     * @param  Builder<ServerSubscription>  $query
     * @param  callable(ServerSubscription): void  $callback
     */
    private function each(Builder $query, callable $callback): void
    {
        foreach ($query->lazyById(100) as $subscription) {
            try {
                $callback($subscription);
            } catch (Throwable $throwable) {
                report($throwable);
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $addons
     * @return list<array{key: string, units: int}>
     */
    private function normalizeAddons(array $addons): array
    {
        $list = [];

        foreach ($addons as $key => $value) {
            [$addon, $units] = is_array($value) ? [$value['key'] ?? null, $value['units'] ?? 0] : [$key, $value];

            if (is_string($addon) && $addon !== '' && is_numeric($units) && (int) $units > 0) {
                $list[] = ['key' => $addon, 'units' => (int) $units];
            }
        }

        return $list;
    }
}
