<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Search;

use Closure;

/**
 * The SSRF guard of the web search: the panel only ever calls public https addresses. A URL
 * is allowed when it is https on port 443, has no user info, and its host is (or resolves only
 * to) public, globally routable IPs: never loopback, private, link-local, carrier-grade NAT,
 * reserved or documentation ranges, and never names such as localhost or *.internal.
 * resolve() returns the checked addresses so the request can be pinned to them (no DNS
 * rebinding between the check and the request).
 *
 * Source links shown in the thread are checked by isPublicLink(), which needs no DNS because
 * the panel never fetches them.
 */
class PublicUrl
{
    /** @var list<string> */
    private const array BLOCKED_SUFFIXES = ['localhost', 'local', 'internal', 'localdomain', 'home.arpa', 'lan', 'intranet'];

    /** @var Closure(string): list<string> */
    private readonly Closure $resolver;

    /**
     * @param  (Closure(string): list<string>)|null  $resolver  host name to IPs; DNS by default
     */
    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? $this->dns(...);
    }

    /** Whether an IP is public and globally routable. */
    public static function isPublicIp(string $ip): bool
    {
        $ip = mb_trim($ip, '[]');

        // An IPv4 address mapped into IPv6 (::ffff:127.0.0.1) is checked as the IPv4 address.
        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $match) === 1) {
            $ip = $match[1];
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
    }

    /**
     * Whether a link from a search result may be shown: http or https, no user info, and not an
     * address or name that points into a private network.
     */
    public static function isPublicLink(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if (! in_array(mb_strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        return self::publicHostName($parts['host']);
    }

    /**
     * The public IPs a public https URL resolves to, or null when the URL is not allowed.
     *
     * @return non-empty-list<string>|null
     */
    public function resolve(string $url): ?array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        if (mb_strtolower($parts['scheme']) !== 'https' || (isset($parts['port']) && $parts['port'] !== 443)) {
            return null;
        }

        $host = mb_strtolower(mb_trim($parts['host'], '[]'));
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isPublicIp($host) ? [$host] : null;
        }

        if (! self::publicHostName($host)) {
            return null;
        }

        $ips = ($this->resolver)($host);
        if ($ips === []) {
            return null;
        }

        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                return null;
            }
        }

        return $ips;
    }

    public function isAllowed(string $url): bool
    {
        return $this->resolve($url) !== null;
    }

    /**
     * A host name (not an IP) that can be public: dotted, without a private suffix, and not a
     * decimal, hex or octal spelling of an address (2130706433, 0x7f000001), which curl accepts.
     */
    private static function publicHostName(string $host): bool
    {
        $host = mb_rtrim(mb_strtolower(mb_trim($host, '[]')), '.');
        if ($host === '') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isPublicIp($host);
        }

        if (! str_contains($host, '.') || preg_match('/^((0x[0-9a-f]*|[0-9]+)\.){0,3}(0x[0-9a-f]*|[0-9]+)$/i', $host) === 1) {
            return false;
        }

        foreach (self::BLOCKED_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return false;
            }
        }

        // Plain ASCII names only; international names arrive punycoded (xn--).
        return preg_match('/^[a-z0-9.-]+$/', $host) === 1;
    }

    /**
     * @return list<string>
     */
    private function dns(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];

        $records = @dns_get_record($host, DNS_AAAA);
        foreach (is_array($records) ? $records : [] as $record) {
            if (is_string($record['ipv6'] ?? null)) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
