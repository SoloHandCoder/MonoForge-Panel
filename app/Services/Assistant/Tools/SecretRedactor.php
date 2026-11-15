<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools;

/**
 * Masks values that look like secrets before tool output reaches the model: the values of
 * keys named like a password, token, key or secret (in .env, properties, YAML, JSON and PHP
 * files), credentials inside URLs, Discord webhook tokens, PEM key blocks and JWTs.
 *
 * A key is judged by its whole segments (split on ".", "_", "-", spaces and camelCase), so
 * "rcon.password" and "api_key" are secrets but "rcon.port", "enable-rcon", "author",
 * "api-version" and "hotkey" are not. The masked text keeps its shape, and restore() puts the
 * original values back when the model writes a masked file, so it can still edit the rest.
 */
final class SecretRedactor
{
    public const string PLACEHOLDER = '[hidden-secret]';

    /** Key segments that make a key a secret on their own. */
    private const array SECRET_SEGMENTS = [
        'password', 'passwords', 'passwd', 'pass', 'passphrase', 'pw', 'pwd',
        'secret', 'secrets', 'token', 'tokens', 'apikey', 'credential', 'credentials',
        'key', 'auth', 'gslt', 'webhook',
    ];

    /** Startup variable names that are secrets although no segment says so. */
    private const array SECRET_VARIABLES = ['steam_acc'];

    /** YAML block scalar markers: the value is on the following lines, not this one. */
    private const array BLOCK_MARKERS = ['|', '>', '|-', '>-', '|+', '>+'];

    /**
     * The segments of a key, lowercased: "rconPassword" and "rcon.password" both give [rcon, password].
     *
     * @return list<string>
     */
    public static function segments(string $key): array
    {
        $key = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $key) ?? $key;
        $parts = preg_split('/[._\-\s]+/', mb_strtolower($key)) ?: [];

