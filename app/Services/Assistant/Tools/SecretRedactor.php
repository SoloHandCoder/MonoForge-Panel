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
 * restore() puts the original values back when the model writes a masked file, so it can
 * edit the rest of a config. It is strict: every mask must sit at the same key path as in the
 * file on the server, and the result must mask back to exactly what the model wrote.
 */
final class SecretRedactor
{
    public const string PLACEHOLDER = '[hidden-secret]';

    /** What the model is told when restore() refuses a write. */
    public const string MOVED = 'This edit moves or changes a hidden value; leave hidden values where they are. Keep every '.self::PLACEHOLDER.' exactly as it was read, under the same key, with the text around it unchanged, or ask the user to edit that value in the file manager. Nothing was written.';

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
        $parts = preg_split('/[._\-\s]+/', mb_strtolower($key)) ?: [];

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

    /**
     * Log or console text: as redact(), and also "key: value" pairs anywhere in a line, since
     * console lines start with a timestamp ("[12:00:01 INFO]: RCON password: hunter22").
     */
    public static function redactLog(string $text): string
    {
        $text = self::redact($text);

        return preg_replace_callback(
            '/(?<![\w.\-])([A-Za-z][\w.\-]*(?:\h[A-Za-z][\w.\-]*)?)(\h*[:=]\h*)(?!\[hidden-secret\])([^\s,;]+)/',
            fn (array $m): string => self::isSecretKey($m[1]) && ! self::maskedOrBlock($m[3]) ? $m[1].$m[2].self::PLACEHOLDER : $m[0],
            $text,
        ) ?? $text;
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
     * $original. Returns null (refuse the write) unless:
     *
     *  - every mask sits at a known key path (YAML parents by indentation, JSON and PHP array
     *    nesting, the key of a properties or env line), or, for a mask without a key (a PEM
     *    block, a value in a block scalar), on lines identical to the masked original;
     *  - each of those paths holds as many masks as in the original;
     *  - masking the restored file gives back exactly the content the model wrote, so no
     *    value was moved out of a masked position (a quote or "@" moved, for example).
     */
    public static function restore(string $content, string $original): ?string
    {
        if (! self::containsPlaceholder($content)) {
            return $content;
        }

        $masked = self::redact($original);
        $values = self::maskedValues($masked, $original);
        if ($values === null) {
            return null;
        }

        $byPath = [];
        foreach (self::keyPaths($masked) as $index => $path) {
            $byPath[$path][] = $values[$index];
        }

        $paths = self::keyPaths($content);
        foreach (array_count_values($paths) as $path => $count) {
            if (count($byPath[$path] ?? []) !== $count) {
                return null;
            }
        }

        $parts = explode(self::PLACEHOLDER, $content);
        $restored = array_shift($parts);
        foreach ($parts as $index => $part) {
            $restored .= array_shift($byPath[$paths[$index]]).$part;
        }

        return self::redact($restored) === $content ? $restored : null;
    }

    /**
     * The values a masked copy hides, in order. Found by lining the masked copy up against
     * the original: each value is the text between one unchanged part and the next.
     *
     * @return list<string>|null
     */
    private static function maskedValues(string $masked, string $original): ?array
    {
        $parts = explode(self::PLACEHOLDER, $masked);
        if (count($parts) === 1) {
            return [];
        }

        $first = array_shift($parts);
        $last = (string) array_pop($parts);
        if (! str_starts_with($original, $first) || ! str_ends_with($original, $last)) {
            return null;
        }

        $end = mb_strlen($original) - mb_strlen($last);
        $position = mb_strlen($first);
        $values = [];

        foreach ($parts as $part) {
            // Two masks with nothing between them cannot be told apart.
            $next = $part === '' ? false : mb_strpos($original, $part, $position);
            if ($next === false || $next + mb_strlen($part) > $end) {
                return null;
            }

            $values[] = mb_substr($original, $position, $next - $position);
            $position = $next + mb_strlen($part);
        }

        if ($position > $end) {
            return null;
        }

        $values[] = mb_substr($original, $position, $end - $position);

        return $values;
    }

    /**
     * The key path of every mask in a text, in order.
     *
     * @return list<string>
     */
    private static function keyPaths(string $text): array
    {
        $lines = explode("\n", $text);
        $paths = [];

        foreach ($lines as $number => $line) {
            $pieces = explode(self::PLACEHOLDER, $line);
            $before = '';
            foreach (array_slice($pieces, 0, -1) as $index => $piece) {
                $before .= ($index > 0 ? self::PLACEHOLDER : '').$piece;
                $path = self::keyPath($lines, $number, $before, $pieces[$index + 1] ?? '');
                // Masks without a key are told apart by their place on the (identical) line.
                $paths[] = str_starts_with($path, 'lines:') ? $path.'#'.$index : $path;
            }
        }

        return $paths;
    }

    /**
     * The key path of a mask between $before and $after on line $number.
     *
     * @param  list<string>  $lines
     */
    private static function keyPath(array $lines, int $number, string $before, string $after): string
    {
        $line = $lines[$number];

        // "password": "…, 'DB_PASSWORD', '…, 'key' => '…: the key inside its JSON or PHP nesting.
        if (preg_match('/(["\'])([\w.\-]+)\1\h*(?:=>|,|:|=)\h*["\']$/', $before, $m) === 1) {
            return 'nested:'.implode('/', [...self::nestingParents(implode("\n", array_slice($lines, 0, $number))."\n".$before), $m[2]]);
        }

        $lineKey = preg_match('/^(\h*)(?:export\h+)?["\']?([\w.\-]+)["\']?\h*(?:=>|[:=])/', $line, $k) === 1 ? $k : null;
        $keyPath = $lineKey === null ? null : implode('/', [...self::indentParents($lines, $number, mb_strlen($lineKey[1])), $lineKey[2]]);

        // scheme://user:…@host inside a URL, or a webhook token: under the line's key, if any.
        // The host is part of the path, so a password cannot be pointed at another server.
        if (preg_match('#([a-z][a-z0-9+.\-]*://[^\s:/@"\']+):$#i', $before, $u) === 1) {
            $host = preg_match('#^@([^\s/"\'?\#]*)#', $after, $h) === 1 ? mb_strtolower($h[1]) : '?';

            return 'url:'.($keyPath ?? '?'.mb_trim($line)).'@'.mb_strtolower($u[1]).'@'.$host;
        }

        if (preg_match('#/api/webhooks/(\d+)/$#', $before, $w) === 1) {
            return 'webhook:'.($keyPath ?? '?'.mb_trim($line)).'@'.$w[1];
        }

        // key: …, KEY=…: the key under its YAML parents.
        if ($lineKey !== null && preg_match('/^\h*(?:export\h+)?[\w.\-]+\h*[:=]\h*$/', $before) === 1) {
            return 'key:'.$keyPath;
        }

        // No key (a PEM block, a block scalar body): only the identical lines around it match.
        return 'lines:'.($lines[$number - 1] ?? '')."\n".$line;
    }

    /**
     * The YAML keys a line at the given indentation is nested under, outermost first.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private static function indentParents(array $lines, int $number, int $indent): array
    {
        $parents = [];
        for ($i = $number - 1; $i >= 0 && $indent > 0; $i--) {
            $line = $lines[$i];
            if (mb_trim($line) === '' || str_starts_with(mb_ltrim($line), '#')) {
                continue;
            }

            $own = mb_strlen($line) - mb_strlen(mb_ltrim($line, " \t"));
            if ($own >= $indent) {
                continue;
            }

            if (preg_match('/^\h*(-\h+)?["\']?([\w.\-]+)["\']?\h*:\h*(?:[|>][+-]?)?\h*$/', $line, $m) === 1) {
                array_unshift($parents, ($m[1] !== '' ? '-' : '').$m[2]);
            } else {
                array_unshift($parents, mb_trim($line));
            }

            $indent = $own;
        }

        return $parents;
    }

    /**
     * The keys of the JSON objects or PHP arrays open at the end of $text, outermost first;
     * an opening without a key counts as "[]".
     *
     * @return list<string>
     */
    private static function nestingParents(string $text): array
    {
        $stack = [];
        $tokens = '/(["\'])((?:\\\\.|(?!\1).)*)\1(\h*(?::|=>)\h*)?([\[{(])?|([\[{(])|([\]})])/s';
        preg_match_all($tokens, $text, $matches, PREG_SET_ORDER);

        foreach ($matches as $m) {
            if (($m[6] ?? '') !== '') {
                array_pop($stack);
            } elseif (($m[5] ?? '') !== '') {
                $stack[] = '[]';
            } elseif (($m[4] ?? '') !== '') {
                $stack[] = ($m[3] ?? '') !== '' ? ($m[2] ?? '') : '[]';
            }
        }

        return $stack;
    }

    private static function maskedOrBlock(string $value): bool
    {
        $value = mb_trim($value, " \t\"'");

        return $value === self::PLACEHOLDER || in_array($value, self::BLOCK_MARKERS, true);
    }
}
