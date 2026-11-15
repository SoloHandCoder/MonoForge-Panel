import { describe, expect, it } from 'vitest';
import { billingNotice, timeUntil, type ServerSubscription } from './subscriptions';

const now = Date.parse('2026-11-20T12:00:00Z');

const subscription = (status: string, deleteAt: string | null): ServerSubscription => ({
    server_identifier: 'a1b2c3d4',
    server_name: 'Survival',
    status,
    price_tokens: 500,
    period_days: 30,
    addons: [],
    auto_renew: true,
    next_renewal_at: '2026-11-20T12:00:00Z',
    suspended_at: status === 'suspended' ? '2026-11-20T12:00:00Z' : null,
    delete_at: deleteAt,
    can_renew_now: true,
});

describe('billing countdown', () => {
    it('counts days and hours, then hours and minutes, and never goes negative', () => {
        expect(timeUntil('2026-11-27T11:00:00Z', now)).toBe('6d 23h');
        expect(timeUntil('2026-11-20T14:30:00Z', now)).toBe('2h 30m');
        expect(timeUntil('2026-11-20T12:05:00Z', now)).toBe('5m');
        expect(timeUntil('2026-11-19T12:00:00Z', now)).toBe('0m');
    });

    it('shows a card notice only while the server is suspended', () => {
        expect(billingNotice(null, now)).toBeNull();
        expect(billingNotice(subscription('active', null), now)).toBeNull();
        expect(billingNotice(subscription('suspended', '2026-11-27T11:00:00Z'), now)).toBe(
            'Suspended — deletes in 6d 23h. Top up and renew'
        );
        expect(billingNotice(subscription('suspended', '2026-11-20T11:00:00Z'), now)).toBe('Suspended — deleting now');
    });
});