        return array_values(array_filter($parts, fn (string $part): bool => $part !== ''));
    }

    /** Whether a configuration key names a secret, judged by its whole segments. */
    public static function isSecretKey(string $key): bool
    {
        return array_intersect(self::segments($key), self::SECRET_SEGMENTS) !== [];
    }

    /** Whether a startup variable (RCON_PASSWORD, STEAM_ACC, DB_PW...) holds a secret. */
    public static function isSecretName(string $name): bool
    {
        return self::isSecretKey($name) || in_array(mb_strtolower($name), self::SECRET_VARIABLES, true);
    }

    public static function containsPlaceholder(string $text): bool
    {
        return str_contains($text, self::PLACEHOLDER);
    }

    /**
     * A tool call label for the activity log, which other users of the server can read: the
     * value of a secret-named variable ("Set RCON_PASSWORD to ...") and long token-like
     * strings are masked as well.
     */
    public static function redactLabel(string $label): string
    {
        $label = self::redact($label);

        $label = preg_replace_callback(
            '/\b([A-Za-z0-9_]+) to "(.*)"$/u',
            fn (array $match): string => self::isSecretName($match[1]) ? $match[1].' to "'.self::PLACEHOLDER.'"' : $match[0],
            $label,
        ) ?? $label;

        return preg_replace('/(?<![\w+-])(?=[\w+-]*\d)(?=[\w+-]*[A-Za-z])[\w+-]{24,}(?![\w+-])/', self::PLACEHOLDER, $label) ?? $label;
    }

    public static function redact(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $mask = self::PLACEHOLDER;

        // PEM blocks (private keys, certificates), also when the END line is missing.
        $text = preg_replace('/-----BEGIN [A-Z0-9 ]+-----.*?(?:-----END [A-Z0-9 ]+-----|\z)/s', $mask, $text) ?? $text;

        // JSON web tokens.
        $text = preg_replace('/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/', $mask, $text) ?? $text;

        // Credentials inside a URL: mysql://root:pw@db/mc.
        $text = preg_replace('#\b([a-z][a-z0-9+.\-]*://[^\s:/@"\']+:)([^\s@/"\']+)@#i', '$1'.$mask.'@', $text) ?? $text;

        // Discord webhook tokens.
        $text = preg_replace('#(https?://(?:[\w-]+\.)?discord(?:app)?\.com/api/webhooks/\d+/)[\w-]+#i', '$1'.$mask, $text) ?? $text;

        // A quoted value after a quoted secret key: "password": "x", 'DB_PASSWORD', 'x', 'key' => 'x'.
        $text = preg_replace_callback(
            '/(["\'])([\w.\-]+)\1(\h*(?:=>|,|:|=)\h*)(["\'])((?:\\\\.|(?!\4).)+)\4/',
            fn (array $m): string => self::isSecretKey($m[2]) && $m[5] !== $mask ? $m[1].$m[2].$m[1].$m[3].$m[4].$mask.$m[4] : $m[0],
            $text,
        ) ?? $text;

        // An unquoted secret key at the start of a line: DB_PASSWORD=x, rcon.password=x, token: x.
        return preg_replace_callback(
            '/^(\h*(?:export\h+)?)([\w.\-]+)(\h*[:=]\h*)(\S.*?)\h*$/m',
            fn (array $m): string => self::isSecretKey($m[2]) && ! self::maskedOrBlock($m[4])
                ? $m[1].$m[2].$m[3].$mask
                : $m[0],
            $text,
        ) ?? $text;
    }

    /**
     * Puts the original values back into content the model wrote from a masked copy of
     * $original: each placeholder takes the value that was masked after the same text on its
     * line (in order, when that text appears more than once). Returns null when a placeholder
     * has no such original value, so nothing is written with a placeholder in it.
     */
    public static function restore(string $content, string $original): ?string
    {
        if (! self::containsPlaceholder($content)) {
            return $content;
        }

        $originals = self::originals($original);
        if ($originals === null) {
            return null;
        }

        $parts = explode(self::PLACEHOLDER, $content);
        $restored = array_shift($parts);
        $before = $restored;

        foreach ($parts as $part) {
            $prefix = self::linePrefix($before);
            if (($originals[$prefix] ?? []) === []) {
                return null;
            }

            $restored .= array_shift($originals[$prefix]).$part;
            $before = $part;
        }

        return $restored;
    }

    /**
     * The masked values of a text, by the trimmed text before them on their line, in order.
     * Found by lining the masked copy up against the original.
     *
     * @return array<string, list<string>>|null
     */
    private static function originals(string $original): ?array
    {
        $parts = explode(self::PLACEHOLDER, self::redact($original));
        if (count($parts) === 1) {
            return [];
        }

        // The masked copy is the original with some values replaced, so each value is the text
        // between one unchanged part and the next (the first place the next part occurs).
        $first = array_shift($parts);
        $last = (string) array_pop($parts);
        if (! str_starts_with($original, $first) || ! str_ends_with($original, $last)) {
            return null;
        }

        $end = mb_strlen($original) - mb_strlen($last);
        $position = mb_strlen($first);
        $before = $first;
        $originals = [];

        foreach ($parts as $part) {
            // Two placeholders with nothing between them cannot be told apart.
            $next = $part === '' ? false : mb_strpos($original, $part, $position);
            if ($next === false || $next + mb_strlen($part) > $end) {
                return null;
            }

            $originals[self::linePrefix($before)][] = mb_substr($original, $position, $next - $position);
            $position = $next + mb_strlen($part);
            $before = $part;
        }

        if ($position > $end) {
            return null;
        }

        $originals[self::linePrefix($before)][] = mb_substr($original, $position, $end - $position);

        return $originals;
    }

    /** The trimmed text on the last line of $text, which is what precedes a placeholder. */
    private static function linePrefix(string $text): string
    {
        $newline = mb_strrpos($text, "\n");

        return mb_trim($newline === false ? $text : mb_substr($text, $newline + 1));
    }

    private static function maskedOrBlock(string $value): bool
    {
        $value = mb_trim($value, " \t\"'");

        return $value === self::PLACEHOLDER || in_array($value, self::BLOCK_MARKERS, true);
    }
}
