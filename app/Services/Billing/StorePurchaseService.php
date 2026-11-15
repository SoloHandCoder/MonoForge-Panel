<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Billing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Contracts\Servers\CreatesServers;
use Pterodactyl\Contracts\Servers\DeletesServers;
use Pterodactyl\Data\StorePurchaseOrder;
use Pterodactyl\Data\StorePurchaseResult;
use Pterodactyl\Exceptions\Billing\InsufficientTokensException;
use Pterodactyl\Exceptions\Billing\InvalidQuoteException;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Exceptions\Service\Billing\StorePurchaseException;
use Pterodactyl\Exceptions\Service\Deployment\NoViableAllocationException;
use Pterodactyl\Exceptions\Service\Deployment\NoViableNodeException;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Billing\ServerSubscription;
use Pterodactyl\Models\Billing\ShopAddon;
use Pterodactyl\Models\Billing\ShopPeriod;
use Pterodactyl\Models\Billing\ShopPlan;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\EggVariable;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Tunnels\TunnelService;
use Pterodactyl\Services\Tunnels\TunnelSettings;
use Throwable;

/**
 * Buys a server from the store: checks the order, quotes it, charges the first period,
 * deploys the server on a node the plan allows, starts its subscription and asks for a
 * playit tunnel. Anything that fails after the charge refunds it, and the player's
 * idempotency key makes a repeated request return the server it already bought.
 */
class StorePurchaseService
{
    /** The reference type of the charge and of its refund in the token ledger. */
    public const string REFERENCE_TYPE = 'store_purchase';

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

    private function buy(User $user, StorePurchaseOrder $order, string $reference): StorePurchaseResult
    {
        throw_unless(ShopSettings::enabled(), StorePurchaseException::disabled());

        $plan = ShopPlan::query()->with('category')->whereKey($order->planId)->where('visible', true)->first()
            ?? throw StorePurchaseException::unavailable('That plan is not for sale.');
        throw_unless($plan->category->visible, StorePurchaseException::unavailable('That plan is not for sale.'));
        $period = ShopPeriod::query()->whereKey($order->periodId)->where('enabled', true)->first()
            ?? throw StorePurchaseException::unavailable('That billing period is not offered.');

        $allowedEggs = array_map(intval(...), (array) $plan->egg_ids);
        throw_unless(in_array($order->eggId, $allowedEggs, true), StorePurchaseException::unavailable('That game is not available on this plan.'));
        $egg = Egg::query()->with(['tags', 'variables'])->findOrFail($order->eggId);
        $image = collect($egg->docker_images)->first();
        throw_unless(is_string($image) && $image !== '', StorePurchaseException::unavailable('That game has no image to run on yet.'));

        $addons = $this->addons($order->addons);
        $subdomain = $this->subdomain($order->subdomain);

        $candidates = $this->candidateNodes($plan, $egg, $order);
        throw_if($candidates->isEmpty(), StorePurchaseException::noNode());

        // Resources do not depend on the node, only the price does, so any candidate sizes the order.
        $quote = $this->sellableQuote($plan, $period, $candidates->firstOrFail(), $addons);
        $node = $this->chooseNode($candidates, $quote);
        if ($node->id !== $candidates->firstOrFail()->id) {
            $quote = $this->sellableQuote($plan, $period, $node, $addons);
        }

        throw_if($order->expectedTotal !== null && $order->expectedTotal !== $quote->totalPerPeriod, StorePurchaseException::priceChanged($quote->totalPerPeriod));
        throw_unless($this->capacity->canProvision($quote->resources), StorePurchaseException::capacity());

        try {
            $this->wallet->debit(
                $user,
                $quote->totalPerPeriod,
                'server_charge',
                sprintf('Server "%s": %s, first %d days', $order->name, $plan->name, $quote->periodDays),
                self::REFERENCE_TYPE,
                $reference,
                ['plan_id' => $plan->id, 'period_id' => $period->id, 'node_id' => $node->id, 'addons' => $addons],
            );
        } catch (InsufficientTokensException $insufficientTokensException) {
            throw StorePurchaseException::insufficient($quote->totalPerPeriod, $this->wallet->balance($user), $insufficientTokensException);
        }

        $server = null;
        try {
            $server = $this->createServer($user, $order, $plan, $egg, $image, $node, $quote, $reference);
            $this->subscriptions->start($server, $user, $plan, $period, $quote, $addons);
        } catch (Throwable $throwable) {
            $this->refund($user, $quote->totalPerPeriod, $reference, $server, $throwable);
        }

        return new StorePurchaseResult(
            $server,
            $quote->totalPerPeriod,
            $quote->periodDays,
            address: $this->provisionTunnel($server, $subdomain),
        );
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

        return new StorePurchaseResult(
            $server,
            (int) ($subscription->price_tokens ?? 0),
            (int) ($subscription->period_days ?? 0),
            replayed: true,
            address: $this->tunnelAddress($server),
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
        $subdomain = $subdomain === null ? '' : mb_strtolower(mb_trim($subdomain));
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
     * Public nodes out of maintenance that the plan allows, in the location or on the node the
     * player picked, and that accept the egg.
     *
     * @return Collection<int, Node>
     */
    private function candidateNodes(ShopPlan $plan, Egg $egg, StorePurchaseOrder $order): Collection
    {
        $allowed = is_array($plan->node_ids) ? array_map(intval(...), $plan->node_ids) : [];

        return $this->nodes($egg)
            ->when($allowed !== [], fn (Builder $query) => $query->whereIn('nodes.id', $allowed))
            ->when($order->nodeId !== null, fn (Builder $query) => $query->where('nodes.id', $order->nodeId))
            ->when($order->locationId !== null, fn (Builder $query) => $query->where('nodes.location_id', $order->locationId))
            ->orderBy('nodes.id')
            ->get();
    }

    /**
     * The candidate with room for the quoted memory and disk and a free allocation, the least
     * loaded first.
     *
     * @param  Collection<int, Node>  $candidates
     */
    private function chooseNode(Collection $candidates, Quote $quote): Node
    {
        $node = Node::query()
            ->viableFor($this->resource($quote, 'memory'), $this->resource($quote, 'disk'))
            ->where('nodes.maintenance_mode', false)
            ->whereIn('nodes.id', $candidates->modelKeys())
            ->whereHas('allocations', fn (Builder $query) => $query->whereNull('server_id'))
            ->orderByRaw('IFNULL(SUM(servers.memory), 0) / GREATEST(nodes.memory, 1)')
            ->orderBy('nodes.id')
            ->first();

        return $node instanceof Node ? $node : throw StorePurchaseException::noNode();
    }

    /** @return Builder<Node> */
    private function nodes(Egg $egg): Builder
    {
        return Node::query()
            ->where('nodes.public', true)
            ->where('nodes.maintenance_mode', false)
            ->forEggTags($egg->tagSlugs())
            ->forDeployTags([]);
    }

    /**
     * Creates the server through the panel's own creation action, on a free allocation of the
     * node, with the egg's default image, startup and variables. A purchase that raced for
     * the same allocation tries another one.
     */
    private function createServer(User $user, StorePurchaseOrder $order, ShopPlan $plan, Egg $egg, string $image, Node $node, Quote $quote, string $reference): Server
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
                    'name' => $order->name,
                    'description' => sprintf('%s, bought in the store.', $plan->name),
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

        $this->wallet->credit($user, $tokens, 'refund', 'Refund: the server could not be created', self::REFERENCE_TYPE, $reference);

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
            $tunnel = $this->tunnels->provision($server);
            if ($subdomain !== null) {
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
