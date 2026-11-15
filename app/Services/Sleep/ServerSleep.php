<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Sleep;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Contracts\Servers\ReadsServerStates;
use Pterodactyl\Contracts\Servers\WakesServers;
use Pterodactyl\Data\ServerState;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Facades\Daemon;
use Pterodactyl\Models\Billing\ServerSleepSession;
use Pterodactyl\Models\Billing\ServerSubscription;
use Pterodactyl\Models\Billing\ShopPlan;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Query\QueryStatus;
use Pterodactyl\Services\Query\ServerQuery;
use Throwable;

/**
 * Pay-as-you-play. A shop server whose plan allows it and whose owner turned auto-sleep on is
 * stopped gracefully once nobody has played on it for the plan's idle minutes, and it sleeps
 * until anybody starts it again (the panel, a schedule, the console or "Wake server"). Every
 * sleep is recorded with the plan's discount at that moment, and the renewal discounts the time
 * the server slept in the period at those recorded discounts.
 *
 * The scheduler calls check() once a minute: one batched Wings state read and one player query
 * per running server, so it stays cheap.
 */
class ServerSleep implements WakesServers
{
    /**
     * Right after the graceful stop Wings may still report "running" while the game saves. Only
     * after this long does "running" mean somebody started the server again ("starting" always does).
     */
    public const int SETTLE_SECONDS = 180;

    /** Two wake requests close together (the button pressed twice) send one start. */
    private const int WAKE_LOCK_SECONDS = 30;

    public function __construct(
        private readonly ReadsServerStates $states,
        private readonly ServerQuery $query,
    ) {}

    public function isSleeping(Server $server): bool
    {
        return $this->subscriptionOf($server)?->isSleeping() ?? false;
    }

    public function wake(Server $server): bool
    {
        if (! $this->isSleeping($server) || ! $this->canStart($server)) {
            return false;
        }

        $lock = Cache::lock("server-sleep:wake:{$server->id}", self::WAKE_LOCK_SECONDS);
        if (! $lock->get()) {
            // Another request is starting it right now.
            return true;
        }

        try {
            Daemon::server($server)->power('start');
        } catch (DaemonConnectionException $daemonConnectionException) {
            $lock->release();
            Log::warning('Could not wake a sleeping server.', ['server' => $server->uuid, 'exception' => $daemonConnectionException->getMessage()]);

            return false;
        }

        $this->markAwake($server);
        Activity::event('server:sleep.wake')->subject($server)->log();

        return true;
    }

    /**
     * Ends the server's sleep, whoever started it. Safe to call for any server: one cheap
     * update when it was not sleeping. True when it was.
     */
    public function markAwake(Server $server, ?CarbonImmutable $at = null): bool
    {
        $at ??= CarbonImmutable::now();

        return DB::transaction(function () use ($server, $at): bool {
            $woken = ServerSubscription::query()
                ->where('server_id', $server->id)
                ->whereNotNull('sleep_started_at')
                ->update(['sleep_started_at' => null, 'idle_checks' => 0]);

            ServerSleepSession::query()
                ->where('server_id', $server->id)
                ->whereNull('ended_at')
                ->update(['ended_at' => $at]);

            return $woken > 0;
        });
    }

    /**
     * Whether auto-sleep can work for the subscription's server: its plan allows it and its game
     * tells who is online (Minecraft Java for now).
     */
    public function available(ServerSubscription $subscription): bool
    {
        return $this->allowed($subscription) && ServerQuery::supports($subscription->server);
    }

    /** Whether the plan lets the server sleep (the game may still be unsupported). */
    public function allowed(ServerSubscription $subscription): bool
    {
        $plan = $subscription->plan;

        return $plan instanceof ShopPlan && $plan->sleep_allowed;
    }

