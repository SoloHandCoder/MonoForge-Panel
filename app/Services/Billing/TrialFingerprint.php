<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Billing;

use Pterodactyl\Enum\OAuthProvider;
use Pterodactyl\Models\User;
use Pterodactyl\Models\UserOAuthAccount;
use RuntimeException;

/**
 * Keyed hashes for the free trial anti-abuse checks: the /24 (IPv4) or /48 (IPv6) network of an
 * address and a linked Discord or Google id. They are HMACs with the app key, so a leaked table
 * cannot be turned back into addresses by hashing every network.
 *
 * Without an app key they would be plain hashes anyone can reverse, so they refuse to run.
 */
final class TrialFingerprint
{
    /** The network of an address (a /24 for IPv4, a /48 for IPv6), hashed; null for no or a bad address. */
    public static function subnet(?string $ip): ?string
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        // Hex of the packed address (ASCII, so string lengths are bytes x 2).
        $hex = bin2hex($packed);

        // An IPv4-mapped IPv6 address (::ffff:a.b.c.d) is the IPv4 address.
        if (str_starts_with($hex, '00000000000000000000ffff') && mb_strlen($hex) === 32) {
            $hex = mb_substr($hex, 24);
        }

        $network = mb_strlen($hex) === 8
            ? 'v4:'.mb_substr($hex, 0, 6)
            : 'v6:'.mb_substr($hex, 0, 12);

        return self::hash('subnet', $network);
    }

    /** A linked account id of a provider, hashed. */
    public static function identity(string $provider, string $providerUserId): string
    {
        return self::hash('oauth:'.$provider, $providerUserId);
    }

    /**
     * The hashes of the user's linked Discord and Google accounts, by column. Free trials,
     * referral rewards and free-tokens coupons are limited to one per linked account.
     *
     * @return array<'discord_hash'|'google_hash', string>
     */
    public static function linkedIdentities(User $user): array
    {
        $hashes = [];
        foreach (UserOAuthAccount::query()->where('user_id', $user->id)->get() as $account) {
            $column = match ($account->provider) {
                OAuthProvider::Discord => 'discord_hash',
                OAuthProvider::Google => 'google_hash',
            };
            $hashes[$column] = self::identity($account->provider->value, $account->provider_user_id);
        }

        return $hashes;
    }

    /**
     * The application key the anti-abuse hashes are keyed with.
     *
     * @throws RuntimeException when the panel has no app key
     */
    public static function key(): string
    {
        $key = config('app.key');
        throw_unless(is_string($key) && $key !== '', RuntimeException::class, 'APP_KEY is not set, so the anti-abuse hashes cannot be keyed. Run "php artisan key:generate".');

        return $key;
    }

    private static function hash(string $purpose, string $value): string
    {
        return hash_hmac('sha256', 'monoforge-trial:'.$purpose.':'.$value, self::key());
    }
}
