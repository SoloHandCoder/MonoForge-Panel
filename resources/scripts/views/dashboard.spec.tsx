/** @vitest-environment jsdom */
import { act, cleanup, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeAll, expect, it, vi } from 'vitest';
import type * as ServerQueries from '@/api/account/servers/queries';
import type * as SubscriptionQueries from '@/api/billing/subscriptions';
import { createExtensionTestHost, createTestServer, type ExtensionTestHost } from '@/sdk/testing';
import { registerClassicTheme } from './classic';
import { ViewFixtureProvider, View } from './View';
import { clearThemeLayers, registerViewLayer, type ViewProps } from './registry';
import './dashboard';

const useServerResourceUsage = vi.hoisted(() => vi.fn((_uuid: string, _enabled?: boolean) => ({ data: undefined })));

vi.mock('@/api/account/servers/queries', async (importOriginal) => ({
    ...(await importOriginal<typeof ServerQueries>()),
    useServerResourceUsage,
}));

const useServerSubscription = vi.hoisted(() =>
    vi.fn((_identifier: string): SubscriptionQueries.ServerSubscription | null => null)
);

vi.mock('@/api/billing/subscriptions', async (importOriginal) => ({
    ...(await importOriginal<typeof SubscriptionQueries>()),
    useServerSubscription,
}));

let host: ExtensionTestHost | undefined;

beforeAll(() => registerClassicTheme());

afterEach(() => {
    cleanup();
    host?.dispose();
    host = undefined;
    useServerResourceUsage.mockClear();
    useServerSubscription.mockReset();
    useServerSubscription.mockReturnValue(null);
});

async function renderInApp(children: ReactNode) {
    host = createExtensionTestHost({ path: '/' });
    const { Wrapper } = host;

    await act(async () => {
        render(children, { wrapper: Wrapper });
    });
}

it('shows a maintenance badge instead of loading stats when the node is under maintenance', async () => {
    const server = createTestServer({ name: 'Survival' });

    await renderInApp(
        <View
            name='dashboard.serverCard'
            server={{ ...server, attributes: { ...server.attributes, is_node_under_maintenance: true } }}
        />
    );

    expect(await screen.findByText('Under Maintenance')).toBeVisible();
    expect(useServerResourceUsage).toHaveBeenCalledWith(server.attributes.uuid, false);
});

it('shows the deletion countdown of a suspended shop server on its card', async () => {
    const server = createTestServer({ name: 'Survival', status: 'suspended' });

    useServerSubscription.mockReturnValue({
        server_identifier: server.attributes.identifier,
        server_name: 'Survival',
        status: 'suspended',
        price_tokens: 500,
        period_days: 30,
        addons: [],
        auto_renew: true,
        next_renewal_at: new Date().toISOString(),
        suspended_at: new Date().toISOString(),
        delete_at: new Date(Date.now() + (6 * 86_400 + 23 * 3600 + 120) * 1000).toISOString(),
        can_renew_now: true,
    });

    await renderInApp(<View name='dashboard.serverCard' server={server} />);

    expect(await screen.findByRole('status')).toHaveTextContent('Suspended — deletes in 6d 23h. Top up and renew');
    expect(useServerSubscription).toHaveBeenCalledWith(server.attributes.identifier);
});

it('loads stats for a server on an available node', async () => {
    const server = createTestServer({ name: 'Survival' });

    await renderInApp(<View name='dashboard.serverCard' server={server} />);

    expect(await screen.findByText('Survival')).toBeVisible();
    expect(screen.queryByText('Under Maintenance')).not.toBeInTheDocument();
    expect(useServerResourceUsage).toHaveBeenCalledWith(server.attributes.uuid, true);
});

it('gives owners power actions from list data, which carries no per-server meta', async () => {
    registerViewLayer('theme', 'theme', {
        'dashboard.serverCard': ({ model }: ViewProps<'dashboard.serverCard'>) => (
            <span>start: {String(model.permissions.start)}</span>
        ),
    });
    const server = createTestServer({ name: 'Survival' });

    try {
        await renderInApp(
            <View
                name='dashboard.serverCard'
                server={{ ...server, meta: undefined, attributes: { ...server.attributes, server_owner: true } }}
            />
        );

        expect(await screen.findByText('start: true')).toBeVisible();
    } finally {
        clearThemeLayers();
    }
});

it('gives a subuser the Files shortcut only with the permission to read files', async () => {
    registerViewLayer('theme', 'theme', {
        'dashboard.serverCard': ({ model }: ViewProps<'dashboard.serverCard'>) => (
            <span>files: {String(model.permissions.files)}</span>
        ),
    });

    try {
        await renderInApp(
            <>
                <View
                    name='dashboard.serverCard'
                    server={createTestServer({ owner: false, permissions: ['control.console'] })}
                />
                <View
                    name='dashboard.serverCard'
                    server={createTestServer({ owner: false, permissions: ['file.read'] })}
                />
            </>
        );

        expect(await screen.findByText('files: false')).toBeVisible();
        expect(screen.getByText('files: true')).toBeVisible();
    } finally {
        clearThemeLayers();
    }
});

it('renders the classic server list from a fixture, with a link and status pill per card', async () => {
    await renderInApp(
        <ViewFixtureProvider fixtures={{ 'dashboard.serverList': 'servers' }}>
            <View name='dashboard.serverList' />
        </ViewFixtureProvider>
    );

    const survival = await screen.findByRole('link', { name: 'Survival' });

    expect(survival).toHaveAttribute('href', '/server/a1b2c3d4');
    const pill = (name: string) =>
        screen
            .getByRole('link', { name })
            .closest('[data-part="server-card"]')
            ?.querySelector('[data-part="status-pill"]');

    expect(pill('Survival')).toHaveAttribute('data-tone', 'online');
    expect(pill('Creative')).toHaveAttribute('data-tone', 'offline');
    expect(screen.getAllByRole('link', { name: 'Console' }).length).toBeGreaterThan(0);
    expect(screen.getByRole('switch')).toBeInTheDocument();
});

it('shows the empty state and the dashboard error from fixtures', async () => {
    await renderInApp(
        <ViewFixtureProvider fixtures={{ 'dashboard.serverList': 'empty', 'page.dashboard': 'error' }}>
            <View name='dashboard.serverList' />
            <View name='page.dashboard' />
        </ViewFixtureProvider>
    );

    expect(await screen.findByText('No servers yet')).toBeVisible();
    expect(screen.queryByRole('switch')).not.toBeInTheDocument();
    expect(screen.getByText('Request failed with status code 500.')).toBeVisible();
});
