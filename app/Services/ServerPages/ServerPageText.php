<?php

declare(strict_types=1);

namespace Pterodactyl\Services\ServerPages;

/**
 * Owner text made safe to show as plain text: one line, no control characters or Minecraft colour
 * codes, and clamped. It is still shown as text (never HTML) by the page.
 */
final class ServerPageText
{
    public const int TAGLINE_MAX = 80;

    public const int NAME_MAX = 64;

    public const int EXCERPT_MAX = 160;

    /** One line of text, clamped to $max characters with an ellipsis; empty when nothing is left. */
    public static function line(?string $value, int $max): string
    {
        $value = preg_replace(['/\x{00A7}./u', '/[\x00-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', '/\s+/u'], ['', ' ', ' '], $value ?? '') ?? '';
        $value = mb_trim($value);

        return mb_strlen($value) > $max ? mb_rtrim(mb_substr($value, 0, $max - 1)).'…' : $value;
    }

    /** Markdown reduced to a short plain-text excerpt for a card. */
    public static function excerpt(?string $markdown, int $max = self::EXCERPT_MAX): string
    {
        $text = preg_replace(['/!\[[^\]]*\]\([^)]*\)/', '/\[([^\]]*)\]\([^)]*\)/', '/[*_`#>~|]+/', '/^\s*(?:[-+]|\d+[.)])\s+/m'], ['', '$1', '', ''], strip_tags($markdown ?? ''));

        return self::line($text, $max);
    }
}
