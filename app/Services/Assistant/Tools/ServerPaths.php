<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools;

/**
 * Path handling for the file tools: normalising the paths the model passes and spotting
 * files that likely hold secrets, which are only read after the user confirms.
 */
final class ServerPaths
{
    /**
     * Basename patterns (matched case-insensitively) of files that may hold secrets.
     */
    public const array SENSITIVE_PATTERNS = [
        // Environment files.
        '.env',
        '*.env',
        '.env.*',
        // Keys, certificates and key stores.
        '*.pem',
        '*.key',
        'id_*',
        '*.ppk',
        '*.p12',
        '*.pfx',
        '*.keystore',
        '*.jks',
        '*.kdbx',
        // Credential files of common tools.
        '.htpasswd',
        '.netrc',
        '.npmrc',
        '.pgpass',
        '.my.cnf',
        'wp-config.php',
        'auth.json',
        'credentials*',
        // Game server files that hold passwords or player lists.
        'server.properties',
        'ops.json',
        'whitelist.json',
        // Databases.
        '*.db',
        '*.sqlite',
        '*.sqlite3',
        // Anything named like a secret.
        '*secret*',
        '*token*',
        '*password*',
        '*passwd*',
        '*key*',
        '*credential*',
    ];

    /**
     * Basename patterns of files that are nothing but key material or credentials. Even after
     * the user allows reading one, the model only learns its size.
     */
    public const array KEY_MATERIAL_PATTERNS = [
        '*.pem',
        '*.key',
        'id_*',
        '*.ppk',
        '*.p12',
        '*.pfx',
        '*.keystore',
        '*.jks',
        '*.kdbx',
        '.htpasswd',
        '.netrc',
        '.pgpass',
    ];

    /**
     * An absolute path inside the server with "." and ".." resolved and duplicate slashes
     * removed. A ".." can never climb above the server root.
     */
    public static function normalize(string $path): string
    {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }

        return '/'.implode('/', $segments);
    }

    /**
     * The path joined onto a directory, unless it is already absolute.
     */
    public static function join(string $directory, string $path): string
    {
        return self::normalize(str_starts_with($path, '/') ? $path : $directory.'/'.$path);
    }

    public static function basename(string $path): string
    {
        $normalized = self::normalize($path);

        return $normalized === '/' ? '' : mb_substr($normalized, (int) mb_strrpos($normalized, '/') + 1);
    }

    public static function dirname(string $path): string
    {
        $normalized = self::normalize($path);
        $slash = (int) mb_strrpos($normalized, '/');

        return $slash === 0 ? '/' : mb_substr($normalized, 0, $slash);
    }

    public static function isSensitive(string $path): bool
    {
        return self::matchesAny(self::SENSITIVE_PATTERNS, $path);
    }

    public static function isKeyMaterial(string $path): bool
    {
        return self::matchesAny(self::KEY_MATERIAL_PATTERNS, $path);
    }

    /**
     * Whether a name matches a glob that only uses "*" (any run of characters) and "?" (one
     * character), compared case-insensitively.
     */
    public static function matches(string $pattern, string $name): bool
    {
        $regex = '/^'.str_replace(['\*', '\?'], ['.*', '.'], preg_quote($pattern, '/')).'$/iu';

        return preg_match($regex, $name) === 1;
    }

    /**
     * @param  list<string>  $patterns
     */
    private static function matchesAny(array $patterns, string $path): bool
    {
        $name = mb_strtolower(self::basename($path));
        if ($name === '') {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (self::matches($pattern, $name)) {
                return true;
            }
        }

        return false;
    }
}
