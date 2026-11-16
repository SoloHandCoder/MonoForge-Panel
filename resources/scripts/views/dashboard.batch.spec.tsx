/** @vitest-environment jsdom */
import { act, cleanup, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeAll, beforeEach, expect, it, vi } from 'vitest';
import {
    accountServersQueryOptions,
    serverResourceUsageBatchLimit,
    serverResourceUsageBatchQueryOptions,
    serverResourceUsageQueryOptions,
} from '@/api/account/servers/queries';
import type * as SubscriptionQueries from '@/api/billing/subscriptions';
import { queryClient } from '@/api/queryClient';
import { createExtensionTestHost, createTestServer, type ExtensionTestHost } from '@/sdk/testing';
import { registerClassicTheme } from './classic';
import { View } from './View';
import './dashboard';

/** What the panel's queries pass the generated batch call. */
type BatchInput = { query: { servers: string } };

// The generated transport, which the dashboard's queries call; the module is mocked by its path.
const sdk = vi.hoisted(() => ({
    clientListServerResources: vi.fn<(options: BatchInput) => Promise<unknown>>(),
    clientGetServerResources: vi.fn<() => Promise<unknown>>(),
    clientListClientServers: vi.fn<() => Promise<unknown>>(),
}));

vi.mock('@/api/generated/sdk.gen', async (importOriginal) => ({
    ...(await importOriginal<object>()),
    ...sdk,
}));

vi.mock('@/api/billing/subscriptions', async (importOriginal) => ({
    ...(await importOriginal<typeof SubscriptionQueries>()),
    useServerSubscription: () => null,
}));

const stats = (current_state = 'running') => ({
    object: 'stats',
    attributes: {
        current_state,
        is_suspended: false,
        resources: {
            memory_bytes: 512 * 1024 ** 2,
            cpu_absolute: 12.5,
            disk_bytes: 1024 ** 3,
            network_rx_bytes: 0,
            network_tx_bytes: 0,
            uptime: 1000,
        },
    },
});

const servers = (count: number) =>
    Array.from({ length: count }, (_, index) => {
        const hex = index.toString(16).padStart(8, '0');

        return createTestServer({
            identifier: hex,
            uuid: `${hex}-0000-4000-8000-000000000000`,
            name: `Server ${index}`,
        });
    });

let host: ExtensionTestHost | undefined;

beforeAll(() => registerClassicTheme());

beforeEach(() => {
    sdk.clientListServerResources.mockImplementation(async ({ query }) => ({
        data: {
            object: 'server_resources',
            data: Object.fromEntries(query.servers.split(',').map((uuid) => [uuid, stats()])),
        },
    }));
    sdk.clientGetServerResources.mockResolvedValue({ data: stats() });
});

afterEach(() => {
    cleanup();
    host?.dispose();
    host = undefined;
    vi.clearAllMocks();
});

async function renderList(list: ReturnType<typeof servers>) {
    host = createExtensionTestHost({ path: '/' });
    const page = {
        object: 'list',
        data: list,
        meta: {
            pagination: { total: list.length, count: list.length, per_page: 100, current_page: 1, total_pages: 1 },
        },
    };

    host.queryClient.setQueryData(accountServersQueryOptions({ page: 1 }).queryKey, page as never);
    sdk.clientListClientServers.mockResolvedValue({ data: page });

    await act(async () => {
        render(<View name='dashboard.serverList' />, { wrapper: host!.Wrapper });
    });
}

const pills = () =>
    [...document.querySelectorAll('[data-part="status-pill"]')].map((pill) => pill.getAttribute('data-tone'));

it('polls the usage of 30 server cards with one request instead of one per card', async () => {
    const list = servers(30);

    await renderList(list);

    await waitFor(() => expect(pills().filter((tone) => tone === 'online')).toHaveLength(30));
    expect(sdk.clientListServerResources).toHaveBeenCalledTimes(1);
    expect(sdk.clientListServerResources.mock.calls[0]?.[0].query.servers).toBe(
        list.map((server) => server.attributes.uuid).join(',')
    );
    expect(sdk.clientGetServerResources).not.toHaveBeenCalled();
    expect(screen.getByText('Server 29')).toBeVisible();
});

