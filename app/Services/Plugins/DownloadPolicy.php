<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Plugins;

/**
 * What the one-click installer may download, and where it may put it. Wings downloads the
 * files itself, so the panel only ever hands it HTTPS URLs on the catalogs' own CDNs, and
 * only ever writes to relative paths inside the server root.
 */
final class DownloadPolicy
{
    /** The only hosts files are downloaded from. */
    public const array ALLOWED_HOSTS = [
        'cdn.modrinth.com',
        'hangarcdn.papermc.io',
        'mediafilez.forgecdn.net',
        'edge.forgecdn.net',
    ];

    /** Whether Wings may be asked to download this URL: HTTPS, an allowed host, no credentials or odd port. */
    public static function allowedUrl(string $url): bool
    {
        if ($url === '' || mb_strlen($url) > 2048 || preg_match('/[\s\x00-\x1f\x7f\\\\]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if (isset($parts['port']) && $parts['port'] !== 443) {
            return false;
        }

        return in_array(mb_strtolower($parts['host'] ?? ''), self::ALLOWED_HOSTS, true);
    }

    /**
     * A path from a modpack index or archive as a clean relative path, or null when it could
     * leave the server root: absolute paths, drive letters, backslashes, "." or ".." segments,
     * control characters and empty segments are all refused.
     */
    public static function relativePath(string $path): ?string
    {
        if ($path === '' || mb_strlen($path) > 512 || preg_match('/[\x00-\x1f\x7f\\\\:*?"<>|]/', $path) === 1) {
            return null;
        }

        if (str_starts_with($path, '/') || str_starts_with($path, '~')) {
            return null;
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if (in_array($segment, ['', '.', '..'], true) || mb_trim($segment) !== $segment) {
                return null;
            }
        }

        return implode('/', $segments);
    }

    /** A jar's file name as a safe single path segment, or null. */
    public static function jarName(string $name): ?string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._+\-()\[\] !]{0,190}\.jar$/', $name) !== 1 || str_contains($name, '..')) {
            return null;
        }

        return $name;
    }
}
