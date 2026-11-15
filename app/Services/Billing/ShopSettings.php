<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Billing;

use Pterodactyl\Models\Setting;

/**
 * The token shop's settings: stored as "settings::monoforge:shop:*" rows and loaded into
 * config("monoforge.shop.*") by the SettingsServiceProvider, with the defaults in
 * config/monoforge.php.
 */
final class ShopSettings
{
    public const int MAX_GRACE_DAYS = 30;

    public static function enabled(): bool
    {
        return config()->boolean('monoforge.shop.enabled');
    }

    /** Days a suspended shop server is kept before it is deleted; 0 deletes it at suspension. */
    public static function graceDays(): int
    {
        return max(0, min(self::MAX_GRACE_DAYS, config()->integer('monoforge.shop.grace_days', 7)));
    }

    /** How many days before a renewal a player without enough tokens is warned. */
    public static function lowBalanceDays(): int
    {
        return max(0, config()->integer('monoforge.shop.low_balance_days', 3));
    }

    /**
     * The most the shop sells in total across shop servers; 0 means no limit.
     *
     * @return array{memory: int, disk: int, cpu: int}
     */
    public static function limits(): array
    {
        return [
            'memory' => max(0, config()->integer('monoforge.shop.limits.memory_mb', 0)),
            'disk' => max(0, config()->integer('monoforge.shop.limits.disk_mb', 0)),
            'cpu' => max(0, config()->integer('monoforge.shop.limits.cpu_percent', 0)),
        ];
    }

    /**
     * Stores the settings and applies them to this request's configuration.
     *
     * @param  array{enabled: bool, grace_days: int, low_balance_days: int, limits: array{memory: int, disk: int, cpu: int}}  $values
     */
    public static function store(array $values): void
    {
        $settings = [
            'enabled' => $values['enabled'],
            'grace_days' => $values['grace_days'],
            'low_balance_days' => $values['low_balance_days'],
            'limits.memory_mb' => $values['limits']['memory'],
            'limits.disk_mb' => $values['limits']['disk'],
            'limits.cpu_percent' => $values['limits']['cpu'],
        ];

        foreach ($settings as $key => $value) {
            $stored = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

            Setting::put('settings::monoforge:shop:'.str_replace('.', ':', $key), $stored);
            config()->set('monoforge.shop.'.$key, $value);
        }
    }
}
