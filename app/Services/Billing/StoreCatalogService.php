<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Billing;

use Illuminate\Database\Eloquent\Collection;
use Pterodactyl\Enum\ShopMultiplierScope;
use Pterodactyl\Models\Billing\ShopAddon;
use Pterodactyl\Models\Billing\ShopCategory;
use Pterodactyl\Models\Billing\ShopMultiplier;
use Pterodactyl\Models\Billing\ShopPeriod;
use Pterodactyl\Models\Billing\ShopPlan;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Tag;
use Pterodactyl\Services\Tunnels\TunnelSettings;

/**
 * What the store shows a player: visible categories with their plans cheapest first,
 * the enabled periods and add-ons, and where each plan can be placed. Node addresses
 * are never included, only ids and names.
 */
class StoreCatalogService
{
    /** @var array{node: array<int, float>, location: array<int, float>}|null */
    private ?array $multipliers = null;

    /** @var Collection<int, Node>|null */
    private ?Collection $nodes = null;

    /** @var array{memory: int, disk: int, cpu: int}|null */
    private ?array $used = null;

    /** @var array<int, array{memory: int, disk: int, cpu: int}>|null */
    private ?array $usedByNode = null;

    public function __construct(private readonly CapacityService $capacity) {}

    /**
     * @return array<string, mixed>
     */
    public function catalog(): array
    {
        if (! ShopSettings::enabled()) {
            return ['enabled' => false, 'subdomains_enabled' => false, 'subdomain_base' => null, 'categories' => [], 'periods' => [], 'addons' => []];
        }

        $categories = ShopCategory::query()->ordered()->where('visible', true)->get();
        $visiblePlans = ShopPlan::query()
            ->where('visible', true)
            ->whereIn('category_id', $categories->modelKeys())
            ->cheapestFirst()
            ->get();

        $eggIds = $visiblePlans->flatMap(fn (ShopPlan $plan): array => $plan->eggIds())->unique()->values()->all();
        $eggs = Egg::query()->with('tags')->whereIn('id', $eggIds)->orderBy('name')->get()->keyBy('id');

        $presented = [];
        foreach ($categories as $category) {
            $plans = $visiblePlans->where('category_id', $category->id)
                ->map(fn (ShopPlan $plan): array => $this->plan($plan, $eggs))
                ->filter(fn (array $plan): bool => $plan['eggs'] !== [] && $plan['locations'] !== [])
                ->values()
                ->all();

            if ($plans !== []) {
                $presented[] = [...ShopPresenter::category($category), 'plans' => $plans];
            }
        }

        return [
            'enabled' => true,
            // Custom subdomains are offered at checkout only when the admin's Tunnels settings allow them.
            'subdomains_enabled' => TunnelSettings::subdomainsEnabled(),
            'subdomain_base' => TunnelSettings::subdomainsEnabled() ? TunnelSettings::baseDomain() : null,
            'categories' => $presented,
            'periods' => ShopPeriod::query()->ordered()->where('enabled', true)->get()
                ->map(fn (ShopPeriod $period): array => [
                    'id' => $period->id,
                    'name' => $period->name,
                    'days' => $period->days,
                    'price_modifier_percent' => $period->price_modifier_percent,
                ])->all(),
            'addons' => ShopAddon::query()->where('enabled', true)->orderBy('name')->get()
                ->map(fn (ShopAddon $addon): array => [
                    'key' => $addon->key,
                    'name' => $addon->name,
                    'description' => $addon->description,
                    'resource' => $addon->resource->value,
                    'amount' => $addon->amount,
                    'price_tokens' => $addon->price_tokens,
                    'max_units' => $addon->max_units,
                ])->all(),
        ];
    }

    /**
     * The nodes a plan may be placed on now: its own list, or every deployable node, never
     * one under maintenance or one not available in the shop. Cheapest multiplier first.
     *
     * @return Collection<int, Node>
     */
    public function nodesFor(ShopPlan $plan): Collection
    {
        return $this->nodes()
            ->filter(fn (Node $node): bool => $plan->allowsNode($node) && ! $node->isUnderMaintenance())
            ->sortBy([fn (Node $a, Node $b): int => $this->multiplierOf($a) <=> $this->multiplierOf($b), fn (Node $a, Node $b): int => $a->id <=> $b->id])
            ->values();
    }

