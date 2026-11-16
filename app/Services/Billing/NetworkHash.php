<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Billing;

/**
 * A keyed hash of the network an address belongs to: its /24 for IPv4 and its /48 for IPv6.
 * Keyed with the application key, so the stored hashes cannot be turned back into networks
 * by hashing every possible one. Used to spot sign-ups and redemptions from the same place.
 * Without an app key the hashes could be reversed, so it throws instead of hashing.
 */
final class NetworkHash
{
    /** The hash of the address's network, or null for something that is not an IP address. */
    public static function of(?string $ip): ?string
    {
        $network = self::network($ip);

        return $network === null ? null : hash_hmac('sha256', 'network:'.$network, self::key());
    }

    /** The /24 (IPv4) or /48 (IPv6) network of the address, e.g. "203.0.113.0/24". */
    public static function network(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }

        $packed = @inet_pton(mb_trim($ip));
        if ($packed === false) {
            return null;
        }

        // An IPv4-mapped IPv6 address (::ffff:a.b.c.d) is the IPv4 address.
        if (mb_strlen($packed, '8bit') === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $packed = mb_substr($packed, 12, null, '8bit');
        }

        if (mb_strlen($packed, '8bit') === 4) {
            return inet_ntop(mb_substr($packed, 0, 3, '8bit')."\0").'/24';
        }

        $prefix = (string) inet_ntop(mb_substr($packed, 0, 6, '8bit').str_repeat("\0", 10));

        return $prefix.'/48';
    }

    private static function key(): string
    {
        return TrialFingerprint::key();
    }
}
