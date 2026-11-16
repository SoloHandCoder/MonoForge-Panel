/* oxlint-disable rules/design-tokens -- owner-chosen page colours are data, and the text colours are the two WCAG extremes */
import type { CSSProperties } from 'react';
import type { PublicServerPage } from '@/api/public/serverPage';

/**
 * The look of a public server page as the panel sends it (the same rules as
 * app/Services/ServerPages/ServerPageTheme.php). Used for the live preview on the share settings
 * page, where the owner's unsaved colours have no theme from the panel yet.
 */
export type ServerPageTheme = PublicServerPage['theme'];
export type BackgroundStyle = ServerPageTheme['background']['style'];
export type ButtonStyle = ServerPageTheme['button_style'];

export const DEFAULT_ACCENT = '#8b5cf6';
export const DEFAULT_BACKGROUND = '#241a44';
export const LIGHT_TEXT = '#ffffff';
export const DARK_TEXT = '#000000';
export const MIN_CONTRAST = 4.5;

const HEX = /^#[0-9a-f]{6}$/i;
const BANNER_PATH = /^\/s\/[a-z0-9-]+\/banner\/[a-f0-9]{40}\.(?:png|jpg|webp)$/;

export const isHexColor = (value: string | null | undefined): value is string => !!value && HEX.test(value);

const color = (value: string | null | undefined): string | null => (isHexColor(value) ? value.toLowerCase() : null);

/** The WCAG relative luminance of a `#rrggbb` colour. */
export const luminance = (hex: string): number => {
    const [r, g, b] = [1, 3, 5].map((offset) => {
        const value = Number.parseInt(hex.slice(offset, offset + 2), 16) / 255;

        return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};

/** The WCAG contrast ratio of two `#rrggbb` colours, from 1 to 21. */
export const contrast = (a: string, b: string): number => {
    const [high, low] = [luminance(a), luminance(b)].sort((x, y) => y - x);

    return (high + 0.05) / (low + 0.05);
};

/** White or black, whichever reads better: one of them always reaches 4.5:1. */
export const textOn = (background: string): string =>
    contrast(LIGHT_TEXT, background) >= contrast(DARK_TEXT, background) ? LIGHT_TEXT : DARK_TEXT;

/** The text colour that reads on both ends of a gradient, or null when none does. */
export const textForGradient = (from: string, to: string): string | null => {
    let best: string | null = null;
    let bestContrast = 0;

    for (const text of [LIGHT_TEXT, DARK_TEXT]) {
        const worst = Math.min(contrast(text, from), contrast(text, to));

        if (worst >= MIN_CONTRAST && worst > bestContrast) {
            best = text;
            bestContrast = worst;
        }
    }

    return best;
};

export interface ThemeInput {
    accent: string | null;
    backgroundStyle: BackgroundStyle;
    backgroundFrom: string | null;
    backgroundTo: string | null;
    buttonStyle: ButtonStyle;
    imageUrl: string | null;
}

/** The style shown: a gradient needs its second colour and an image needs the banner. */
const shownStyle = (chosen: BackgroundStyle, to: string | null, image: string | null): BackgroundStyle => {
    if (chosen === 'image' && image) {
        return 'image';
    }

    if (chosen !== 'solid' && to) {
        return 'gradient';
    }

    return 'solid';
};

/** White over an image (it gets a dark scrim), else the colour that reads on the background. */
const shownText = (style: BackgroundStyle, from: string, to: string | null): string => {
    if (style === 'image') {
        return LIGHT_TEXT;
    }

    return style === 'gradient' && to ? (textForGradient(from, to) ?? LIGHT_TEXT) : textOn(from);
};

/** The theme the panel would send for these settings. */
export const buildTheme = (input: ThemeInput): ServerPageTheme => {
    const accent = color(input.accent) ?? DEFAULT_ACCENT;
    const from = color(input.backgroundFrom) ?? DEFAULT_BACKGROUND;
    const to = color(input.backgroundTo);
    const image = input.imageUrl && BANNER_PATH.test(input.imageUrl) ? input.imageUrl : null;
    const style = shownStyle(input.backgroundStyle, to, image);
    const text = shownText(style, from, to);

    return {
        accent,
        accent_text: textOn(accent),
        background: {
            style,
            from,
            to: style === 'solid' ? null : to,
            image_url: style === 'image' ? image : null,
        },
        text,
        button_style: input.buttonStyle === 'square' ? 'square' : 'rounded',
    };
};

/**
 * The CSS for a themed surface: the background and its text colour as custom properties the
 * components read. Only validated colours and the panel's own banner paths reach the style, so
 * nothing an owner types can become CSS.
 */
export const themeStyle = (theme: ServerPageTheme): CSSProperties => {
    const accent = color(theme.accent) ?? DEFAULT_ACCENT;
    const from = color(theme.background.from) ?? DEFAULT_BACKGROUND;
    const to = color(theme.background.to);
    const image =
        theme.background.image_url && BANNER_PATH.test(theme.background.image_url) ? theme.background.image_url : null;
    const text = theme.text === DARK_TEXT ? DARK_TEXT : LIGHT_TEXT;
    const accentText = theme.accent_text === DARK_TEXT ? DARK_TEXT : LIGHT_TEXT;

    let backgroundImage = 'none';

    if (theme.background.style === 'image' && image) {
        // The scrim keeps white text readable on any picture.
        backgroundImage = `linear-gradient(180deg, rgb(0 0 0 / 0.35), rgb(0 0 0 / 0.7)), url("${image}")`;
    } else if (theme.background.style === 'gradient' && to) {
        backgroundImage = `linear-gradient(135deg, ${from} 0%, ${to} 100%)`;
    }

    return {
        backgroundColor: from,
        backgroundImage,
        color: text,
        '--page-accent': accent,
        '--page-accent-text': accentText,
        '--page-text': text,
        '--page-radius': theme.button_style === 'square' ? '4px' : '9999px',
    } as CSSProperties;
};

/** The accent and button shape alone, for a row of buttons outside the themed surface. */
export const accentVars = (theme: ServerPageTheme): CSSProperties =>
    ({
        '--page-accent': color(theme.accent) ?? DEFAULT_ACCENT,
        '--page-accent-text': theme.accent_text === DARK_TEXT ? DARK_TEXT : LIGHT_TEXT,
        '--page-radius': theme.button_style === 'square' ? '4px' : '9999px',
    }) as CSSProperties;

/** The style of a button in the owner's accent and shape. */
export const accentButtonStyle: CSSProperties = {
    backgroundColor: 'var(--page-accent)',
    color: 'var(--page-accent-text)',
    borderRadius: 'var(--page-radius)',
};