    /**
     * The cheapest node the plan may use in the location, if any. Given the resources of an order,
     * the cheapest one that still has room for them within its own shop limits comes first.
     *
     * @param  array<string, int>|null  $resources
     */
    public function cheapestNodeIn(ShopPlan $plan, int $locationId, ?array $resources = null): ?Node
    {
        $nodes = $this->nodesFor($plan)->filter(fn (Node $node): bool => $node->location_id === $locationId)->values();
        if ($resources === null) {
            return $nodes->first();
        }

        return $nodes->first(fn (Node $node): bool => $this->nodeFits($node, $resources)) ?? $nodes->first();
    }

    /**
     * Whether a server with these resources fits within the node's own shop limits.
     *
     * @param  array<string, int>  $resources
     */
    public function nodeFits(Node $node, array $resources): bool
    {
        $this->usedByNode ??= $this->capacity->usedByNode();

        return CapacityService::fits(CapacityService::nodeLimits($node), $this->usedByNode[$node->id] ?? ['memory' => 0, 'disk' => 0, 'cpu' => 0], $resources);
    }

    public function multiplierOf(Node $node): float
    {
        $multipliers = $this->multipliers();

        return $multipliers['node'][$node->id] ?? $multipliers['location'][$node->location_id] ?? 1.0;
    }

    /**
     * @param  \Illuminate\Support\Collection<int|string, Egg>  $eggs
     * @return array<string, mixed>
     */
    private function plan(ShopPlan $plan, \Illuminate\Support\Collection $eggs): array
    {
        $nodes = $this->nodesFor($plan);
        $resources = $plan->resources();
        $locations = [];
        $anyNodeFits = false;
        foreach ($nodes as $node) {
            $location = $node->location;
            $locations[$location->id] ??= [
                'id' => $location->id,
                'short' => $location->short,
                'long' => $location->long,
                'multiplier' => $this->multipliers()['location'][$location->id] ?? 1.0,
                'can_provision' => false,
                'nodes' => [],
            ];
            // A node at its own shop limits stays listed, marked so the store can grey it out.
            $fits = $this->nodeFits($node, $resources);
            $anyNodeFits = $anyNodeFits || $fits;
            $locations[$location->id]['can_provision'] = $locations[$location->id]['can_provision'] || $fits;
            $locations[$location->id]['nodes'][] = ['id' => $node->id, 'name' => $node->name, 'multiplier' => $this->multiplierOf($node), 'can_provision' => $fits];
        }

        $lowest = $nodes->isEmpty() ? 1.0 : $this->multiplierOf($nodes->first());

        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'description' => $plan->description,
            'price_tokens' => $plan->price_tokens,
            // What the plan costs per 30 days on its cheapest node.
            'from_price_tokens' => max(1, (int) ceil($plan->price_tokens * $lowest - 1e-6)),
            'resources' => $resources,
            // Within the shop-wide limits and on at least one node within its own.
            'can_provision' => $anyNodeFits && CapacityService::fits(ShopSettings::limits(), $this->used ??= $this->capacity->used(), $resources),
            'eggs' => collect($plan->eggIds())
                ->map(fn (int $id): ?Egg => $eggs->get($id))
                ->filter()
                ->map(fn (Egg $egg): array => [
                    'id' => $egg->id,
                    'name' => $egg->name,
                    'description' => $egg->description,
                    // Eggs have no artwork yet; the store falls back to the tag colour.
                    'image' => null,
                    'tags' => $egg->tags->map(fn (Tag $tag): array => ['name' => $tag->name, 'color' => $tag->color])->values()->all(),
                ])
                ->values()
                ->all(),
            'locations' => array_values($locations),
        ];
    }

    /**
     * Public nodes the admin made available in the shop only: a plan that names any other node
     * never shows (or quotes) it, like the purchase, which refuses it.
     *
     * @return Collection<int, Node>
     */
    private function nodes(): Collection
    {
        return $this->nodes ??= Node::query()->with('location')->where('public', true)->where('shop_enabled', true)->orderBy('id')->get();
    }

    /**
     * @return array{node: array<int, float>, location: array<int, float>}
     */
    private function multipliers(): array
    {
        if ($this->multipliers === null) {
            $this->multipliers = ['node' => [], 'location' => []];
            foreach (ShopMultiplier::query()->get() as $multiplier) {
                $scope = $multiplier->scope === ShopMultiplierScope::Node ? 'node' : 'location';
                $this->multipliers[$scope][$multiplier->scope_id] = $multiplier->value();
            }
        }

        return $this->multipliers;
    }
}
