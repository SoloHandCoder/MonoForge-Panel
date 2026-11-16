/** @vitest-environment jsdom */

import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { mapQuote, type RawStoreQuoteResponse } from '@/api/store/types';
import CouponField from './CouponField';
import { chargedNow } from './OrderSummary';
import { couponPart, firstPeriodPrice, normalizeCoupon, validateCoupon } from './useCheckout';

afterEach(cleanup);

const rawQuote = (coupon: RawStoreQuoteResponse['coupon']): RawStoreQuoteResponse => ({
    node_id: 2,
    can_provision: true,
    balance: 1500,
    quote: {
        base_per_period: 500,
        multiplier: '1.00',
        addons_per_period: 0,
        total_per_period: 500,
        period_days: 30,
        lines: [{ label: 'Stone · 30 days', tokens: 500 }],
        resources: {},
    },
    coupon,
});

describe('coupon codes', () => {
    it('normalises and checks a code like the server', () => {
        expect(normalizeCoupon('  welcome-20 ')).toBe('WELCOME-20');
        expect(validateCoupon('welcome_20')).toBeNull();
        expect(validateCoupon('ab')).not.toBeNull();
        expect(validateCoupon('no spaces')).not.toBeNull();
        expect(validateCoupon('x'.repeat(33))).not.toBeNull();
    });

    it('charges the coupon price only once the server checked the applied code', () => {
        const valid = { code: 'WELCOME', valid: true, message: null, discount: 100, firstPeriod: 400 };

        expect(firstPeriodPrice(500, null, null)).toBe(500);
        expect(firstPeriodPrice(500, 'WELCOME', null)).toBeNull();
        expect(firstPeriodPrice(500, 'WELCOME', { ...valid, code: 'OTHER' })).toBeNull();
        expect(firstPeriodPrice(500, 'WELCOME', valid)).toBe(400);
        expect(firstPeriodPrice(500, 'WELCOME', { ...valid, valid: false, discount: 0, firstPeriod: 500 })).toBe(500);
    });

    it('checks the balance against the discounted first period', () => {
        const quote = mapQuote(
            rawQuote({
                code: 'WELCOME',
                valid: true,
                message: null,
                type: 'percent',
                discount_tokens: 100,
                first_period_tokens: 400,
            })
        );

        expect(couponPart(500, 'WELCOME', quote, 450)).toMatchObject({
            firstPeriod: 400,
            short: false,
            checking: false,
        });
        expect(couponPart(500, null, quote, 450)).toMatchObject({ firstPeriod: 500, short: true, coupon: null });
        expect(couponPart(500, 'WELCOME', null, 450)).toMatchObject({ firstPeriod: null, checking: true });
    });

    it('maps the quote coupon, and its absence', () => {
        expect(mapQuote(rawQuote(null)).coupon).toBeNull();
        expect(
            mapQuote(
                rawQuote({
                    code: 'WELCOME',
                    valid: true,
                    message: null,
                    type: 'percent',
                    discount_tokens: 100,
                    first_period_tokens: 400,
                })
            ).coupon
        ).toEqual({ code: 'WELCOME', valid: true, message: null, discount: 100, firstPeriod: 400 });
    });

    it('charges the first period now, the period price without a coupon', () => {
        const price = {
            total: 500,
            days: 30,
            lines: null,
            resources: { memory: 1, disk: 1, cpu: 1, databases: 0, backups: 0, allocations: 0 },
            updating: false,
            balance: 1000,
            short: false,
            soldOut: false,
        };

        expect(chargedNow(price)).toBe(500);
        expect(chargedNow({ ...price, firstPeriod: 400 })).toBe(400);
        expect(chargedNow({ ...price, firstPeriod: null })).toBe(500);
    });
});

describe('coupon field', () => {
    it('applies a typed code in upper case and refuses a malformed one', () => {
        const onApply = vi.fn();

        render(<CouponField coupon={{ applied: null, result: null, onApply, onRemove: vi.fn() }} />);

        fireEvent.click(screen.getByRole('button', { name: 'Have a coupon?' }));
        const input = screen.getByLabelText('Coupon code');

        fireEvent.change(input, { target: { value: 'a' } });
        fireEvent.click(screen.getByRole('button', { name: 'Apply' }));
        expect(onApply).not.toHaveBeenCalled();
        expect(input).toHaveAttribute('aria-invalid', 'true');

        fireEvent.change(input, { target: { value: ' welcome ' } });
        fireEvent.click(screen.getByRole('button', { name: 'Apply' }));
        expect(onApply).toHaveBeenCalledWith('WELCOME');
    });

    it('shows the discount of a valid coupon and why an invalid one does not apply', () => {
        const onRemove = vi.fn();
        const { rerender } = render(
            <CouponField
                coupon={{
                    applied: 'WELCOME',
                    result: { code: 'WELCOME', valid: true, message: null, discount: 100, firstPeriod: 400 },
                    onApply: vi.fn(),
                    onRemove,
                }}
            />
        );

        expect(screen.getByText('−100 tokens')).toBeVisible();
        expect(screen.getByText(/Renewals are full price/)).toBeVisible();

        rerender(
            <CouponField
                coupon={{
                    applied: 'WELCOME',
                    result: {
                        code: 'WELCOME',
                        valid: false,
                        message: "This code can't be used.",
                        discount: 0,
                        firstPeriod: 500,
                    },
                    onApply: vi.fn(),
                    onRemove,
                }}
            />
        );

        expect(screen.getByRole('alert')).toHaveTextContent("This code can't be used.");
        fireEvent.click(screen.getByRole('button', { name: 'Remove the coupon WELCOME' }));
        expect(onRemove).toHaveBeenCalled();
    });
});
