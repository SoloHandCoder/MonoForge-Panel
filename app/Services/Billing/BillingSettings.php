<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Billing;

use Pterodactyl\Models\Setting;

/**
 * The shop's billing settings, which the admin Shop tab (Task R) stores in the settings table.
 */
class BillingSettings
{
    public const string GRACE_DAYS = 'monoforge:shop:grace_days';

    public const string LOW_BALANCE_DAYS = 'monoforge:shop:low_balance_days';

    public const int DEFAULT_GRACE_DAYS = 7;

    public const int DEFAULT_LOW_BALANCE_DAYS = 3;

    /**
     * Days between a failed renewal (the suspension) and the deletion; 0 deletes at once.
     */
    public function graceDays(): int
    {
        return $this->integer(self::GRACE_DAYS, self::DEFAULT_GRACE_DAYS, 30);
    }

    /**
     * How many days before a renewal a short balance is warned about.
     */
    public function lowBalanceDays(): int
    {
        return $this->integer(self::LOW_BALANCE_DAYS, self::DEFAULT_LOW_BALANCE_DAYS, 30);
    }

    private function integer(string $key, int $default, int $max): int
    {
        $value = Setting::fetch($key);

        if ($value === null || mb_trim($value) === '' || ! is_numeric($value)) {
            return $default;
        }

        return max(0, min($max, (int) $value));
    }
}
