<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Billing;

use Pterodactyl\Models\Setting;

/**
 * Free trial servers (Shop > Trials): stored as "settings::monoforge:shop:trials:*" rows and
 * loaded into config("monoforge.shop.trials.*") by the SettingsServiceProvider.
 */
final class TrialSettings
{
    /** The trial lengths the admin can pick from, in hours. */
    public const array HOURS = [24, 48, 72];

    public const int DEFAULT_HOURS = 48;

    public const int MAX_CONCURRENT = 10000;

    public static function enabled(): bool
    {
        return config()->boolean('monoforge.shop.trials.enabled');
    }

    /**
     * The plans a trial can be started on. Stored as a comma separated list.
     *
     * @return list<int>
     */
    public static function planIds(): array
    {
        $ids = explode(',', config()->string('monoforge.shop.trials.plan_ids', ''));

        $planIds = [];
        foreach ($ids as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $planIds[] = (int) $id;
            }
        }

        return array_values(array_unique($planIds));
    }

    public static function hours(): int
    {
        $hours = config()->integer('monoforge.shop.trials.hours', self::DEFAULT_HOURS);

        return in_array($hours, self::HOURS, true) ? $hours : self::DEFAULT_HOURS;
    }

    /** How many trials may run at the same time across the panel; 0 allows none. */
    public static function maxConcurrent(): int
    {
        return max(0, min(self::MAX_CONCURRENT, config()->integer('monoforge.shop.trials.max_concurrent', 10)));
    }

    /** Whether the account needs a linked Discord or Google account (a provider-verified email). */
    public static function requireVerified(): bool
    {
        return config()->boolean('monoforge.shop.trials.require_verified', true);
    }

    /**
     * @return array{enabled: bool, plan_ids: list<int>, hours: int, max_concurrent: int, require_verified: bool}
     */
    public static function all(): array
    {
        return [
            'enabled' => self::enabled(),
            'plan_ids' => self::planIds(),
            'hours' => self::hours(),
            'max_concurrent' => self::maxConcurrent(),
            'require_verified' => self::requireVerified(),
        ];
    }

    /**
     * Stores the settings and applies them to this request's configuration.
     *
     * @param  array{enabled: bool, plan_ids: list<int>, hours: int, max_concurrent: int, require_verified: bool}  $values
     */
    public static function store(array $values): void
    {
        $settings = [
            'enabled' => $values['enabled'],
            'plan_ids' => implode(',', $values['plan_ids']),
            'hours' => $values['hours'],
            'max_concurrent' => $values['max_concurrent'],
            'require_verified' => $values['require_verified'],
        ];

        foreach ($settings as $key => $value) {
            $stored = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

            Setting::put('settings::monoforge:shop:trials:'.$key, $stored);
            config()->set('monoforge.shop.trials.'.$key, $value);
        }
    }
}
