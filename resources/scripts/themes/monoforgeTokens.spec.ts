/// <reference types="node" />
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

import { describe, expect, it } from 'vitest';

const read = (path: string) =>
    readFileSync(fileURLToPath(new URL(path, import.meta.url)), 'utf8')
        .split(/\r?\n/)
        .join('\n');

const css = read('../../../packages/sdk/panel.css');
const tokens = read('../../../packages/sdk/theme-tokens.css');

const darkBlock = (() => {
    const start = css.indexOf('\n.dark {');

    return css.slice(start, css.indexOf('\n}', start));
})();

describe('MonoForge tokens', () => {
    it.each([
        ['--primary', '#8b5cf6'],
        ['--background', '#0e0d15'],
        ['--card', '#17151f'],
        ['--sidebar', '#0b0a11'],
        ['--border', '#27233a'],
        ['--foreground', '#ece9f7'],
        ['--muted-foreground', '#9d97b5'],
        ['--success', '#3ee07a'],
        ['--status-busy', '#f59e0b'],
        ['--status-offline', '#6b6680'],
    ])('dark sets %s to %s', (name, value) => {
        expect(darkBlock).toContain(`${name}: ${value};`);
    });

    it('uses bundled Inter Variable', () => {
        expect(tokens).toContain("--font-sans: 'Inter Variable'");
        expect(css).toContain("font-family: 'Inter Variable'");
        expect(css).toContain('/assets/fonts/inter-latin-wght-normal.woff2');
        expect(css).not.toContain('fonts.googleapis.com');
    });

    it('honours reduced motion', () => {
        expect(css).toContain('prefers-reduced-motion: reduce');
        expect(css).toContain('@utility animate-rise');
    });

    it('drops the hover lift under reduced motion, which Tailwind 4 draws with translate', () => {
        const start = css.indexOf('@media (prefers-reduced-motion: reduce)');
        const block = css.slice(start, css.indexOf('\n}', start));

        expect(block).toContain(String.raw`.hover\:-translate-y-1:hover`);
        expect(block).toContain('translate: none !important;');
    });
});
