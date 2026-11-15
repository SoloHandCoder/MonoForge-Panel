import { describe, expect, it } from 'vitest';
import { canApproveTebexPayment } from './tebex';

describe('canApproveTebexPayment', () => {
    it('offers "Approve and credit" only for flagged payments that were not credited', () => {
        expect(canApproveTebexPayment({ status: 'underpaid', processed_at: null })).toBe(true);
        expect(canApproveTebexPayment({ status: 'unmatched', processed_at: null })).toBe(true);
        expect(canApproveTebexPayment({ status: 'underpaid', processed_at: '2026-11-16T12:00:00+00:00' })).toBe(false);
        expect(canApproveTebexPayment({ status: 'refunded', processed_at: null })).toBe(false);
        expect(canApproveTebexPayment({ status: 'completed', processed_at: '2026-11-16T12:00:00+00:00' })).toBe(false);
    });
});
