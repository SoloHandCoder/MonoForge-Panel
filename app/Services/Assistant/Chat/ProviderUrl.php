<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Chat;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Which provider base URLs the panel may call. Providers are configured by root admins and
 * may point at a local Ollama, so loopback and private addresses are allowed, but cloud
 * metadata endpoints (which hand out credentials) never are.
 */
final class ProviderUrl
{
    /** @var list<string> */
    private const array BLOCKED_HOSTS = [
        'metadata',
        'metadata.google.internal',
        'metadata.azure.internal',
        'instance-data',
        'instance-data.ec2.internal',
    ];

    /** @var list<string> */
    private const array BLOCKED_RANGES = [
        '169.254.0.0/16',
        '100.100.100.200/32',
        'fe80::/10',
        'fd00:ec2::254/128',
    ];

    public static function isAllowed(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (! in_array(mb_strtolower($parts['scheme']), ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = mb_strtolower(mb_trim($parts['host'], '[]'));
        if ($host === '' || in_array(mb_rtrim($host, '.'), self::BLOCKED_HOSTS, true)) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return ! IpUtils::checkIp($host, self::BLOCKED_RANGES);
        }

        // Decimal, hex or octal spellings of an address (2852039166, 0xa9fea9fe, 0251.0376.0251.0376)
        // are not valid IPs to PHP but are to curl; only plain dotted addresses are accepted.
        return preg_match('/^((0x[0-9a-f]*|[0-9]+)\.){0,3}(0x[0-9a-f]*|[0-9]+)$/i', $host) !== 1;
    }

    /** The chat completions endpoint under a base URL such as https://api.cline.bot/api/v1. */
    public static function completions(string $baseUrl): string
    {
        return mb_rtrim($baseUrl, '/').'/chat/completions';
    }
}
