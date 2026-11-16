<?php

declare(strict_types=1);

namespace Pterodactyl\Services\ServerPages;

use Closure;

/**
 * The social links a public server page may show: a Discord invite and a website. Both must be
 * plain https addresses (no credentials, no other ports); the Discord one must be on discord.gg
 * or discord.com. They are shown with rel="nofollow ugc noopener noreferrer".
 */
final class ServerPageLinks
{
    public const int MAX_LENGTH = 255;

    /** The hosts a Discord invite may use. */
    public const array DISCORD_HOSTS = ['discord.gg', 'discord.com', 'www.discord.com'];

    /**
     * @return list<string|Closure>
     */
    public static function rules(bool $discord): array
    {
        return ['sometimes', 'nullable', 'string', 'max:'.self::MAX_LENGTH, function (string $attribute, mixed $value, Closure $fail) use ($discord): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            if ($discord && self::discord($value) === null) {
                $fail('The Discord link must be an https invite on discord.gg or discord.com, such as https://discord.gg/abc123.');
            } elseif (! $discord && self::website($value) === null) {
                $fail('The website must be an https address, such as https://example.com.');
            }
        }];
    }

    /** The invite address when it is a valid https Discord invite, else null. */
    public static function discord(?string $url): ?string
    {
        $parts = self::https($url);
        if ($parts === null || ! in_array($parts['host'], self::DISCORD_HOSTS, true)) {
            return null;
        }

        // discord.gg/<code> or discord.com/invite/<code>; nothing else on those hosts.
        $path = $parts['path'];
        $valid = $parts['host'] === 'discord.gg'
            ? preg_match('#\A/[A-Za-z0-9-]{2,32}/?\z#', $path) === 1
            : preg_match('#\A/invite/[A-Za-z0-9-]{2,32}/?\z#', $path) === 1;

        return $valid ? $url : null;
    }

    /** The address when it is a valid https website, else null. */
    public static function website(?string $url): ?string
    {
        $parts = self::https($url);

        return $parts !== null && str_contains($parts['host'], '.') ? $url : null;
    }

    /**
     * @return array{host: string, path: string}|null
     */
    private static function https(?string $url): ?array
    {
        if (! is_string($url) || $url === '' || mb_strlen($url) > self::MAX_LENGTH || preg_match('/[\s<>"\'\\\\\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }

        $host = mb_strtolower($parts['host'] ?? '');
        if ($host === '' || preg_match('/\A[a-z0-9.-]+\z/', $host) !== 1 || str_starts_with($host, '.') || str_ends_with($host, '.')) {
            return null;
        }

        return ['host' => $host, 'path' => $parts['path'] ?? ''];
    }
}