    /**
     * The scheduler's pass: ends the sleep of servers somebody started from the console, and
     * puts servers that nobody played on for long enough to sleep.
     *
     * @return array{asleep: int, awake: int}
     */
    public function check(): array
    {
        $counts = ['asleep' => 0, 'awake' => 0];

        ServerSubscription::query()
            ->with(['server.node', 'server.egg', 'server.allocation', 'plan'])
            ->where('status', ServerSubscription::STATUS_ACTIVE)
            ->where(fn (Builder $query): Builder => $query->where('auto_sleep', true)->orWhereNotNull('sleep_started_at')->orWhere('idle_checks', '>', 0))
            ->chunkById(100, function (Collection $subscriptions) use (&$counts): void {
                $servers = [];
                foreach ($subscriptions as $subscription) {
                    $servers[] = $subscription->server;
                }

                try {
                    $states = $this->states->read($servers);
                } catch (Throwable $throwable) {
                    report($throwable);

                    return;
                }

                foreach ($subscriptions as $subscription) {
                    $state = $states[$subscription->server->uuid] ?? null;

                    try {
                        $result = $this->checkOne($subscription, $state instanceof ServerState ? $state : null);
                        if ($result !== null) {
                            $counts[$result]++;
                        }
                    } catch (Throwable $throwable) {
                        report($throwable);
                    }
                }
            });

        return $counts;
    }

    /**
     * Gracefully stops an idle server and marks it sleeping. False when it was not stopped
     * (Wings refused, or it is not eligible any more).
     */
    public function sleep(ServerSubscription $subscription): bool
    {
        $server = $subscription->server;
        if (! $subscription->isActive() || $subscription->sleep_started_at !== null || ! $this->canStart($server)) {
            return false;
        }

        try {
            Daemon::server($server)->power('stop');
        } catch (DaemonConnectionException $daemonConnectionException) {
            Log::warning('Could not put an idle server to sleep.', ['server' => $server->uuid, 'exception' => $daemonConnectionException->getMessage()]);

            return false;
        }

        $now = CarbonImmutable::now();
        $minutes = $subscription->plan?->sleepIdleMinutes() ?? ShopPlan::DEFAULT_SLEEP_IDLE_MINUTES;

        // Lock in the plan's discount for this sleep: a later change to the plan does not reprice it.
        $discount = $subscription->plan?->sleepDiscountPercent() ?? 0;

        $marked = DB::transaction(function () use ($subscription, $now, $discount): bool {
            $updated = ServerSubscription::query()
                ->whereKey($subscription->getKey())
                ->where('status', ServerSubscription::STATUS_ACTIVE)
                ->whereNull('sleep_started_at')
                ->update(['sleep_started_at' => $now, 'idle_checks' => 0]);

            if ($updated === 0) {
                return false;
            }

            ServerSleepSession::query()->create(['server_id' => $subscription->server_id, 'started_at' => $now, 'discount_percent' => $discount]);

            return true;
        });

        $subscription->forceFill(['sleep_started_at' => $marked ? $now : $subscription->sleep_started_at, 'idle_checks' => 0])->syncOriginal();

        if ($marked) {
            Activity::event('server:sleep.asleep')->subject($server)->property('minutes', $minutes)->log();
        }

        return $marked;
    }

    /**
     * The seconds the server slept between the two moments. A sleep that has not ended counts
     * until now; overlapping sessions (which should not exist) are counted once.
     */
    public function sleptSeconds(int $serverId, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return array_sum($this->sleptSecondsByDiscount($serverId, $from, $to));
    }

    /**
     * The seconds slept between the two moments, keyed by the discount percent each sleep
     * recorded when it started. Overlaps are counted once, for the sleep that started first.
     *
     * @return array<int, int>
     */
    public function sleptSecondsByDiscount(int $serverId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $now = CarbonImmutable::now();
        if ($to->greaterThan($now)) {
            $to = $now;
        }

        if ($to->lessThanOrEqualTo($from)) {
            return [];
        }

        $intervals = ServerSleepSession::query()
            ->where('server_id', $serverId)
            ->where('started_at', '<', $to)
            ->where(fn (Builder $query): Builder => $query->whereNull('ended_at')->orWhere('ended_at', '>', $from))
            ->oldest('started_at')
            ->get()
            ->map(fn (ServerSleepSession $session): array => [
                max($from->getTimestamp(), $session->started_at->getTimestamp()),
                min($to->getTimestamp(), ($session->ended_at ?? $now)->getTimestamp()),
                max(0, min(100, $session->discount_percent)),
            ])
            ->filter(fn (array $interval): bool => $interval[1] > $interval[0])
            ->sortBy(fn (array $interval): int => $interval[0])
            ->values()
            ->all();

        $totals = [];
        $end = null;
        foreach ($intervals as [$start, $stop, $discount]) {
            if ($end !== null && $start < $end) {
                $start = $end;
            }

            if ($stop > $start) {
                $totals[$discount] = ($totals[$discount] ?? 0) + $stop - $start;
                $end = $stop;
            }
        }

        return $totals;
    }

