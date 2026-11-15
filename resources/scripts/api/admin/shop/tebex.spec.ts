import { describe, expect, it } from 'vitest';
import { canApproveTebexPayment, isUnderpaidTebexPayment } from './tebex';

describe('canApproveTebexPayment', () => {
    it('offers "Approve and credit" only for flagged payments that were not credited', () => {
        expect(canApproveTebexPayment({ status: 'underpaid', processed_at: null })).toBe(true);
        expect(canApproveTebexPayment({ status: 'unmatched', processed_at: null })).toBe(true);
        expect(canApproveTebexPayment({ status: 'underpaid', processed_at: '2026-11-16T12:00:00+00:00' })).toBe(false);
        expect(canApproveTebexPayment({ status: 'refunded', processed_at: null })).toBe(false);
        expect(canApproveTebexPayment({ status: 'completed', processed_at: '2026-11-16T12:00:00+00:00' })).toBe(false);
    });
});

describe('isUnderpaidTebexPayment', () => {
    it('asks for explicit acceptance of an underpaid payment, also when it was flagged as unmatched first', () => {
        expect(isUnderpaidTebexPayment({ status: 'underpaid', flag_reason: '0.99 EUR was paid' })).toBe(true);
        expect(
            isUnderpaidTebexPayment({
                status: 'unmatched',
                flag_reason: 'it matched no panel user; also 0.99 EUR was paid for 1 × a package priced 4.99 EUR',
            })
        ).toBe(true);
        expect(isUnderpaidTebexPayment({ status: 'unmatched', flag_reason: 'it matched no panel user' })).toBe(false);
    });
});
