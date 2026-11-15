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
 * "api-version" and "hotkey" are not.
 *
 * Every pattern is linear (possessive quantifiers, no nested alternation that backtracks),
 * and masking fails closed: when PCRE reports an error, the whole text is hidden instead of
 * being passed on unmasked.
 *
 * Masked values are never written back: write_file refuses content with a mask, and edit_file
 * changes the real file only on lines that hold no hidden value.
 */
final class SecretRedactor
{
    public const string PLACEHOLDER = '[hidden-secret]';

    /** The most hidden values hiddenValues() lines up; more and the text counts as unchecked. */
    public const int MAX_HIDDEN_VALUES = 500;

    /** What the whole text becomes when it could not be checked for secrets. */
    public const string UNCHECKED = '[content hidden: could not be checked for secrets]';

    /** Key segments that make a key a secret on their own. */
    private const array SECRET_SEGMENTS = [
        'password', 'passwords', 'passwd', 'pass', 'passphrase', 'pw', 'pwd',
        'secret', 'secrets', 'token', 'tokens', 'apikey', 'credential', 'credentials',
        'key', 'auth', 'gslt', 'webhook',
    ];

    /** Whole names that are secrets although no segment says so (startup variables and file keys). */
    private const array SECRET_NAMES = ['steam_acc'];

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
        $parts = preg_split('/[._\-\s]++/', mb_strtolower($key)) ?: [];

