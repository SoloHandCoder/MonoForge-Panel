<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Tunnels;

/**
 * Cleans a playit.gg agent secret or agent id pasted by an administrator. People copy lines
 * straight out of playit.toml, such as `secret_key = "ab12..."`, so the key name, the equals
 * sign, one pair of quotes and surrounding whitespace are taken off before it is validated.
 */
final class PlayitKey
{
    /** What a cleaned secret must look like: playit's are hex; url-safe characters are allowed too. */
    public const string SECRET_PATTERN = '/^[A-Za-z0-9_-]{16,512}$/';

    public const string SECRET_KEY = 'secret_key';

    public const string AGENT_ID = 'agent_id';

    /**
     * The value without whitespace, its `name =` prefix or one pair of surrounding quotes.
     * Anything that is not a string is returned unchanged so validation can refuse it.
     */
    public static function clean(mixed $value, string $name): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = self::unquote(mb_trim($value));

        $prefix = '/^'.preg_quote($name, '/').'\s*=\s*/i';
        if (preg_match($prefix, $value) === 1) {
            return self::unquote(mb_trim((string) preg_replace($prefix, '', $value, 1)));
        }

        return $value;
    }

    private static function unquote(string $value): string
    {
        if (mb_strlen($value) >= 2) {
            $first = $value[0];
            if (($first === '"' || $first === "'") && str_ends_with($value, $first)) {
                return mb_trim(mb_substr($value, 1, -1));
            }
        }

        return $value;
    }
}
