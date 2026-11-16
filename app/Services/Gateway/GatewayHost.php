<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Gateway;

use Pterodactyl\Models\Node;

/**
 * The public host players join through the game gateway: a DNS name such as
 * `play.monoforge.net` (an A record pointing at the VPS, DNS only), or the VPS's public IPv4
 * address. It is never the node's own address, which the gateway exists to hide.
 */
final class GatewayHost
{
    public const int MAX_LENGTH = 253;

    /** Lowercase labels of letters, digits and inner hyphens, at least two of them, the last not all digits. */
    private const string HOSTNAME = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?=[a-z0-9-]*[a-z])[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D';

    public static function normalize(string $host): string
    {
        return mb_rtrim(mb_strtolower(mb_trim($host)), '.');
    }

    public static function isIpv4(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /**
     * Why the host is not accepted for the node, or null when it is.
     */
    public static function error(string $host, ?Node $node = null): ?string
    {
        $host = self::normalize($host);
        if ($host === '') {
            return 'Enter the public host players join, such as play.example.com.';
        }

        if (self::isIpv4($host)) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return 'The public host must be a public IPv4 address, not a private or reserved one.';
            }
        } elseif (preg_match(self::HOSTNAME, $host) !== 1) {
            return 'The public host must be a host name such as play.example.com or a public IPv4 address (no port, no https://).';
        }

        if ($node instanceof Node && $host === self::normalize((string) $node->fqdn)) {
            return "The public host must be the gateway's address, not the node's own: players would see the node's address.";
        }

        return null;
    }
}
