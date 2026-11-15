<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools;

/**
 * Masks values that look like secrets before tool output reaches the model: the values of
 * keys named like a password, token, key or secret (in .env, properties, YAML, JSON and PHP
 * files), PEM key blocks and JWTs. The masked text keeps its shape so the model can still
 * explain the file, and write_file refuses to write the placeholder back.
 */
final class SecretRedactor
{
    public const string PLACEHOLDER = '[hidden-secret]';

    /** Words that make a key or variable name look like it holds a secret. */
    private const string WORDS = 'pass|secret|token|key|auth|credential|rcon|api';

    /** Startup variable names whose values are hidden unless the user reveals them. */
    private const string VARIABLE = '/PASS|TOKEN|KEY|SECRET|RCON|API|CREDENTIAL/i';

    public static function isSecretName(string $name): bool
    {
        return preg_match(self::VARIABLE, $name) === 1;
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
        $words = self::WORDS;

        // PEM blocks: private keys, certificates.
        $text = preg_replace('/-----BEGIN [A-Z0-9 ]+-----.*?-----END [A-Z0-9 ]+-----/s', $mask, $text) ?? $text;

        // JSON web tokens.
        $text = preg_replace('/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/', $mask, $text) ?? $text;

        // A quoted value after a quoted secret key: "password": "x", 'DB_PASSWORD', 'x', 'key' => 'x'.
        $text = preg_replace(
            '/((["\'])[\w.\-]*(?:'.$words.')[\w.\-]*\2\s*(?:=>|,|:|=)\s*)(["\'])((?:\\.|(?!\3).)+)\3/i',
            '$1$3'.$mask.'$3',
            $text,
        ) ?? $text;

        // An unquoted secret key at the start of a line: DB_PASSWORD=x, rcon.password=x, token: x.
        return preg_replace(
            '/^(\h*(?:export\h+)?[\w.\-]*(?:'.$words.')[\w.\-]*\h*[:=]\h*)(?!["\']?'.preg_quote($mask, '/').')(\S.*?)\h*$/im',
            '$1'.$mask,
            $text,
        ) ?? $text;
    }
}