it('writes each reading to the server’s own resources entry, which the server page reads', async () => {
    const [server] = servers(1);

    await renderList([server!]);

    await waitFor(() =>
        expect(queryClient.getQueryData(serverResourceUsageQueryOptions(server!.attributes.uuid).queryKey)).toEqual(
            stats()
        )
    );
    expect(sdk.clientGetServerResources).not.toHaveBeenCalled();
});

it('splits a page of more than 50 servers into requests of at most 50', async () => {
    await renderList(servers(60));

    await waitFor(() => expect(sdk.clientListServerResources).toHaveBeenCalledTimes(2));
    const sizes = sdk.clientListServerResources.mock.calls.map(([options]) => options.query.servers.split(',').length);

    expect(sizes).toEqual([serverResourceUsageBatchLimit, 10]);
    expect(sdk.clientGetServerResources).not.toHaveBeenCalled();
});

it('leaves suspended servers and nodes under maintenance out of the batch', async () => {
    const [running, suspended, maintenance] = servers(3);
    const list = [
        running!,
        { ...suspended!, attributes: { ...suspended!.attributes, status: 'suspended' as const, is_suspended: true } },
        { ...maintenance!, attributes: { ...maintenance!.attributes, is_node_under_maintenance: true } },
    ];

    await renderList(list);

    await waitFor(() => expect(sdk.clientListServerResources).toHaveBeenCalledTimes(1));
    expect(sdk.clientListServerResources.mock.calls[0]?.[0].query.servers).toBe(running!.attributes.uuid);
    expect(sdk.clientGetServerResources).not.toHaveBeenCalled();
});

it('keeps a card rendered on its own polling the single resources endpoint', async () => {
    const [server] = servers(1);

    host = createExtensionTestHost({ path: '/' });
    await act(async () => {
        render(<View name='dashboard.serverCard' server={server!} />, { wrapper: host!.Wrapper });
    });

    await waitFor(() => expect(sdk.clientGetServerResources).toHaveBeenCalledTimes(1));
    expect(sdk.clientListServerResources).not.toHaveBeenCalled();
});

it('refetches the batch every 30 seconds and never in a hidden tab', () => {
    const options = serverResourceUsageBatchQueryOptions(queryClient, ['a']);

    expect(options.refetchInterval).toBe(30_000);
    expect(options.refetchIntervalInBackground).toBe(false);
});

it('shows a card Wings cannot reach as unavailable instead of loading forever', async () => {
    const [reachable, unreachable] = servers(2);

    sdk.clientListServerResources.mockImplementation(async () => ({
        data: {
            object: 'server_resources',
            data: {
                [reachable!.attributes.uuid]: stats(),
                [unreachable!.attributes.uuid]: {
                    object: 'error',
                    attributes: { code: 'daemon_error', status: 504, detail: 'The node could not be reached.' },
                },
            },
        },
    }));

    await renderList([reachable!, unreachable!]);

    await waitFor(() => expect(pills()).toEqual(['online', 'offline']));
    expect(screen.getByText('Unavailable')).toBeVisible();
    expect(screen.queryByText('Loading')).toBeNull();
    expect(screen.getByText('Connection Error')).toBeVisible();
});

it('marks a card polling on its own unavailable once its resources request fails', async () => {
    const [server] = servers(1);

    sdk.clientGetServerResources.mockRejectedValue(new Error('504'));
    host = createExtensionTestHost({ path: '/' });
    await act(async () => {
        render(<View name='dashboard.serverCard' server={server!} />, { wrapper: host!.Wrapper });
    });

    await waitFor(() => expect(screen.getByText('Unavailable')).toBeVisible());
    expect(screen.queryByText('Loading')).toBeNull();
});