        return array_values(array_filter($parts, fn (string $part): bool => $part !== ''));
    }

    /** Whether a configuration key or startup variable names a secret. */
    public static function isSecretKey(string $key): bool
    {
        return array_intersect(self::segments($key), self::SECRET_SEGMENTS) !== []
            || in_array(mb_strtolower(mb_trim($key)), self::SECRET_NAMES, true);
    }

    /** Whether a startup variable (RCON_PASSWORD, STEAM_ACC, DB_PW...) holds a secret. */
    public static function isSecretName(string $name): bool
    {
        return self::isSecretKey($name);
    }

    public static function containsPlaceholder(string $text): bool
    {
        return str_contains($text, self::PLACEHOLDER) || str_contains($text, self::UNCHECKED);
    }

    /** Whether masking would hide something in the text (or could not check it). */
    public static function hidesSomething(string $text): bool
    {
        $masked = self::tryRedact($text);

        return $masked === null || $masked !== $text;
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
            '/\b([A-Za-z0-9_]++) to "([^\n]*)"$/u',
            fn (array $match): string => self::isSecretName($match[1]) ? $match[1].' to "'.self::PLACEHOLDER.'"' : $match[0],
            $label,
        );
        if ($label === null) {
            return self::UNCHECKED;
        }

        // Labels are at most 191 characters, so the lookaheads stay short.
        return preg_replace('/(?<![\w+-])(?=[\w+-]*\d)(?=[\w+-]*[A-Za-z])[\w+-]{24,}+(?![\w+-])/', self::PLACEHOLDER, $label) ?? self::UNCHECKED;
    }

    /**
     * Log or console text: as redact(), and also "key: value" pairs anywhere in a line, since
     * console lines start with a timestamp ("[12:00:01 INFO]: RCON password: hunter22").
     */
    public static function redactLog(string $text): string
    {
        $masked = self::tryRedact($text);
        if ($masked === null) {
            return self::UNCHECKED;
        }

        return preg_replace_callback(
            '/(?<![\w.\-])([A-Za-z][\w.\-]*+(?:\h[A-Za-z][\w.\-]*+)?)(\h*+[:=]\h*+)(?!\[hidden-secret\])([^\s,;]++)/',
            fn (array $m): string => self::isSecretKey($m[1]) && ! self::maskedOrBlock($m[3]) ? $m[1].$m[2].self::PLACEHOLDER : $m[0],
            $masked,
        ) ?? self::UNCHECKED;
    }

    /** The text with secrets masked, or UNCHECKED when it could not be checked. */
    public static function redact(string $text): string
    {
        return self::tryRedact($text) ?? self::UNCHECKED;
    }

    /**
     * The values masking hides in a text, in order, or null when they cannot be told apart
     * (or the text could not be checked). Used to make sure an edit leaves every hidden value
     * exactly as it was.
     *
     * @return list<string>|null
     */
    public static function hiddenValues(string $text): ?array
    {
        $masked = self::tryRedact($text);
        if ($masked === null) {
            return null;
        }

        $parts = explode(self::PLACEHOLDER, $masked);
        if (count($parts) === 1) {
            return [];
        }

        // Lining up many values is slow on large files; such files are not edited here.
        if (count($parts) - 1 > self::MAX_HIDDEN_VALUES) {
            return null;
        }

        $first = array_shift($parts);
        $last = (string) array_pop($parts);
        if (! str_starts_with($text, $first) || ! str_ends_with($text, $last)) {
            return null;
        }

        // Each value is the text between one unchanged part and the next (byte offsets).
        $end = mb_strlen($text) - mb_strlen($last);
        $position = mb_strlen($first);
        $values = [];

        foreach ($parts as $part) {
            $next = $part === '' ? false : mb_strpos($text, $part, $position);
            if ($next === false || $next + mb_strlen($part) > $end) {
                return null;
            }

            $values[] = mb_substr($text, $position, $next - $position);
            $position = $next + mb_strlen($part);
        }

        if ($position > $end) {
            return null;
        }

        $values[] = mb_substr($text, $position, $end - $position);

        return $values;
    }

    /** The masked text, or null when any pattern failed (fail closed). */
    private static function tryRedact(string $text): ?string
    {
        if ($text === '') {
            return $text;
        }

        $mask = self::PLACEHOLDER;

        // PEM blocks (private keys, certificates), also when the END line is missing.
        $text = preg_replace('/-----BEGIN [A-Z0-9 ]++-----(?:[^-]++|-(?!----END ))*+(?:-----END [A-Z0-9 ]++-----|\z)/', $mask, $text);
        if ($text === null) {
            return null;
        }

        // JSON web tokens.
        $text = preg_replace('/\beyJ[A-Za-z0-9_-]{8,}+\.[A-Za-z0-9_-]{8,}+\.[A-Za-z0-9_-]{8,}+/', $mask, $text);
        if ($text === null) {
            return null;
        }

        // Credentials inside a URL: mysql://root:pw@db/mc.
        $text = preg_replace('#\b([a-z][a-z0-9+.\-]*+://[^\s:/@"\']++:)[^\s@/"\']++@#i', '$1'.$mask.'@', $text);
        if ($text === null) {
            return null;
        }

        // Discord webhook tokens.
        $text = preg_replace('#(https?://(?:[\w-]++\.)?discord(?:app)?\.com/api/webhooks/\d++/)[\w-]++#i', '$1'.$mask, $text);
        if ($text === null) {
            return null;
        }

        // A quoted value after a quoted secret key, on one line: "password": "x",
        // 'DB_PASSWORD', 'x', 'key' => 'x'. One pattern per quote, each linear.
        foreach (['"' => '"(?:[^"\\\\\n]++|\\\\.)++"', "'" => "'(?:[^'\\\\\n]++|\\\\.)++'"] as $quote => $value) {
            $text = preg_replace_callback(
                '/(["\'])([\w.\-]++)\1(\h*+(?:=>|,|:|=)\h*+)('.$value.')/',
                fn (array $m): string => self::isSecretKey($m[2]) && $m[4] !== $quote.$mask.$quote
                    ? $m[1].$m[2].$m[1].$m[3].$quote.$mask.$quote
                    : $m[0],
                $text,
            );
            if ($text === null) {
                return null;
            }
        }

        // An unquoted secret key at the start of a line, also as a YAML list item:
        // DB_PASSWORD=x, rcon.password=x, token: x, - password: x. A "\r" stays outside.
        return preg_replace_callback(
            '/^(\h*+(?:-\h++)?(?:export\h++)?)([\w.\-]++)(\h*+[:=]\h*+)(\S[^\r\n]*+)/m',
            function (array $m) use ($mask): string {
                $value = mb_rtrim($m[4]);

                return self::isSecretKey($m[2]) && ! self::maskedOrBlock($value) ? $m[1].$m[2].$m[3].$mask : $m[0];
            },
            $text,
        );
    }

    private static function maskedOrBlock(string $value): bool
    {
        $value = mb_trim($value, " \t\"'");

        return $value === self::PLACEHOLDER || in_array($value, self::BLOCK_MARKERS, true);
    }
}
