<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Tunnels;

use Pterodactyl\Models\Billing\ServerTunnel;
use Pterodactyl\Models\Setting;

/**
 * The custom subdomain settings (`name.monoforge.net`). Creating the DNS records is a later
 * extra, so for now the chosen name is only stored on the server's tunnel.
 */
final class TunnelSettings
{
    public const string ENABLED = 'settings::monoforge:tunnels:subdomains_enabled';

    public const string BASE_DOMAIN = 'settings::monoforge:tunnels:base_domain';

    public const string BLOCKLIST = 'settings::monoforge:tunnels:blocklist';

    /** A DNS label: lowercase letters, digits and inner hyphens. */
    public const string SUBDOMAIN_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    /** Whether players may pick a custom subdomain: switched on, with a base domain set. */
    public static function subdomainsEnabled(): bool
    {
        return self::switchedOn() && self::baseDomain() !== '';
    }

    /** The administrator's switch alone. */
    public static function switchedOn(): bool
    {
        return Setting::fetch(self::ENABLED) === '1';
    }

    public static function baseDomain(): string
    {
        return mb_strtolower(mb_trim((string) Setting::fetch(self::BASE_DOMAIN)));
    }

    /**
     * The names administrators reserved, lowercased.
     *
     * @return list<string>
     */
    public static function blocklist(): array
    {
        $decoded = json_decode((string) Setting::fetch(self::BLOCKLIST), true);

        return is_array($decoded) ? array_values(array_filter($decoded, is_string(...))) : [];
    }

    /**
     * @param  array<array-key, string>  $blocklist
     */
    public static function save(bool $enabled, string $baseDomain, array $blocklist): void
    {
        $names = array_values(array_unique(array_filter(array_map(fn (string $name): string => mb_strtolower(mb_trim($name)), $blocklist), fn (string $name): bool => $name !== '')));

        Setting::put(self::ENABLED, $enabled ? '1' : '0');
        Setting::put(self::BASE_DOMAIN, mb_strtolower(mb_trim($baseDomain)));
        Setting::put(self::BLOCKLIST, json_encode($names, JSON_THROW_ON_ERROR));
    }

    /** A requested subdomain as it is checked and stored: trimmed and lowercased. */
    public static function normalize(string $subdomain): string
    {
        return mb_strtolower(mb_trim($subdomain));
    }

    /**
     * Why a requested subdomain cannot be used, or null when it can. For the checkout (Task S).
     *
     * Only ASCII letters, digits and hyphens pass, punycode (`xn--`, the encoding of look-alike
     * Unicode names) is refused, and a name is reserved when it contains a blocked word anywhere
     * (`admin` also blocks `my-admin1`). A name is taken while another tunnel holds it; deleting
     * the tunnel or the server releases it.
     */
    public static function subdomainProblem(string $subdomain): ?string
    {
        $name = self::normalize($subdomain);

        return match (true) {
            ! self::subdomainsEnabled() => 'Custom subdomains are not available.',
            preg_match(self::SUBDOMAIN_PATTERN, $name) !== 1 => 'Use 1 to 63 lowercase letters, digits or hyphens, not starting or ending with a hyphen.',
            str_contains($name, 'xn--') => 'Use plain letters, digits and hyphens; encoded (xn--) names are not allowed.',
            self::containsBlockedWord($name) => 'That name is reserved.',
            ServerTunnel::query()->where('subdomain', $name)->exists() => 'That name is already taken.',
            default => null,
        };
    }

    private static function containsBlockedWord(string $name): bool
    {
        foreach (self::blocklist() as $word) {
            $word = self::normalize($word);
            if ($word !== '' && str_contains($name, $word)) {
                return true;
            }
        }

        return false;
    }
}
