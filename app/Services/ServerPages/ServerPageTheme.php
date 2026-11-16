<?php

declare(strict_types=1);

namespace Pterodactyl\Services\ServerPages;

use Pterodactyl\Models\Share\ServerPage;

/**
 * The look an owner chose for a public server page, as the public page and the discovery card
 * draw it: an accent colour, a background (solid, a gradient between two colours, or the
 * banner image) and the button shape. Owners pick colours only, never CSS, and the text colour
 * is never theirs: it is worked out here so every combination stays readable (WCAG AA, 4.5:1).
 *
 * @phpstan-type Theme array{
 *     accent: string,
 *     accent_text: string,
 *     background: array{style: 'solid'|'gradient'|'image', from: string, to: string|null, image_url: string|null},
 *     text: string,
 *     button_style: 'rounded'|'square',
 * }
 */
final class ServerPageTheme
{
    public const string HEX_PATTERN = '/\A#[0-9a-fA-F]{6}\z/';

    /** The brand violet a page without an accent falls back to. */
    public const string DEFAULT_ACCENT = '#8b5cf6';

    /** The dark backdrop of the public pages, the default background. */
    public const string DEFAULT_BACKGROUND = '#241a44';

    public const array BACKGROUND_STYLES = ['solid', 'gradient', 'image'];

    public const array BUTTON_STYLES = ['rounded', 'square'];

    /** WCAG AA for normal text. */
    public const float MIN_CONTRAST = 4.5;

    public const string LIGHT_TEXT = '#ffffff';

    public const string DARK_TEXT = '#000000';

    /**
     * @return Theme
     */
    public static function for(ServerPage $page): array
    {
        $accent = self::color($page->banner_color) ?? self::DEFAULT_ACCENT;
        $from = self::color($page->background_from) ?? self::DEFAULT_BACKGROUND;
        $to = self::color($page->background_to);
        $image = $page->bannerUrl();

        $style = match ($page->background_style) {
            'gradient' => $to === null ? 'solid' : 'gradient',
            // Without an image there is nothing to show: the colour stands in.
            'image' => $image === null ? ($to === null ? 'solid' : 'gradient') : 'image',
            default => 'solid',
        };

        // A banner image always gets a dark scrim behind the text, so the text is white.
        $text = match ($style) {
            'image' => self::LIGHT_TEXT,
            'gradient' => self::textFor($from, $to) ?? self::LIGHT_TEXT,
            default => self::textOn($from),
        };

        return [
            'accent' => $accent,
            'accent_text' => self::textOn($accent),
            'background' => [
                'style' => $style,
                'from' => $from,
                'to' => $style === 'solid' ? null : $to,
                'image_url' => $style === 'image' ? $image : null,
            ],
            'text' => $text,
            'button_style' => $page->button_style === 'square' ? 'square' : 'rounded',
        ];
    }

    /** A valid `#rrggbb` in lowercase, or null. */
    public static function color(?string $value): ?string
    {
        return is_string($value) && preg_match(self::HEX_PATTERN, $value) === 1 ? mb_strtolower($value) : null;
    }

    /** White or black, whichever reads better on the colour: one of them always reaches 4.5:1. */
    public static function textOn(string $background): string
    {
        return self::contrast(self::LIGHT_TEXT, $background) >= self::contrast(self::DARK_TEXT, $background)
            ? self::LIGHT_TEXT
            : self::DARK_TEXT;
    }

    /**
     * The text colour that reads on both ends of a gradient, or null when neither white nor black
     * reaches 4.5:1 on both (a gradient from near-black to near-white, say).
     */
    public static function textFor(string $from, ?string $to): ?string
    {
        if ($to === null) {
            return self::textOn($from);
        }

        $best = null;
        $bestContrast = 0.0;
        foreach ([self::LIGHT_TEXT, self::DARK_TEXT] as $text) {
            $worst = min(self::contrast($text, $from), self::contrast($text, $to));
            if ($worst >= self::MIN_CONTRAST && $worst > $bestContrast) {
                $best = $text;
                $bestContrast = $worst;
            }
        }

        return $best;
    }

    /** The WCAG contrast ratio of two `#rrggbb` colours, from 1 to 21. */
    public static function contrast(string $a, string $b): float
    {
        $first = self::luminance($a);
        $second = self::luminance($b);

        return (max($first, $second) + 0.05) / (min($first, $second) + 0.05);
    }

    /** The WCAG relative luminance of a `#rrggbb` colour. */
    public static function luminance(string $hex): float
    {
        $channels = [];
        foreach ([1, 3, 5] as $offset) {
            $value = hexdec(mb_substr($hex, $offset, 2)) / 255;
            $channels[] = $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
