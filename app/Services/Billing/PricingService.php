<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Billing;

use Pterodactyl\Enum\ShopMultiplierScope;
use Pterodactyl\Exceptions\Billing\InvalidQuoteException;
use Pterodactyl\Models\Billing\ShopAddon;
use Pterodactyl\Models\Billing\ShopMultiplier;
use Pterodactyl\Models\Billing\ShopPeriod;
use Pterodactyl\Models\Billing\ShopPlan;
use Pterodactyl\Models\Node;

/**
 * Prices a shop server in whole tokens per period.
 *
 * - base = round(price_tokens x days / 30 x (1 + price_modifier_percent / 100))
 * - multiplier = the node's multiplier, else its location's, else 1.0
 * - add-ons = the sum of units x price_tokens x days / 30 x (1 + price_modifier_percent / 100)
 * - total = ceil(base x multiplier + add-ons), never below 1
 */
class PricingService
{
    /** Absorbs float noise such as 114.99999 or 115.00001 before rounding up. */
    private const float EPSILON = 1e-6;

    /**
     * @param  array<string, int>  $addons  units per add-on key; zero units are ignored
     *
     * @throws InvalidQuoteException when an add-on is unknown, disabled or over its unit limit
     */
    public function quote(ShopPlan $plan, ShopPeriod $period, Node $node, array $addons): Quote
    {
        return $this->calculate($plan, $period, $this->multiplierFor($node), $this->resolveAddons($addons));
    }

    /**
     * The pricing formula on its own, for a multiplier and add-ons already looked up.
     *
     * @param  list<array{ShopAddon, int}>  $addons  each add-on with its unit count
     */
    public function calculate(ShopPlan $plan, ShopPeriod $period, float $multiplier, array $addons): Quote
    {
        $factor = $period->factor();
        $base = (int) round($plan->price_tokens * $factor);
        $resources = $plan->resources();

        $addonLines = [];
        $addonsExact = 0.0;
        foreach ($addons as [$addon, $units]) {
            if ($units <= 0) {
                continue;
            }

            $exact = $units * $addon->price_tokens * $factor;
            $addonsExact += $exact;
            $addonLines[] = ['label' => "{$units} × {$addon->name}", 'tokens' => (int) round($exact)];
            $resources[$addon->resource->value] += $addon->amount * $units;
        }

        $total = max(1, (int) ceil($base * $multiplier + $addonsExact - self::EPSILON));
        $addonsPerPeriod = array_sum(array_column($addonLines, 'tokens'));
        // The plan line takes whatever the add-on lines do not, so the lines always add up to the total.
        $planLine = [
            'label' => $this->planLabel($plan, $period, $multiplier),
            'tokens' => max(0, $total - $addonsPerPeriod),
        ];

        return new Quote(
            basePerPeriod: $base,
            multiplier: $multiplier,
            addonsPerPeriod: $addonsPerPeriod,
            totalPerPeriod: $total,
            periodDays: $period->days,
            lines: [$planLine, ...$addonLines],
            resources: $resources,
        );
    }

    /** The node's own multiplier, else its location's, else 1.0. */
    public function multiplierFor(Node $node): float
    {
        $multiplier = ShopMultiplier::query()->where('scope', ShopMultiplierScope::Node)->where('scope_id', $node->id)->first()
            ?? ShopMultiplier::query()->where('scope', ShopMultiplierScope::Location)->where('scope_id', $node->location_id)->first();

        return $multiplier?->value() ?? 1.0;
    }

    /**
     * Refuses a purchase the store does not offer: a hidden plan, a disabled period, or a
     * node the plan does not allow (or that is under maintenance).
     *
     * @throws InvalidQuoteException
     */
    public function assertSellable(ShopPlan $plan, ShopPeriod $period, Node $node): void
    {
        throw_unless($plan->visible, InvalidQuoteException::class, 'This plan is not for sale.');

        throw_unless($period->enabled, InvalidQuoteException::class, 'This billing period is not available.');

        throw_if(! $plan->allowsNode($node) || $node->isUnderMaintenance(), InvalidQuoteException::class, 'This plan is not available on the selected node.');
    }

    /**
     * Looks up the requested add-ons, refusing unknown or disabled ones and too many units.
     *
     * @param  array<string, int>  $addons
     * @return list<array{ShopAddon, int}>
     *
     * @throws InvalidQuoteException
     */
    public function resolveAddons(array $addons): array
    {
        $requested = array_filter($addons, fn (int $units): bool => $units !== 0);
        if ($requested === []) {
            return [];
        }

        $known = ShopAddon::query()
            ->whereIn('key', array_map(strval(...), array_keys($requested)))
            ->where('enabled', true)
            ->get()
            ->keyBy('key');

        $resolved = [];
        foreach ($requested as $key => $units) {
            $addon = $known->get((string) $key);
            throw_unless($addon instanceof ShopAddon, InvalidQuoteException::class, "The add-on \"{$key}\" is not available.");

            if ($units < 0 || $units > $addon->max_units) {
                throw new InvalidQuoteException("You can add at most {$addon->max_units} of \"{$addon->name}\".");
            }

            $resolved[] = [$addon, $units];
        }

        return $resolved;
    }

    private function planLabel(ShopPlan $plan, ShopPeriod $period, float $multiplier): string
    {
        $label = "{$plan->name} · {$period->days} days";

        return $multiplier === 1.0 ? $label : $label.' × '.mb_rtrim(mb_rtrim(number_format($multiplier, 2, '.', ''), '0'), '.');
    }
}
