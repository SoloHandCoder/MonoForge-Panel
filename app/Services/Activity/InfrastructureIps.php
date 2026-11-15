<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Activity;

use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Pterodactyl\Models\Node;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * The "infrastructure IP set": addresses of the panel host and its nodes that are never
 * shown in activity entries. It is made of loopback, private, unique local and link-local
 * ranges, the addresses each node's FQDN resolves to (A and AAAA, cached for an hour) and
 * the admin-editable "monoforge:privacy:hidden_ips" list of IPs and CIDR ranges.
 *
 * Nothing in here throws: lookups that fail are ignored.
 */
class InfrastructureIps
{
    public const string CACHE_PREFIX = 'monoforge:privacy:node_ips:';

    /** Ranges that never belong to a member of the public. */
    public const array RESERVED_RANGES = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '::/128',
        '::1/128',
        'fc00::/7',
        'fe80::/10',
    ];

    /** @var list<string>|null */
    private ?array $nodeIps = null;

    /** @var Closure(string): list<string> */
    private readonly Closure $resolver;

    /**
     * @param  (Closure(string): list<string>)|null  $resolver  looks up the A and AAAA addresses of a host name
     */
    public function __construct(private readonly CacheRepository $cache, ?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? $this->resolveWithDns(...);
    }

    /**
     * The entries of the "monoforge:privacy:hidden_ips" setting, one IP or CIDR per line
     * (commas are accepted too). Blank lines and lines starting with "#" are skipped.
     *
     * @return list<string>
     */
    public static function hiddenList(?string $value = null): array
    {
        // The setting may be stored empty (null), which means no extra addresses.
        $value ??= (string) config('monoforge.privacy.hidden_ips');

        $entries = [];
        foreach (preg_split('/[\r\n,]+/', $value) ?: [] as $line) {
            $line = mb_trim($line);
            if ($line !== '' && ! str_starts_with($line, '#')) {
                $entries[] = $line;
            }
        }

        return $entries;
    }

    /**
     * Whether an entry of the hidden list is a valid IPv4 or IPv6 address or CIDR range.
     */
    public static function isValidEntry(string $entry): bool
    {
        [$address, $mask] = str_contains($entry, '/') ? explode('/', $entry, 2) : [$entry, null];
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($mask === null) {
            return true;
        }

        $max = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 128 : 32;

        return preg_match('/^\d{1,3}$/', $mask) === 1 && (int) $mask <= $max;
    }

    /**
     * A valid address in a canonical form (IPv4-mapped IPv6 as IPv4, IPv6 compressed and
     * lowercase), or null for anything that is not an IP address.
     */
    public static function normalize(?string $ip): ?string
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        // ::ffff:192.0.2.1 is the IPv4 address 192.0.2.1.
        if (mb_strlen($packed, '8bit') === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $packed = mb_substr($packed, 12, null, '8bit');
        }

        $normalized = inet_ntop($packed);

        return $normalized === false ? null : $normalized;
    }

    /**
     * Whether the address belongs to the panel host, a node or the admin's hidden list.
     */
    public function contains(?string $ip): bool
    {
        $ip = self::normalize($ip);
        if ($ip === null) {
            return false;
        }

        try {
            return IpUtils::checkIp($ip, self::RESERVED_RANGES)
                || in_array($ip, $this->nodeIps(), true)
                || IpUtils::checkIp($ip, self::hiddenList());
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The addresses the node FQDNs resolve to. Cached per set of FQDNs, so adding or
     * changing a node is picked up straight away.
     *
     * @return list<string>
     */
    public function nodeIps(): array
    {
        if ($this->nodeIps !== null) {
            return $this->nodeIps;
        }

        try {
            /** @var list<string> $fqdns */
            $fqdns = Node::query()->orderBy('id')->pluck('fqdn')->filter(fn (mixed $fqdn): bool => is_string($fqdn) && $fqdn !== '')->unique()->values()->all();
        } catch (Throwable) {
            return $this->nodeIps = [];
        }

        if ($fqdns === []) {
            return $this->nodeIps = [];
        }

        $key = self::CACHE_PREFIX.hash('sha256', implode("\n", $fqdns));

        try {
            $cached = $this->cache->get($key);
            if (is_array($cached)) {
                return $this->nodeIps = array_values(array_filter($cached, is_string(...)));
            }
        } catch (Throwable) {
        }

        $ips = [];
        foreach ($fqdns as $fqdn) {
            $literal = self::normalize(mb_trim($fqdn, '[]'));
            if ($literal !== null) {
                $ips[] = $literal;

                continue;
            }

            try {
                foreach (($this->resolver)($fqdn) as $resolved) {
                    $resolved = self::normalize($resolved);
                    if ($resolved !== null) {
                        $ips[] = $resolved;
                    }
                }
            } catch (Throwable) {
            }
        }

        $ips = array_values(array_unique($ips));

        try {
            $this->cache->put($key, $ips, config()->integer('monoforge.privacy.node_dns_ttl', 3600));
        } catch (Throwable) {
        }

        return $this->nodeIps = $ips;
    }

    /**
     * @return list<string>
     */
    private function resolveWithDns(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records)) {
            return [];
        }

        $ips = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }

        return $ips;
    }
}
