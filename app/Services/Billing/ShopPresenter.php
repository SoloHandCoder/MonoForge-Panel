<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Billing;

use Pterodactyl\Models\Billing\ShopAddon;
use Pterodactyl\Models\Billing\ShopCategory;
use Pterodactyl\Models\Billing\ShopMultiplier;
use Pterodactyl\Models\Billing\ShopPeriod;
use Pterodactyl\Models\Billing\ShopPlan;

/**
 * The JSON shape of the shop catalog models, shared by the admin and store endpoints.
 */
final class ShopPresenter
{
    /**
     * @return array{id: int, name: string, slug: string, description: string|null, sort: int, visible: bool}
     */
    public static function category(ShopCategory $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'sort' => $category->sort,
            'visible' => $category->visible,
        ];
    }

    /**
     * @return array{id: int, category_id: int, name: string, description: string|null, visible: bool, sort: int, price_tokens: int, resources: array<string, int>, egg_ids: list<int>, node_ids: list<int>|null, sleep_allowed: bool, sleep_discount_percent: int, sleep_idle_minutes: int}
     */
    public static function plan(ShopPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'category_id' => $plan->category_id,
            'name' => $plan->name,
            'description' => $plan->description,
            'visible' => $plan->visible,
            'sort' => $plan->sort,
            'price_tokens' => $plan->price_tokens,
            'resources' => $plan->resources(),
            'egg_ids' => $plan->eggIds(),
            'node_ids' => $plan->nodeIds(),
            'sleep_allowed' => $plan->sleep_allowed,
            'sleep_discount_percent' => $plan->sleepDiscountPercent(),
            'sleep_idle_minutes' => $plan->sleepIdleMinutes(),
        ];
    }

    /**
     * @return array{id: int, name: string, days: int, price_modifier_percent: int, sort: int, enabled: bool}
     */
    public static function period(ShopPeriod $period): array
    {
        return [
            'id' => $period->id,
            'name' => $period->name,
            'days' => $period->days,
            'price_modifier_percent' => $period->price_modifier_percent,
            'sort' => $period->sort,
            'enabled' => $period->enabled,
        ];
    }

    /**
     * @return array{id: int, scope: string, scope_id: int, multiplier: float, label: string|null}
     */
    public static function multiplier(ShopMultiplier $multiplier): array
    {
        return [
            'id' => $multiplier->id,
            'scope' => $multiplier->scope->value,
            'scope_id' => $multiplier->scope_id,
            'multiplier' => $multiplier->value(),
            'label' => $multiplier->label,
        ];
    }

    /**
     * @return array{id: int, key: string, name: string, description: string|null, resource: string, amount: int, price_tokens: int, max_units: int, enabled: bool}
     */
    public static function addon(ShopAddon $addon): array
    {
        return [
            'id' => $addon->id,
            'key' => $addon->key,
            'name' => $addon->name,
            'description' => $addon->description,
            'resource' => $addon->resource->value,
            'amount' => $addon->amount,
            'price_tokens' => $addon->price_tokens,
            'max_units' => $addon->max_units,
            'enabled' => $addon->enabled,
        ];
    }
}
