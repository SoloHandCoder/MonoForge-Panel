<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Billing;

use Pterodactyl\Models\Setting;

/**
 * The referral programme's settings, stored as "settings::monoforge:referrals:*" rows. The
 * programme is off until an administrator turns it on.
 */
final class ReferralSettings
{
    public const int DEFAULT_REWARD = 200;

    public const int DEFAULT_MONTHLY_CAP = 10;

    /** The most tokens a reward may be (the cap on an admin token adjustment). */
    public const int MAX_REWARD = 1_000_000;

    public const int MAX_MONTHLY_CAP = 1000;

    /** How long the first purchase must stay paid (not refunded or charged back) before the reward. */
    public const int HOLD_DAYS = 7;

    /** How long the referral cookie lasts. */
    public const int COOKIE_DAYS = 30;

    public const string COOKIE = 'monoforge_referral';

    private const string PREFIX = 'settings::monoforge:referrals:';

    public static function enabled(): bool
    {
        return filter_var(Setting::cached(self::PREFIX.'enabled'), FILTER_VALIDATE_BOOLEAN);
    }

    /** The tokens each side (referrer and referred user) gets. */
    public static function reward(): int
    {
        $value = Setting::cached(self::PREFIX.'reward_tokens');

        return is_numeric($value) ? max(1, min(self::MAX_REWARD, (int) $value)) : self::DEFAULT_REWARD;
    }

    /** The most rewards a referrer is paid per calendar month; 0 means no cap. */
    public static function monthlyCap(): int
    {
        $value = Setting::cached(self::PREFIX.'monthly_cap');

        return is_numeric($value) ? max(0, min(self::MAX_MONTHLY_CAP, (int) $value)) : self::DEFAULT_MONTHLY_CAP;
    }

    /**
     * @return array{enabled: bool, reward_tokens: int, monthly_cap: int, hold_days: int}
     */
    public static function toArray(): array
    {
        return [
            'enabled' => self::enabled(),
            'reward_tokens' => self::reward(),
            'monthly_cap' => self::monthlyCap(),
            'hold_days' => self::HOLD_DAYS,
        ];
    }

    public static function store(bool $enabled, int $reward, int $monthlyCap): void
    {
        Setting::put(self::PREFIX.'enabled', $enabled ? 'true' : 'false');
        Setting::put(self::PREFIX.'reward_tokens', (string) max(1, min(self::MAX_REWARD, $reward)));
        Setting::put(self::PREFIX.'monthly_cap', (string) max(0, min(self::MAX_MONTHLY_CAP, $monthlyCap)));
    }
}
