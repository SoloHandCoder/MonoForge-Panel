<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Billing;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Contracts\Servers\CreatesServers;
use Pterodactyl\Contracts\Servers\DeletesServers;
use Pterodactyl\Data\CouponDiscount;
use Pterodactyl\Data\StorePurchaseOrder;
use Pterodactyl\Data\StorePurchaseResult;
use Pterodactyl\Data\StoreTrialOrder;
use Pterodactyl\Exceptions\Billing\CouponException;
use Pterodactyl\Exceptions\Billing\InsufficientTokensException;
use Pterodactyl\Exceptions\Billing\InvalidQuoteException;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Exceptions\Service\Billing\StorePurchaseException;
use Pterodactyl\Exceptions\Service\Deployment\NoViableAllocationException;
use Pterodactyl\Exceptions\Service\Deployment\NoViableNodeException;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Billing\CouponRedemption;
use Pterodactyl\Models\Billing\ServerSubscription;
use Pterodactyl\Models\Billing\ServerTunnel;
use Pterodactyl\Models\Billing\ShopAddon;
use Pterodactyl\Models\Billing\ShopPeriod;
use Pterodactyl\Models\Billing\ShopPlan;
use Pterodactyl\Models\Billing\TokenTransaction;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\EggVariable;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Notices\NoticeService;
use Pterodactyl\Services\Tunnels\TunnelService;
use Pterodactyl\Services\Tunnels\TunnelSettings;
use Throwable;

/**
 * Buys a server from the store: checks the order, quotes it, charges the first period,
 * deploys the server on a node the plan allows, starts its subscription and asks for a
 * playit tunnel. Anything that fails after the charge refunds it, and the player's
 * idempotency key makes a repeated request return the server it already bought.
 *
 * A discount coupon lowers the first period only (renewals are charged at the full price). It
 * is redeemed in the same transaction as the charge and given back with the refund.
 */
class StorePurchaseService
{
    /** The reference type of the charge and of its refund in the token ledger. */
    public const string REFERENCE_TYPE = 'store_purchase';

    /** The store-wide lock around the capacity check and the server creation. */
    public const string CAPACITY_LOCK = 'monoforge:store:capacity';

    /**
     * How long the capacity lock is held at most: longer than the worst case of a purchase
     * (three allocation attempts against a slow Wings plus the clean-up delete), so the lock
     * never expires while a server row is still being written. Waiting buyers give up after
     * `monoforge.shop.capacity_lock_wait` seconds instead.
     */
    private const int CAPACITY_LOCK_SECONDS = 180;

    /** How many free allocations are tried when another purchase takes the chosen one first. */
    private const int ALLOCATION_ATTEMPTS = 3;

    public function __construct(
        private readonly TokenWallet $wallet,
        private readonly PricingService $pricing,
        private readonly CapacityService $capacity,
        private readonly SubscriptionService $subscriptions,
        private readonly CreatesServers $creation,
        private readonly DeletesServers $deletion,
        private readonly TunnelService $tunnels,
        private readonly NoticeService $notices,
        private readonly TrialService $trials,
        private readonly CouponService $coupons,
    ) {}

    /**
     * The ledger reference and the server external id of an order: the player and their
     * idempotency key, hashed so neither length nor content of the key reaches the database.
     */
    public static function reference(User $user, string $idempotencyKey): string
    {
        return 'store-'.mb_substr(hash('sha256', $user->id.':'.$idempotencyKey), 0, 40);
    }

    /**
     * The external id of a free trial server (nothing is charged, so there is no ledger row):
     * the player and their idempotency key, hashed.
     */
    public static function trialReference(User $user, string $idempotencyKey): string
    {
        return 'trial-'.mb_substr(hash('sha256', 'trial:'.$user->id.':'.$idempotencyKey), 0, 40);
    }

