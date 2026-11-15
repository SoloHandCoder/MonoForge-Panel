import type * as Monaco from 'monaco-editor/editor/editor.api.js';

export const MONOFORGE_THEME = 'monoforge';

type Hex = string;

/** Six hex digits of a CSS colour in `#rgb`, `#rrggbb` or `rgb()` form; null for anything else. */
export function hexDigits(value: string): Hex | null {
    const hex = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(value.trim());

    if (hex) {
        const digits = hex[1].length === 3 ? [...hex[1]].map((digit) => digit + digit).join('') : hex[1];

        return digits.toLowerCase();
    }

    const rgb = /^rgba?\((\d+)[ ,]+(\d+)[ ,]+(\d+)/i.exec(value.trim());

    return rgb
        ? rgb
              .slice(1, 4)
              .map((part) => Math.min(255, Number(part)).toString(16).padStart(2, '0'))
              .join('')
        : null;
}

/** Monaco's colour keys and the design token each one takes, with an optional alpha in hex. */
const colorTokens: readonly (readonly [key: string, token: string, alpha?: string])[] = [
    ['editor.background', 'card'],
    ['editor.foreground', 'foreground'],
    ['editorCursor.foreground', 'accent'],
    ['editor.lineHighlightBackground', 'hover-row'],
    ['editor.lineHighlightBorder', 'hover-row'],
    ['editor.selectionBackground', 'primary', '55'],
    ['editor.inactiveSelectionBackground', 'primary', '2a'],
    ['editor.selectionHighlightBackground', 'primary', '26'],
    ['editor.wordHighlightBackground', 'primary', '26'],
    ['editor.findMatchBackground', 'warning', '66'],
    ['editor.findMatchHighlightBackground', 'warning', '33'],
    ['editorLineNumber.foreground', 'muted-foreground', '80'],
    ['editorLineNumber.activeForeground', 'accent-soft'],
    ['editorIndentGuide.background1', 'muted'],
    ['editorIndentGuide.activeBackground1', 'accent', '80'],
    ['editorBracketMatch.background', 'primary', '33'],
    ['editorBracketMatch.border', 'accent', '80'],
    ['editorWidget.background', 'popover'],
    ['editorWidget.border', 'border'],
    ['editorSuggestWidget.background', 'popover'],
    ['editorSuggestWidget.border', 'border'],
    ['editorSuggestWidget.selectedBackground', 'active-item'],
    ['editorHoverWidget.background', 'popover'],
    ['editorHoverWidget.border', 'border'],
    ['quickInput.background', 'popover'],
    ['quickInputList.focusBackground', 'active-item'],
    ['input.background', 'background'],
    ['input.border', 'border'],
    ['focusBorder', 'accent'],
    ['scrollbarSlider.background', 'muted-foreground', '33'],
    ['scrollbarSlider.hoverBackground', 'muted-foreground', '55'],
    ['scrollbarSlider.activeBackground', 'accent', '66'],
    ['editorGutter.background', 'card'],
    ['editorStickyScroll.background', 'card'],
    ['editorStickyScrollHover.background', 'hover-row'],
    ['minimap.background', 'card'],
];

/** Syntax token scopes and the design token that colours them. */
const ruleTokens: readonly (readonly [scope: string, token: string, fontStyle?: string])[] = [
    ['', 'foreground'],
    ['comment', 'muted-foreground', 'italic'],
    ['keyword', 'accent'],
    ['type', 'accent-soft'],
    ['tag', 'accent'],
    ['attribute.name', 'accent-soft'],
    ['key', 'accent-soft'],
    ['string.key.json', 'accent-soft'],
    ['string', 'success'],
    ['string.value.json', 'success'],
    ['attribute.value', 'success'],
    ['number', 'warning'],
    ['keyword.json', 'warning'],
    ['variable', 'warning'],
    ['delimiter', 'muted-foreground'],
    ['invalid', 'destructive'],
];

/**
 * A custom dark theme built from the panel's design tokens, so the editor matches the page around
 * it. A token that is not a plain hex or rgb() colour keeps Monaco's own dark value.
 */
export function defineMonoForgeTheme(monaco: typeof Monaco): void {
    const styles = getComputedStyle(document.documentElement);
    const read = (token: string) => hexDigits(styles.getPropertyValue(`--${token}`));
    const colors: Monaco.editor.IColors = {};
    const rules: Monaco.editor.ITokenThemeRule[] = [];

    for (const [key, token, alpha = ''] of colorTokens) {
        const digits = read(token);

        if (digits) {
            colors[key] = `#${digits}${alpha}`;
        }
    }

    for (const [scope, token, fontStyle] of ruleTokens) {
        const digits = read(token);

        if (digits) {
            const rule: Monaco.editor.ITokenThemeRule = { token: scope, foreground: digits };

            if (fontStyle) {
                rule.fontStyle = fontStyle;
            }

            rules.push(rule);
        }
    }

    monaco.editor.defineTheme(MONOFORGE_THEME, { base: 'vs-dark', inherit: true, rules, colors });
}
