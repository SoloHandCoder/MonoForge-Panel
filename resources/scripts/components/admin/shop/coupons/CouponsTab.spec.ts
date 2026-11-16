import { describe, expect, it } from 'vitest';
import { couponValueLabel, type AdminCoupon } from '@/api/admin/shop/coupons';
import { couponFormValues, couponRequest, couponWindow, validateCouponValue } from './CouponsTab';

const coupon = (overrides: Partial<AdminCoupon> = {}): AdminCoupon => ({
    id: 1,
    code: 'WELCOME',
    type: 'percent',
    value: 20,
    description: null,
    max_redemptions: null,
    redemptions_count: 0,
    valid_from: null,
    valid_until: null,
    plan_ids: [3],
    category_ids: [],
    new_customers_only: false,
    enabled: true,
    created_at: '2026-11-16T12:00:00+00:00',
    ...overrides,
});

describe('coupon form', () => {
    it('sends an upper-case code, no limit for an empty maximum and no plans for a free-tokens coupon', () => {
        const values = { ...couponFormValues(coupon()), code: ' welcome-20 ', maxRedemptions: 0, description: '  ' };

        expect(couponRequest(values)).toMatchObject({
            code: 'WELCOME-20',
            type: 'percent',
            max_redemptions: null,
            description: null,
            plan_ids: [3],
            valid_from: null,
        });
        expect(couponRequest({ ...values, type: 'tokens', categoryIds: [2] })).toMatchObject({
            plan_ids: [],
            category_ids: [],
        });
    });

    it('limits the value by type', () => {
        expect(validateCouponValue('percent', 100)).toBeUndefined();
        expect(validateCouponValue('percent', 101)).toBe('At most 100%.');
        expect(validateCouponValue('fixed', 0)).toBe('At least 1.');
        expect(validateCouponValue('fixed', 5_000_000)).toBeUndefined();
        expect(validateCouponValue('tokens', 1_000_001)).toBe('At most 1,000,000 tokens.');
        expect(validateCouponValue('tokens', null)).toBe('At least 1.');
    });
});

describe('coupon list', () => {
    const now = new Date('2026-11-20T12:00:00Z');

    it('says whether a coupon works now', () => {
        expect(couponWindow(coupon(), now)).toEqual({ label: 'Active', live: true });
        expect(couponWindow(coupon({ enabled: false }), now).label).toBe('Disabled');
        expect(couponWindow(coupon({ valid_until: '2026-11-19T00:00:00Z' }), now).label).toBe('Expired');
        expect(couponWindow(coupon({ valid_from: '2026-12-01T00:00:00Z' }), now).live).toBe(false);
        expect(couponWindow(coupon({ max_redemptions: 5, redemptions_count: 5 }), now).label).toBe('Used up');
    });

    it('names what a coupon gives', () => {
        expect(couponValueLabel('percent', 20)).toBe('20% off');
        expect(couponValueLabel('fixed', 1500)).toBe('1,500 tokens off');
        expect(couponValueLabel('tokens', 500)).toBe('500 free tokens');
    });
});
