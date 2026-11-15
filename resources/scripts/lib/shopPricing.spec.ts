import { describe, expect, it } from 'vitest';
import { basePrice, formatMultiplier, modifierLabel, perThirtyDays, periodTotal } from '@/lib/shopPricing';

const monthly = { days: 30, price_modifier_percent: 0 };
const quarterly = { days: 90, price_modifier_percent: -20 };
const yearly = { days: 365, price_modifier_percent: 10 };

describe('shop pricing preview', () => {
    it('matches the server formula for periods, multipliers and add-ons', () => {
        expect(periodTotal(500, monthly)).toBe(500);
        expect(basePrice(500, quarterly)).toBe(1200);
        expect(periodTotal(500, yearly)).toBe(6692);
        expect(basePrice(500, { days: 365, price_modifier_percent: -25 })).toBe(4563);
        expect(periodTotal(333, monthly, 1.15)).toBe(383);
        expect(periodTotal(100, monthly, 1.15)).toBe(115);
        // 1200 x 1.5 + 100 tokens of add-ons per 30 days x 3 x 0.8
        expect(periodTotal(500, quarterly, 1.5, 100)).toBe(2040);
        expect(periodTotal(0, monthly)).toBe(1);
    });

    it('labels modifiers and multipliers', () => {
        expect(modifierLabel(-20)).toBe('20% off');
        expect(modifierLabel(10)).toBe('10% surcharge');
        expect(modifierLabel(0)).toBe('No discount');
        expect(formatMultiplier(1.5)).toBe('1.5×');
        expect(formatMultiplier(1)).toBe('1×');
        expect(perThirtyDays(1200, quarterly)).toBe(400);
    });
});
