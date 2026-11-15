/** @vitest-environment jsdom */
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import type * as SleepQueries from '@/api/server/sleep/queries';
import type * as SubscriptionQueries from '@/api/billing/subscriptions';
import { createExtensionTestHost, type ExtensionTestHost } from '@/sdk/testing';
import ServerSleepBanner from './ServerSleepBanner';
import { AutoSleepSummary } from './ServerSubscriptionPanel';

const useServerSleep = vi.hoisted(() => vi.fn((_uuid: string): SleepQueries.ServerSleepState | null => null));
const wake = vi.hoisted(() => vi.fn());
const update = vi.hoisted(() => vi.fn());

vi.mock('@/api/server/sleep/queries', async (importOriginal) => ({
    ...(await importOriginal<typeof SleepQueries>()),
    useServerSleep,
    useWakeServer: () => ({ mutate: wake, isPending: false }),
}));

vi.mock('@/api/billing/subscriptions', async (importOriginal) => ({
    ...(await importOriginal<typeof SubscriptionQueries>()),
    useUpdateServerSubscription: () => ({ mutate: update, isPending: false }),
}));

let host: ExtensionTestHost | undefined;

afterEach(() => {
    cleanup();
    host?.dispose();
    host = undefined;
    useServerSleep.mockReset();
    wake.mockReset();
    update.mockReset();
});

const sleepState = (state: Partial<SleepQueries.ServerSleepState> = {}): SleepQueries.ServerSleepState => ({
    available: true,
    supported: true,
    enabled: true,
    sleeping: true,
    sleep_started_at: '2026-11-20T03:15:00+00:00',
    can_wake: true,
    ...state,
});

const subscription = (
    sleep: Partial<SubscriptionQueries.ServerSubscription['sleep']> = {}
): SubscriptionQueries.ServerSubscription => ({
    server_identifier: 'a1b2c3d4',
    server_name: 'Survival',
    status: 'active',
    price_tokens: 500,
    period_days: 30,
    addons: [],
    auto_renew: true,
    next_renewal_at: '2026-12-15T12:00:00+00:00',
    suspended_at: null,
    delete_at: null,
    can_renew_now: false,
    sleep: {
        allowed: true,
        supported: true,
        enabled: false,
        sleeping: false,
        sleep_started_at: null,
        idle_minutes: 15,
        discount_percent: 50,
        slept_seconds_this_period: 4 * 3600 + 20 * 60,
        saved_this_period: 2,
        last_saved_tokens: 120,
        ...sleep,
    },
});

async function show(element: React.ReactElement) {
    host = createExtensionTestHost();
    await act(async () => {
        render(element, { wrapper: host!.Wrapper });
    });
}

it('shows a sleeping server with "Wake server" for viewers who may start it', async () => {
    useServerSleep.mockReturnValue(sleepState());
    await show(<ServerSleepBanner identifier='a1b2c3d4' />);

    expect(screen.getByText('This server is sleeping')).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Wake server' }));

    expect(useServerSleep).toHaveBeenCalledWith('a1b2c3d4');
    expect(wake).toHaveBeenCalledWith({ path: { server_uuid: 'a1b2c3d4' } });
});

it('has no wake button without control.start, and nothing while awake', async () => {
    useServerSleep.mockReturnValue(sleepState({ can_wake: false }));
    await show(<ServerSleepBanner identifier='a1b2c3d4' />);

    expect(screen.getByText('This server is sleeping')).toBeVisible();
    expect(screen.queryByRole('button', { name: 'Wake server' })).not.toBeInTheDocument();

    cleanup();
    useServerSleep.mockReturnValue(sleepState({ sleeping: false }));
    await show(<ServerSleepBanner identifier='a1b2c3d4' />);

    expect(screen.queryByText('This server is sleeping')).not.toBeInTheDocument();
});

it('shows the savings and the auto-sleep switch on the billing summary', async () => {
    await show(<AutoSleepSummary subscription={subscription()} />);

    expect(screen.getByText('You saved 120 tokens by sleeping at the last renewal.')).toBeVisible();
    expect(screen.getByText('Slept 4h 20m this period, 2 tokens off so far.')).toBeVisible();

    fireEvent.click(screen.getByRole('switch'));
    expect(update).toHaveBeenCalledWith({ path: { server_uuid: 'a1b2c3d4' }, body: { auto_sleep: true } });
});

it('hides the switch for games it cannot ask, and everything for plans without auto-sleep', async () => {
    await show(<AutoSleepSummary subscription={subscription({ supported: false })} />);

    expect(screen.getByText('Auto-sleep: not supported for this game yet.')).toBeVisible();
    expect(screen.queryByRole('switch')).not.toBeInTheDocument();

    cleanup();
    await show(<AutoSleepSummary subscription={subscription({ allowed: false })} />);

    expect(screen.queryByText(/Auto-sleep/)).not.toBeInTheDocument();
});
