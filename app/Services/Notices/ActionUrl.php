<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Notices;

/**
 * The links a notice may carry: a path inside the panel, or an absolute http(s) URL.
 * The panel's notice stack applies the same rules before it renders a link.
 */
final class ActionUrl
{
    /** The URL when it is safe to link to, null otherwise. */
    public static function sanitize(?string $url): ?string
    {
        if ($url === null || $url === '' || mb_strlen($url) > 2048) {
            return null;
        }

        // Browsers drop whitespace and control characters and read "\" as "/", so "/\evil.example"
        // and "/<tab>/evil.example" would leave the panel.
        if (preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            return null;
        }

        if (str_starts_with($url, '/')) {
            return str_starts_with($url, '//') ? null : $url;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($scheme) && in_array(mb_strtolower($scheme), ['http', 'https'], true) && is_string($host) && $host !== ''
            ? $url
            : null;
    }
}
