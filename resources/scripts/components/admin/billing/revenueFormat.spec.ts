import { describe, expect, it } from 'vitest';
import { revenueFixture } from './fixtures';
import {
    csvCell,
    customRangeError,
    daysBetween,
    formatMoney,
    revenueCsv,
    revenueCsvFilename,
    revenueQuery,
} from './revenueFormat';
import { niceMax } from './DailyBarChart';

describe('revenue ranges', () => {
    it('counts both ends of a range', () => {
        expect(daysBetween('2026-11-10', '2026-11-16')).toBe(7);
        expect(daysBetween('2026-11-16', '2026-11-16')).toBe(1);
        expect(daysBetween('2026-11-16', '')).toBeNull();
    });

    it('explains a custom range the panel would refuse', () => {
        expect(customRangeError('2026-11-01', '2026-11-16', '2026-11-16')).toBeNull();
        expect(customRangeError('', '2026-11-16', '2026-11-16')).toBe('Pick both days.');
        expect(customRangeError('2026-11-10', '2026-11-01', '2026-11-16')).toMatch(/on or after/);
        expect(customRangeError('2026-11-10', '2026-11-17', '2026-11-16')).toMatch(/future/);
        expect(customRangeError('2025-01-01', '2026-11-16', '2026-11-16')).toMatch(/366 days/);
    });

    it('asks for a preset or a custom range', () => {
        expect(revenueQuery('7', 'x', 'y')).toEqual({ range: '7' });
        expect(revenueQuery('custom', '2026-11-01', '2026-11-16')).toEqual({
            range: 'custom',
            from: '2026-11-01',
            to: '2026-11-16',
        });
    });
});

describe('revenue formatting', () => {
    it('formats money in its own currency and leaves unknown codes readable', () => {
        expect(formatMoney('14.98', 'EUR')).toBe('€14.98');
        expect(formatMoney('-3.50', 'USD')).toBe('-$3.50');
        expect(formatMoney('1.00', 'UNKNOWN')).toBe('1.00 UNKNOWN');
    });

    it('rounds the chart top to a plain number', () => {
        expect(niceMax(0)).toBe(1);
        expect(niceMax(7)).toBe(10);
        expect(niceMax(1000)).toBe(1000);
        expect(niceMax(1200)).toBe(2000);
        expect(niceMax(230)).toBe(250);
    });
});

describe('revenue CSV', () => {
    it('quotes cells and keeps names from running as formulas', () => {
        expect(csvCell('plain')).toBe('plain');
        expect(csvCell('a, b')).toBe('"a, b"');
        expect(csvCell('say "hi"')).toBe('"say ""hi"""');
        expect(csvCell('=HYPERLINK("x")')).toBe(`"'=HYPERLINK(""x"")"`);
        expect(csvCell('-3.50')).toBe('-3.50');
        expect(csvCell(-5)).toBe('-5');
    });

    it('exports the summary, money, plans and days', () => {
        const csv = revenueCsv(revenueFixture);
        const lines = csv.split('\r\n');

        expect(lines[0]).toBe('metric,value');
        expect(lines).toContain('tokens_sold,1500');
        expect(lines).toContain('renewals_next_7_days,2');
        expect(lines).toContain('EUR,14.98,4.99,0.00,9.99,2,1,0');
        expect(lines).toContain('USD,0.00,0.00,3.50,-3.50,0,0,1');
        expect(lines).toContain("'=Small,2,642");
        expect(lines).toContain('2026-11-15,1000,300,3');
        expect(csv.endsWith('\r\n')).toBe(true);
        expect(revenueCsvFilename(revenueFixture)).toBe('revenue-2026-11-14-to-2026-11-16.csv');
    });
});
