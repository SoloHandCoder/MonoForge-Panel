import { describe, expect, it } from 'vitest';
import { buildTheme, contrast, textForGradient, textOn, themeStyle, type ThemeInput } from './serverPageTheme';

const input = (overrides: Partial<ThemeInput> = {}): ThemeInput => ({
    accent: null,
    backgroundStyle: 'solid',
    backgroundFrom: null,
    backgroundTo: null,
    buttonStyle: 'rounded',
    imageUrl: null,
    ...overrides,
});

const banner = `/s/skyblock/banner/${'a'.repeat(40)}.png`;

describe('server page theme', () => {
    it('measures contrast like WCAG and always picks a readable text colour', () => {
        expect(contrast('#000000', '#ffffff')).toBeCloseTo(21, 3);
        expect(contrast('#ffffff', '#8b5cf6')).toBeCloseTo(4.23, 2);

        for (const color of ['#000000', '#777777', '#808080', '#ffffff', '#8b5cf6', '#ffd166', '#0e0d15', '#22c55e']) {
            expect(contrast(textOn(color), color)).toBeGreaterThanOrEqual(4.5);
        }
    });

    it('finds a text colour for a gradient, or none', () => {
        expect(textForGradient('#0e0d15', '#5b21b6')).toBe('#ffffff');
        expect(textForGradient('#fef3c7', '#bae6fd')).toBe('#000000');
        expect(textForGradient('#000000', '#ffffff')).toBeNull();
    });

    it('builds the same theme as the panel', () => {
        expect(buildTheme(input())).toEqual({
            accent: '#8b5cf6',
            accent_text: '#000000',
            background: { style: 'solid', from: '#241a44', to: null, image_url: null },
            text: '#ffffff',
            button_style: 'rounded',
        });
        expect(buildTheme(input({ backgroundStyle: 'gradient', backgroundTo: null })).background.style).toBe('solid');
        expect(buildTheme(input({ backgroundStyle: 'image' })).background.style).toBe('solid');
        expect(
            buildTheme(input({ backgroundStyle: 'image', backgroundFrom: '#FEF3C7', imageUrl: banner }))
        ).toMatchObject({
            background: { style: 'image', from: '#fef3c7', image_url: banner },
            text: '#ffffff',
        });
        expect(buildTheme(input({ accent: 'red', buttonStyle: 'square' }))).toMatchObject({
            accent: '#8b5cf6',
            button_style: 'square',
        });
    });

    it('never lets anything but a colour or a panel banner path into the style', () => {
        const style = themeStyle({
            accent: 'red;}body{display:none',
            accent_text: '#000000',
            background: {
                style: 'image',
                from: 'url(https://evil.example)',
                to: null,
                image_url: 'https://evil.example/x.png") ; background: red',
            },
            text: 'red',
            button_style: 'rounded',
        });

        expect(style.backgroundColor).toBe('#241a44');
        expect(style.backgroundImage).toBe('none');
        expect(style.color).toBe('#ffffff');
        expect(JSON.stringify(style)).not.toContain('evil');
        expect(themeStyle(buildTheme(input({ backgroundStyle: 'image', imageUrl: banner }))).backgroundImage).toContain(
            `url("${banner}")`
        );
    });
});
