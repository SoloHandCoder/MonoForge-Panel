/** @vitest-environment jsdom */
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import type * as SubscriptionQueries from '@/api/billing/subscriptions';
import { createExtensionTestHost, type ExtensionTestHost } from '@/sdk/testing';
import ServerSubscriptionPanel from './ServerSubscriptionPanel';

const useServerSubscription = vi.hoisted(() =>
    vi.fn((_identifier: string): SubscriptionQueries.ServerSubscription | null => null)
);
const keep = vi.hoisted(() => vi.fn());

vi.mock('@/api/billing/subscriptions', async (importOriginal) => ({
    ...(await importOriginal<typeof SubscriptionQueries>()),
    useServerSubscription,
    useKeepTrialServer: () => ({ mutate: keep, isPending: false }),
}));

vi.mock('@/plugins/useNow', () => ({ useNow: () => Date.parse('2026-11-20T12:00:00Z') }));

let host: ExtensionTestHost | undefined;

beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-11-20T12:00:00Z'));
});

afterEach(() => {
    cleanup();
    host?.dispose();
    host = undefined;
    useServerSubscription.mockReset();
    keep.mockReset();
    vi.useRealTimers();
});

const trialSubscription = (
    status: 'trial' | 'suspended',
    options = [
        { period_id: 1, name: 'Monthly', days: 30, price_tokens: 500 },
        { period_id: 2, name: 'Quarterly', days: 90, price_tokens: 1350 },
    ]
): SubscriptionQueries.ServerSubscription => ({
    server_identifier: 'a1b2c3d4',
    server_name: 'Trial world',
    status,
    is_trial: true,
    trial: { ends_at: '2026-11-21T14:30:00Z', options },
    price_tokens: 0,
    period_days: 2,
    addons: [],
    auto_renew: false,
    next_renewal_at: '2026-11-21T14:30:00Z',
    suspended_at: status === 'suspended' ? '2026-11-20T10:00:00Z' : null,
    delete_at: status === 'suspended' ? '2026-11-27T11:00:00Z' : null,
    can_renew_now: false,
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

async function show() {
    host = createExtensionTestHost();
    await act(async () => {
        render(<ServerSubscriptionPanel identifier='a1b2c3d4' summary={false} />, { wrapper: host!.Wrapper });
    });
}

it('counts down a running trial on every page and keeps it for the picked period', async () => {
    useServerSubscription.mockReturnValue(trialSubscription('trial'));
    await show();

    expect(screen.getByText('Free trial — ends in 1d 2h')).toBeVisible();
    expect(screen.getByRole('button', { name: 'Keep this server · 500 tokens / 30 days' })).toBeVisible();

    fireEvent.click(screen.getByRole('radio', { name: 'Quarterly · 1350 tokens' }));
    fireEvent.click(screen.getByRole('button', { name: 'Keep this server · 1350 tokens / 90 days' }));

    expect(keep).toHaveBeenCalledWith({
        path: { server_uuid: 'a1b2c3d4' },
        body: { period_id: 2, expected_total: 1350 },
    });
});

it('counts down to the deletion once the trial ended', async () => {
    useServerSubscription.mockReturnValue(
        trialSubscription('suspended', [{ period_id: 1, name: 'Monthly', days: 30, price_tokens: 500 }])
    );
    await show();

    expect(screen.getByText('Trial ended — deletes in 6d 23h')).toBeVisible();
    // One period: no picker.
    expect(screen.queryByRole('radio')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Keep this server · 500 tokens / 30 days' }));

    expect(keep).toHaveBeenCalledWith({
        path: { server_uuid: 'a1b2c3d4' },
        body: { period_id: 1, expected_total: 500 },
    });
});

it('says to contact support when the plan is not sold any more', async () => {
    useServerSubscription.mockReturnValue(trialSubscription('trial', []));
    await show();

    expect(screen.getByText('Contact support to keep this server: its plan is not sold any more.')).toBeVisible();
    expect(screen.queryByRole('button', { name: /Keep this server/ })).not.toBeInTheDocument();
});
