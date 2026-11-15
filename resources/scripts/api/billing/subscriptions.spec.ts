import { describe, expect, it } from 'vitest';
import {
    billingNotice,
    sleepDuration,
    subscriptionChangeMessage,
    timeUntil,
    type ServerSubscription,
} from './subscriptions';

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
    sleep: {
        allowed: false,
        supported: true,
        enabled: false,
        sleeping: false,
        sleep_started_at: null,
        idle_minutes: 15,
        discount_percent: 0,
        slept_seconds_this_period: 0,
        saved_this_period: 0,
        last_saved_tokens: 0,
    },
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

describe('auto-sleep', () => {
    it('says which switch changed', () => {
        expect(subscriptionChangeMessage({ auto_sleep: true })).toBe('Auto-sleep is on.');
        expect(subscriptionChangeMessage({ auto_sleep: false })).toBe('Auto-sleep is off.');
        expect(subscriptionChangeMessage({ auto_renew: true })).toBe('Automatic renewal is on.');
        expect(subscriptionChangeMessage({ auto_renew: false })).toBe('Automatic renewal is off.');
    });

    it('shows sleep time in days and hours, then hours and minutes', () => {
        expect(sleepDuration(0)).toBe('0m');
        expect(sleepDuration(59)).toBe('0m');
        expect(sleepDuration(45 * 60)).toBe('45m');
        expect(sleepDuration(4 * 3600 + 20 * 60)).toBe('4h 20m');
        expect(sleepDuration(3 * 86_400 + 2 * 3600 + 59)).toBe('3d 2h');
        expect(sleepDuration(-5)).toBe('0m');
    });
});