    /**
     * @throws StorePurchaseException
     * @throws ValidationException
     */
    public function purchase(User $user, StorePurchaseOrder $order): StorePurchaseResult
    {
        $reference = self::reference($user, $order->idempotencyKey);

        if (($replay = $this->replay($user, $reference)) instanceof StorePurchaseResult) {
            return $replay;
        }

        $lock = Cache::lock('monoforge:store:purchase:'.$reference, 120);
        throw_unless($lock->get(), StorePurchaseException::inProgress());

        try {
            // A request that waited for the lock may find the purchase finished meanwhile.
            if (($replay = $this->replay($user, $reference)) instanceof StorePurchaseResult) {
                return $replay;
            }

            throw_if($this->wallet->hasTransaction(self::REFERENCE_TYPE, $reference, 'refund'), StorePurchaseException::alreadyFailed());
            throw_if($this->wallet->hasTransaction(self::REFERENCE_TYPE, $reference, 'server_charge'), StorePurchaseException::inProgress());

            return $this->buy($user, $order, $reference);
        } finally {
            $lock->release();
        }
    }

    /**
     * Starts the one free trial server of a player: the same checks and node choice as a
     * purchase (the plan must be trialable), nothing is charged, and the subscription runs in
     * the `trial` state until the trial ends. The anti-abuse checks are made again under the
     * store-wide capacity lock, so concurrent trial starts cannot pass them together.
     *
     * @throws StorePurchaseException
     */
    public function startTrial(User $user, StoreTrialOrder $order, ?string $ip): StorePurchaseResult
    {
        $reference = self::trialReference($user, $order->idempotencyKey);

        if (($replay = $this->replay($user, $reference)) instanceof StorePurchaseResult) {
            return $replay;
        }

        // One trial start per player at a time.
        $lock = Cache::lock('monoforge:store:trial:'.$user->id, 120);
        throw_unless($lock->get(), StorePurchaseException::inProgress());

        try {
            if (($replay = $this->replay($user, $reference)) instanceof StorePurchaseResult) {
                return $replay;
            }

            throw_unless(ShopSettings::enabled(), StorePurchaseException::disabled());
            $problem = $this->trials->problem($user, $ip);
            throw_if($problem !== null, StorePurchaseException::trialRefused((string) $problem));
            throw_unless(in_array($order->planId, TrialSettings::planIds(), true), StorePurchaseException::unavailable('That plan has no free trial.'));

            $plan = $this->sellablePlan($order->planId);
            [$egg, $image] = $this->orderEgg($plan, $order->eggId);

            $candidates = $this->candidateNodes($plan, $egg, $order->locationId, $order->nodeId);
            throw_if($candidates->isEmpty(), StorePurchaseException::noNode());

            // A trial is the plan as it is (no add-ons), and it takes capacity like a sale.
            $hours = TrialSettings::hours();
            $quote = new Quote(0, 1.0, 0, 0, max(1, (int) ceil($hours / 24)), [], $plan->resources());
            $candidates = $candidates->filter(fn (Node $node): bool => $this->capacity->canProvisionOn($node, $quote->resources))->values();
            throw_if($candidates->isEmpty(), StorePurchaseException::capacity());

            $node = $this->chooseNode($candidates);

            $result = $this->underCapacityLock(fn (): StorePurchaseResult => $this->createTrial($user, $order, $plan, $egg, $image, $node, $quote, $hours, $reference, $ip));
            $this->warnIfOversold($result);

            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * Checks capacity and the trial rules again, then creates the trial server and records the
     * trial. Called under the capacity lock. A failure removes the half-made server.
     *
     * @throws StorePurchaseException
     */
    private function createTrial(User $user, StoreTrialOrder $order, ShopPlan $plan, Egg $egg, string $image, Node $node, Quote $quote, int $hours, string $reference, ?string $ip): StorePurchaseResult
    {
        throw_unless(
            $this->capacity->canProvision($quote->resources) && $this->capacity->canProvisionOn($node, $quote->resources),
            StorePurchaseException::capacity(),
        );
        $problem = $this->trials->problem($user, $ip);
        throw_if($problem !== null, StorePurchaseException::trialRefused((string) $problem));

        $server = null;
        try {
            $server = $this->createServer($user, $order->name, sprintf('%s, free trial from the store.', $plan->name), $plan, $egg, $image, $node, $quote, $reference);
            DB::transaction(function () use ($server, $user, $plan, $hours, $ip): void {
                $this->subscriptions->startTrial($server, $user, $plan, $hours);
                $this->trials->record($user, $server, $plan->id, $hours, $ip);
            });
        } catch (Throwable $throwable) {
            report($throwable);
            if ($server instanceof Server) {
                try {
                    $this->deletion->withForce()->delete($server);
                } catch (Throwable $cleanup) {
                    report($cleanup);
                }
            }

            throw_if($throwable instanceof UniqueConstraintViolationException, StorePurchaseException::trialRefused('You have already used your free trial.'));

            throw StorePurchaseException::trialFailed(match (true) {
                $throwable instanceof NoViableNodeException, $throwable instanceof NoViableAllocationException => 'There is no free spot for it on that location right now.',
                $throwable instanceof DisplayException => $throwable->getMessage(),
                default => 'Please try again in a few minutes or contact support.',
            }, $throwable);
        }

        return new StorePurchaseResult($server, 0, 0, address: $this->provisionTunnel($server, null));
    }

    private function buy(User $user, StorePurchaseOrder $order, string $reference): StorePurchaseResult
    {
        throw_unless(ShopSettings::enabled(), StorePurchaseException::disabled());

        $plan = $this->sellablePlan($order->planId);
        $period = ShopPeriod::query()->whereKey($order->periodId)->where('enabled', true)->first()
            ?? throw StorePurchaseException::unavailable('That billing period is not offered.');

        [$egg, $image] = $this->orderEgg($plan, $order->eggId);

        $addons = $this->addons($order->addons);
        $subdomain = $this->subdomain($order->subdomain);

        $candidates = $this->candidateNodes($plan, $egg, $order->locationId, $order->nodeId);
        throw_if($candidates->isEmpty(), StorePurchaseException::noNode());

        // A custom subdomain needs a playit tunnel: refuse it rather than drop it after the charge.
        if ($subdomain !== null) {
            $candidates = $candidates->filter(fn (Node $node): bool => $node->hasPlayitAgent())->values();
            throw_if($candidates->isEmpty(), StorePurchaseException::unavailable('Custom subdomains are not available on that location. Leave the subdomain empty or pick another location.'));
        }

        // Resources do not depend on the node, only the price does, so any candidate sizes the order.
        $quote = $this->sellableQuote($plan, $period, $candidates->firstOrFail(), $addons);

        // Only the shop's limits decide where a server can be sold: each node's own shop limits
        // here, the shop-wide ones (and these again) under the capacity lock.
        $candidates = $candidates->filter(fn (Node $node): bool => $this->capacity->canProvisionOn($node, $quote->resources))->values();
        throw_if($candidates->isEmpty(), StorePurchaseException::capacity());

        $node = $this->chooseNode($candidates);
        if ($node->id !== $candidates->firstOrFail()->id) {
            $quote = $this->sellableQuote($plan, $period, $node, $addons);
        }

        $firstPeriod = $order->couponCode === null ? $quote->totalPerPeriod : $this->previewCoupon($user, $order->couponCode, $plan, $quote)->firstPeriod;
        throw_if($order->expectedTotal !== null && $order->expectedTotal !== $firstPeriod, StorePurchaseException::priceChanged($firstPeriod));

        // One purchase at a time from the capacity check to the created server, so concurrent
        // buyers cannot all pass the check and oversell the limits (or take the same subdomain).
        $result = $this->underCapacityLock(fn (): StorePurchaseResult => $this->charge($user, $order, $plan, $period, $egg, $image, $node, $quote, $addons, $subdomain, $reference));
        $this->warnIfOversold($result);

        return $result;
    }

    /** A visible plan in a visible category. */
    private function sellablePlan(int $planId): ShopPlan
    {
        $plan = ShopPlan::query()->with('category')->whereKey($planId)->where('visible', true)->first()
            ?? throw StorePurchaseException::unavailable('That plan is not for sale.');
        throw_unless($plan->category->visible, StorePurchaseException::unavailable('That plan is not for sale.'));

        return $plan;
    }

    /**
     * The game of the order, which the plan must offer, and its default image.
     *
     * @return array{Egg, string}
     */
    private function orderEgg(ShopPlan $plan, int $eggId): array
    {
        $allowedEggs = array_map(intval(...), (array) $plan->egg_ids);
        throw_unless(in_array($eggId, $allowedEggs, true), StorePurchaseException::unavailable('That game is not available on this plan.'));
        $egg = Egg::query()->with(['tags', 'variables'])->findOrFail($eggId);
        $image = collect($egg->docker_images)->first();
        throw_unless(is_string($image) && $image !== '', StorePurchaseException::unavailable('That game has no image to run on yet.'));

        return [$egg, $image];
    }

    /**
     * The coupon's discount on the first period, checked (not redeemed) before the charge.
     *
     * @throws StorePurchaseException
     */
    private function previewCoupon(User $user, string $code, ShopPlan $plan, Quote $quote): CouponDiscount
    {
        try {
            // Counted like a quote, so the purchase endpoint cannot be used to guess codes either.
            return $this->coupons->attempt($user, fn (): CouponDiscount => $this->coupons->preview($user, $code, $plan, $quote->totalPerPeriod));
        } catch (CouponException $couponException) {
            throw StorePurchaseException::unavailable($couponException->getMessage());
        }
    }

    /**
     * A safety net behind the lock: once the server is committed, the shop servers must still
     * fit the shop-wide limits and those of the server's node. If they do not (a lock that
     * expired, a limit lowered meanwhile), the server is kept and root administrators are
     * told, so they can raise the limit or move it.
     */
    private function warnIfOversold(StorePurchaseResult $result): void
    {
        try {
            $server = $result->server;
            $limits = ShopSettings::limits();
            $used = $this->capacity->used();
            if (! CapacityService::fits($limits, $used, [])) {
                Log::warning('The store sold past its capacity limits.', ['server' => $server->uuid, 'used' => $used, 'limits' => $limits]);
                $this->warnAdmins(sprintf(
                    'The store sold server "%s" past its limits: %s. The server was kept; raise the limits in Shop > Limits or move servers.',
                    $server->name,
                    $this->usage($used, $limits),
                ), 'Open shop limits', '/panel/shop/limits');
            }

            $node = $server->loadMissing('node')->node;
            $nodeLimits = CapacityService::nodeLimits($node);
            $nodeUsed = $this->capacity->usedOn($node->id);
            if (! CapacityService::fits($nodeLimits, $nodeUsed, [])) {
                Log::warning('The store sold past the capacity limits of a node.', ['server' => $server->uuid, 'node' => $node->id, 'used' => $nodeUsed, 'limits' => $nodeLimits]);
                $this->warnAdmins(sprintf(
                    'The store sold server "%s" past the shop limits of node "%s": %s. The server was kept; raise the node\'s limits in Shop > Nodes or move servers.',
                    $server->name,
                    $node->name,
                    $this->usage($nodeUsed, $nodeLimits),
                ), 'Open shop nodes', '/panel/shop/nodes');
            }
        } catch (Throwable $throwable) {
            // The purchase itself succeeded; a failed warning must not turn it into an error.
            report($throwable);
        }
    }

    /**
     * @param  array{memory: int, disk: int, cpu: int}  $used
     * @param  array{memory: int, disk: int, cpu: int}  $limits
     */
    private function usage(array $used, array $limits): string
    {
        return sprintf(
            '%s MB memory, %s MB disk and %s%% CPU are in use (limits %s, %s, %s; 0 = none)',
            number_format($used['memory']),
            number_format($used['disk']),
            number_format($used['cpu']),
            number_format($limits['memory']),
            number_format($limits['disk']),
            number_format($limits['cpu']),
        );
    }

    private function warnAdmins(string $body, string $action, string $url): void
    {
        User::query()->where('root_admin', true)->each(function (User $admin) use ($body, $action, $url): void {
            $this->notices->notify($admin, 'warning', 'Store over capacity', $body, null, $action, $url);
        });
    }

    /**
     * Runs the callback while holding the store-wide capacity lock, waiting a few seconds for it.
     *
     * @param  callable(): StorePurchaseResult  $callback
     *
     * @throws StorePurchaseException
     */
    private function underCapacityLock(callable $callback): StorePurchaseResult
    {
        $lock = Cache::lock(self::CAPACITY_LOCK, self::CAPACITY_LOCK_SECONDS);

        try {
            $lock->block(max(0, (int) config('monoforge.shop.capacity_lock_wait', 5)));
        } catch (LockTimeoutException) {
            throw StorePurchaseException::busy();
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * Checks capacity and the subdomain again, charges the first period, creates the server and
     * starts its subscription and tunnel. Called under the capacity lock.
     *
     * @param  array<string, int>  $addons
     *
     * @throws StorePurchaseException
     */
    private function charge(User $user, StorePurchaseOrder $order, ShopPlan $plan, ShopPeriod $period, Egg $egg, string $image, Node $node, Quote $quote, array $addons, ?string $subdomain, string $reference): StorePurchaseResult
    {
        throw_unless(
            $this->capacity->canProvision($quote->resources) && $this->capacity->canProvisionOn($node, $quote->resources),
            StorePurchaseException::capacity(),
        );
        // Taken by a purchase that held the lock before this one.
        $problem = $subdomain === null ? null : TunnelSettings::subdomainProblem($subdomain);
        throw_if($problem !== null, StorePurchaseException::unavailable((string) $problem));

        $discount = $this->chargeFirstPeriod($user, $order, $plan, $period, $node, $quote, $addons, $reference);
        $charged = $discount instanceof CouponDiscount ? $discount->firstPeriod : $quote->totalPerPeriod;

        $server = null;
        try {
            $server = $this->createServer($user, $order->name, sprintf('%s, bought in the store.', $plan->name), $plan, $egg, $image, $node, $quote, $reference);
            // The subscription renews at the full price: a coupon only lowers the first period.
            $this->subscriptions->start($server, $user, $plan, $period, $quote, $addons);
        } catch (Throwable $throwable) {
            $this->refund($user, $charged, $reference, $server, $throwable);
        }

        $warning = null;
        if ($discount instanceof CouponDiscount) {
            try {
                $this->coupons->attachServer($reference, $server);
            } catch (Throwable $throwable) {
                // The purchase is paid and the server exists; only the audit link is missing.
                report($throwable);
                Log::warning('A coupon redemption could not be linked to its server; the purchase stands.', ['reference' => $reference, 'server' => $server->uuid]);
                $warning = 'Your server was bought and is being set up. One last step did not finish; the panel carries on, and support can help if anything looks wrong.';
            }
        }

        return new StorePurchaseResult(
            $server,
            $charged,
            $quote->periodDays,
            address: $this->provisionTunnel($server, $subdomain),
            discountTokens: $discount instanceof CouponDiscount ? $discount->discount : 0,
            couponCode: $discount?->coupon->code,
            warning: $warning,
        );
    }

    /**
     * Redeems the order's coupon and debits the first period in one transaction, so a refused
     * charge (a short balance, a coupon that changed meanwhile) leaves the coupon unused.
     *
     * @param  array<string, int>  $addons
     *
     * @throws StorePurchaseException
     */
    private function chargeFirstPeriod(User $user, StorePurchaseOrder $order, ShopPlan $plan, ShopPeriod $period, Node $node, Quote $quote, array $addons, string $reference): ?CouponDiscount
    {
        try {
            return DB::transaction(function () use ($user, $order, $plan, $period, $node, $quote, $addons, $reference): ?CouponDiscount {
                $discount = null;
                if ($order->couponCode !== null) {
                    $discount = $this->coupons->redeemForPurchase($user, $order->couponCode, $plan, $quote->totalPerPeriod, $reference, $order->ip);
                    // The coupon was edited between the check and the lock: the player confirmed another price.
                    throw_if($order->expectedTotal !== null && $order->expectedTotal !== $discount->firstPeriod, StorePurchaseException::priceChanged($discount->firstPeriod));
                }

                $charge = $discount instanceof CouponDiscount ? $discount->firstPeriod : $quote->totalPerPeriod;
                $description = sprintf('Server "%s": %s, first %d days', $order->name, $plan->name, $quote->periodDays);
                $meta = ['plan_id' => $plan->id, 'period_id' => $period->id, 'node_id' => $node->id, 'addons' => $addons];
                if ($discount instanceof CouponDiscount) {
                    $description .= sprintf(' (coupon %s: %s tokens off)', $discount->coupon->code, number_format($discount->discount));
                    $meta += ['coupon_id' => $discount->coupon->id, 'coupon_discount' => $discount->discount, 'full_price' => $quote->totalPerPeriod];
                }

                try {
                    $this->wallet->debit($user, $charge, 'server_charge', $description, self::REFERENCE_TYPE, $reference, $meta);
                } catch (InsufficientTokensException $insufficientTokensException) {
                    throw StorePurchaseException::insufficient($charge, $this->wallet->balance($user), $insufficientTokensException);
                }

                return $discount;
            });
        } catch (CouponException $couponException) {
            throw StorePurchaseException::unavailable($couponException->getMessage());
        }
    }

    /**
     * The purchase an earlier request with the same key finished, if any.
     *
     * @phpstan-impure
     */
    private function replay(User $user, string $reference): ?StorePurchaseResult
    {
        $server = Server::query()->where('external_id', $reference)->where('owner_id', $user->id)->first();
        if (! $server instanceof Server) {
            return null;
        }

        $subscription = ServerSubscription::query()->where('server_id', $server->id)->first();
        // What the first period cost, which a coupon may have lowered below the renewal price.
        $charged = TokenTransaction::query()
            ->where('reference_type', self::REFERENCE_TYPE)
            ->where('reference_id', $reference)
            ->where('type', TokenTransaction::TYPE_SERVER_CHARGE)
            ->value('amount');
        $redemption = CouponRedemption::query()->with('coupon')->where('purchase_reference', $reference)->first();

        return new StorePurchaseResult(
            $server,
            is_numeric($charged) ? abs((int) $charged) : (int) ($subscription->price_tokens ?? 0),
            (int) ($subscription->period_days ?? 0),
            replayed: true,
            address: $this->tunnelAddress($server),
            discountTokens: $redemption->tokens ?? 0,
            couponCode: $redemption?->coupon->code,
        );
    }

    /**
     * The enabled add-ons the order asked for, by key, with units capped by each add-on.
     *
     * @param  array<string, int>  $requested
     * @return array<string, int>
     */
    private function addons(array $requested): array
    {
        $requested = array_filter($requested, fn (int $units): bool => $units > 0);
        if ($requested === []) {
            return [];
        }

        $available = ShopAddon::query()->where('enabled', true)->whereIn('key', array_keys($requested))->get()->keyBy('key');

        $addons = [];
        foreach ($requested as $key => $units) {
            $addon = $available->get($key);
            throw_unless($addon instanceof ShopAddon, StorePurchaseException::unavailable('One of the add-ons is not offered any more.'));
            throw_if($addon->max_units > 0 && $units > $addon->max_units, StorePurchaseException::unavailable(sprintf('You can add at most %d × %s.', $addon->max_units, $addon->name)));
            $addons[$key] = $units;
        }

        return $addons;
    }

    /**
     * The custom subdomain the player asked for, checked against the Tunnels settings (switch,
     * format, the admin's blocklist and names already taken).
     */
    private function subdomain(?string $subdomain): ?string
    {
        $subdomain = $subdomain === null ? '' : TunnelSettings::normalize($subdomain);
        if ($subdomain === '') {
            return null;
        }

        $problem = TunnelSettings::subdomainProblem($subdomain);
        throw_if($problem !== null, StorePurchaseException::unavailable((string) $problem));

        return $subdomain;
    }

    /**
     * Checks that the plan, period and node may be sold together (Task R's rules), then prices
     * the order on that node.
     *
     * @param  array<string, int>  $addons
     *
     * @throws StorePurchaseException
     */
    private function sellableQuote(ShopPlan $plan, ShopPeriod $period, Node $node, array $addons): Quote
    {
        try {
            $this->pricing->assertSellable($plan, $period, $node);

            return $this->pricing->quote($plan, $period, $node, $addons);
        } catch (InvalidQuoteException $invalidQuoteException) {
            throw StorePurchaseException::unavailable($invalidQuoteException->getMessage());
        }
    }

    /**
     * Public nodes available in the shop and out of maintenance that the plan allows, in the location or on the node the
     * player picked, and that accept the egg.
     *
     * @return Collection<int, Node>
     */
    private function candidateNodes(ShopPlan $plan, Egg $egg, ?int $locationId, ?int $nodeId): Collection
    {
        $allowed = is_array($plan->node_ids) ? array_map(intval(...), $plan->node_ids) : [];

        return $this->nodes($egg)
            ->when($allowed !== [], fn (Builder $query) => $query->whereIn('nodes.id', $allowed))
            ->when($nodeId !== null, fn (Builder $query) => $query->where('nodes.id', $nodeId))
            ->when($locationId !== null, fn (Builder $query) => $query->where('nodes.location_id', $locationId))
            ->orderBy('nodes.id')
            ->get();
    }

    /**
     * The candidate with a free allocation, the least loaded first. The node's own memory and
     * disk allocation (and overallocation) is not checked: for store purchases only the shop's
     * limits decide, and the candidates already fit them.
     *
     * @param  Collection<int, Node>  $candidates
     */
    private function chooseNode(Collection $candidates): Node
    {
        $node = Node::query()
            ->where('nodes.maintenance_mode', false)
            ->whereIn('nodes.id', $candidates->modelKeys())
            ->whereHas('allocations', fn (Builder $query) => $query->whereNull('server_id'))
            ->orderByRaw('(SELECT IFNULL(SUM(servers.memory), 0) FROM servers WHERE servers.node_id = nodes.id) / GREATEST(nodes.memory, 1)')
            ->orderBy('nodes.id')
            ->first();

        return $node instanceof Node ? $node : throw StorePurchaseException::noNode();
    }

    /** @return Builder<Node> */
    private function nodes(Egg $egg): Builder
    {
        return Node::query()
            ->where('nodes.public', true)
            ->where('nodes.shop_enabled', true)
            ->where('nodes.maintenance_mode', false)
            ->forEggTags($egg->tagSlugs())
            ->forDeployTags([]);
    }

    /**
     * Creates the server through the panel's own creation action, on a free allocation of the
     * node, with the egg's default image, startup and variables. A purchase that raced for
     * the same allocation tries another one.
     */
    private function createServer(User $user, string $name, string $description, ShopPlan $plan, Egg $egg, string $image, Node $node, Quote $quote, string $reference): Server
    {
        $environment = $egg->variables
            ->mapWithKeys(fn (EggVariable $variable): array => [$variable->env_variable => $variable->default_value ?? ''])
            ->all();

        $tried = [];
        for ($attempt = 1; ; $attempt++) {
            $allocation = Allocation::query()->unassigned()->onNodes([$node->id])
                ->whereNotIn('id', $tried)->inRandomOrder()->first()
                ?? throw new NoViableAllocationException(trans('exceptions.deployment.no_viable_allocations'));
            $tried[] = $allocation->id;

            try {
                return $this->creation->create([
                    'external_id' => $reference,
                    'name' => $name,
                    'description' => $description,
                    'owner_id' => $user->id,
                    'egg_id' => $egg->id,
                    'image' => $image,
                    'startup' => $egg->startup,
                    'environment' => $environment,
                    'memory' => $this->resource($quote, 'memory'),
                    'swap' => (int) $plan->swap,
                    'disk' => $this->resource($quote, 'disk'),
                    'io' => max(10, min(1000, (int) ($plan->io ?: 500))),
                    'cpu' => $this->resource($quote, 'cpu'),
                    'database_limit' => $this->resource($quote, 'databases'),
                    'allocation_limit' => $this->resource($quote, 'allocations'),
                    'backup_limit' => $this->resource($quote, 'backups'),
                    'allocation_id' => $allocation->id,
                    'start_on_completion' => true,
                ]);
            } catch (ValidationException $exception) {
                throw_if($attempt >= self::ALLOCATION_ATTEMPTS || ! array_key_exists('allocation_id', $exception->errors()), $exception);
            }
        }
    }

    /** A final resource of the quote, which includes the add-ons. */
    private function resource(Quote $quote, string $key): int
    {
        return (int) ($quote->resources[$key] ?? 0);
    }

    /**
     * Gives the charge back, removes a half-created server and turns the failure into a
     * message the player can act on.
     *
     * @throws StorePurchaseException
     */
    private function refund(User $user, int $tokens, string $reference, ?Server $server, Throwable $exception): never
    {
        report($exception);

        if ($server instanceof Server) {
            try {
                $this->deletion->withForce()->delete($server);
            } catch (Throwable $cleanup) {
                report($cleanup);
            }
        }

        // The coupon is given back with the tokens, so the player can use it on the next attempt.
        DB::transaction(function () use ($user, $tokens, $reference): void {
            $this->wallet->credit($user, $tokens, 'refund', 'Refund: the server could not be created', self::REFERENCE_TYPE, $reference);
            $this->coupons->release($reference);
        });

        $reason = match (true) {
            $exception instanceof NoViableNodeException, $exception instanceof NoViableAllocationException => 'There is no free spot for it on that location right now.',
            $exception instanceof ValidationException => 'The server settings were not accepted: '.$exception->getMessage(),
            $exception instanceof DisplayException => $exception->getMessage(),
            default => 'Please try again in a few minutes or contact support.',
        };

        throw StorePurchaseException::refunded($tokens, $reason, $exception);
    }

    /**
     * Asks the tunnel service for a playit tunnel. A tunnel failure never fails the purchase:
     * the service retries it and the node address shows meanwhile.
     */
    private function provisionTunnel(Server $server, ?string $subdomain): ?string
    {
        // Servers on nodes without a playit agent keep their node address (TunnelListener skips
        // them too); provisioning there would only leave a failed tunnel row behind.
        if (! $server->loadMissing('node')->node->hasPlayitAgent()) {
            return null;
        }

        try {
            // Only servers whose egg belongs to a game with automatic tunnels get one.
            $tunnel = $this->tunnels->provisionAutomatic($server);
            if ($tunnel instanceof ServerTunnel && $subdomain !== null) {
                $tunnel->forceFill(['subdomain' => $subdomain])->save();
            }
        } catch (Throwable $throwable) {
            report($throwable);
        }

        return $this->tunnelAddress($server);
    }

    private function tunnelAddress(Server $server): ?string
    {
        try {
            return $this->tunnels->displayAddress($server);
        } catch (Throwable $throwable) {
            report($throwable);

            return null;
        }
    }
}
