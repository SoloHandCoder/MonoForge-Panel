<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Billing;

use Pterodactyl\Models\Billing\TokenPack;
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

    /**
     * How long the first purchase must stay paid (not refunded or charged back) before the
     * reward, by default. Card and PayPal disputes can come weeks later.
     */
    public const int DEFAULT_HOLD_DAYS = 30;

    public const int MAX_HOLD_DAYS = 365;

    /** The most tokens the first purchase can be required to credit. */
    public const int MAX_MIN_PURCHASE = 100_000_000;

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

    /** How many days the first purchase is held before the reward is paid. */
    public static function holdDays(): int
    {
        $value = Setting::cached(self::PREFIX.'hold_days');

        return is_numeric($value) ? max(1, min(self::MAX_HOLD_DAYS, (int) $value)) : self::DEFAULT_HOLD_DAYS;
    }

    /**
     * The tokens the referred user's first Tebex purchase must credit for the referral to
     * qualify, as set; 0 means the smallest visible token pack (see minPurchaseTokens()).
     */
    public static function minPurchaseSetting(): int
    {
        $value = Setting::cached(self::PREFIX.'min_purchase_tokens');

        return is_numeric($value) ? max(0, min(self::MAX_MIN_PURCHASE, (int) $value)) : 0;
    }

    /** The tokens the first purchase must credit: the setting, or the smallest visible token pack. */
    public static function minPurchaseTokens(): int
    {
        $set = self::minPurchaseSetting();
        if ($set > 0) {
            return $set;
        }

        $cheapest = TokenPack::query()->where('visible', true)->orderBy('tokens')->first();

        return $cheapest instanceof TokenPack ? $cheapest->totalTokens() : 1;
    }

    /**
     * @return array{enabled: bool, reward_tokens: int, monthly_cap: int, hold_days: int, min_purchase_tokens: int, effective_min_purchase_tokens: int}
     */
    public static function toArray(): array
    {
        return [
            'enabled' => self::enabled(),
            'reward_tokens' => self::reward(),
            'monthly_cap' => self::monthlyCap(),
            'hold_days' => self::holdDays(),
            'min_purchase_tokens' => self::minPurchaseSetting(),
            'effective_min_purchase_tokens' => self::minPurchaseTokens(),
        ];
    }

    public static function store(bool $enabled, int $reward, int $monthlyCap, ?int $holdDays = null, ?int $minPurchase = null): void
    {
        Setting::put(self::PREFIX.'enabled', $enabled ? 'true' : 'false');
        Setting::put(self::PREFIX.'reward_tokens', (string) max(1, min(self::MAX_REWARD, $reward)));
        Setting::put(self::PREFIX.'monthly_cap', (string) max(0, min(self::MAX_MONTHLY_CAP, $monthlyCap)));
        if ($holdDays !== null) {
            Setting::put(self::PREFIX.'hold_days', (string) max(1, min(self::MAX_HOLD_DAYS, $holdDays)));
        }

        if ($minPurchase !== null) {
            Setting::put(self::PREFIX.'min_purchase_tokens', (string) max(0, min(self::MAX_MIN_PURCHASE, $minPurchase)));
        }
    }
}