    /**
     * The renewal charge of the period that ends at the subscription's `next_renewal_at` (sleep
     * so far when it is paid early), with what sleeping saved. Each sleep is discounted at the
     * percent recorded when it started (locked in), not at the plan's current percent.
     */
    public function bill(ServerSubscription $subscription): SleepBill
    {
        $price = $subscription->price_tokens;
        $periodSeconds = max(1, $subscription->period_days) * 86_400;
        $slept = $this->sleptSecondsByDiscount($subscription->server_id, $subscription->periodStart(), $subscription->next_renewal_at);
        $saved = SleepPricing::savedAtDiscounts($price, $periodSeconds, $slept);

        return new SleepBill($price, $price - $saved, $saved, array_sum($slept), $this->effectiveDiscount($slept));
    }

    /**
     * The discount to show for the period: the one percent its sleeps recorded, or their
     * time-weighted average (rounded) when the plan changed in between. 0 without sleep.
     *
     * @param  array<int, int>  $slept  seconds keyed by discount percent
     */
    private function effectiveDiscount(array $slept): int
    {
        $seconds = array_sum($slept);
        if ($seconds === 0) {
            return 0;
        }

        $weighted = 0;
        foreach ($slept as $discount => $part) {
            $weighted += $discount * $part;
        }

        return intdiv($weighted + intdiv($seconds, 2), $seconds);
    }

    /**
     * @return 'asleep'|'awake'|null what changed
     */
    private function checkOne(ServerSubscription $subscription, ?ServerState $state): ?string
    {
        $server = $subscription->server;

        if ($subscription->sleep_started_at !== null) {
            return $state instanceof ServerState && $this->startedAgain($subscription->sleep_started_at, $state) && $this->markAwake($server) ? 'awake' : null;
        }

        if (! $state instanceof ServerState || $state->state !== 'running' || ! $subscription->auto_sleep || ! $this->available($subscription) || ! $this->canStart($server)) {
            $this->resetIdle($subscription);

            return null;
        }

        $status = $this->query->status($server);
        if (! $status instanceof QueryStatus || $status->online > 0) {
            $this->resetIdle($subscription);

            return null;
        }

        $checks = $subscription->idle_checks + 1;
        if ($checks >= ($subscription->plan?->sleepIdleMinutes() ?? ShopPlan::DEFAULT_SLEEP_IDLE_MINUTES)) {
            return $this->sleep($subscription) ? 'asleep' : null;
        }

        $subscription->forceFill(['idle_checks' => $checks])->save();

        return null;
    }

    private function startedAgain(CarbonImmutable $sleepStartedAt, ServerState $state): bool
    {
        return $state->state === 'starting'
            || ($state->state === 'running' && $sleepStartedAt->lessThanOrEqualTo(CarbonImmutable::now()->subSeconds(self::SETTLE_SECONDS)));
    }

    private function resetIdle(ServerSubscription $subscription): void
    {
        if ($subscription->idle_checks !== 0) {
            $subscription->forceFill(['idle_checks' => 0])->save();
        }
    }

    /** A suspended, installing, restoring or transferring server is left alone. */
    private function canStart(Server $server): bool
    {
        return $server->status === null && ! $server->node->isUnderMaintenance() && $server->transfer === null;
    }

    private function subscriptionOf(Server $server): ?ServerSubscription
    {
        return ServerSubscription::query()->where('server_id', $server->id)->first();
    }
}
