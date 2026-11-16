/** @vitest-environment jsdom */
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import type { AxiosAdapter } from 'axios';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import type { ServerSubscription } from '@/api/billing/subscriptions';
import http from '@/api/http';
import { serverQueryOptions } from '@/api/server/queries';
import type { Server } from '@/api/server/types';
import { createExtensionTestHost, createTestServer, type ExtensionTestHost } from '@/sdk/testing';
import { SKIPPED_INSTALL_SCRIPT_MESSAGE } from '@/lib/serverStatus';
import { View } from '@/views/View';

let host: ExtensionTestHost | undefined;
let requests: { method: string; url: string; body: unknown }[];
/** What GET /api/client/subscriptions answers: the shop billing the viewer pays for. */
let subscriptions: { object: string; attributes: ServerSubscription }[];
const adapter = http.defaults.adapter;

const sleepySubscription = (sleep: Partial<ServerSubscription['sleep']> = {}) => ({
    object: 'server_subscription',
    attributes: {
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
        is_trial: false,
        trial: null,
        sleep: {
            allowed: true,
            supported: true,
            enabled: true,
            sleeping: false,
            sleep_started_at: null,
            idle_minutes: 15,
            discount_percent: 50,
            slept_seconds_this_period: 0,
            saved_this_period: 0,
            last_saved_tokens: 120,
            ...sleep,
        },
    },
});

beforeEach(() => {
    requests = [];
    subscriptions = [];
    http.defaults.adapter = (async (config) => {
        const body: unknown = config.data ? JSON.parse(String(config.data)) : undefined;

        requests.push({ method: config.method ?? 'get', url: config.url ?? '', body });

        if (config.url === '/api/client/subscriptions') {
            return {
                data: { object: 'list', data: subscriptions },
                status: 200,
                statusText: 'OK',
                headers: {},
                config,
            };
        }

        return { data: '', status: 204, statusText: 'No Content', headers: {}, config };
    }) satisfies AxiosAdapter;
});

afterEach(() => {
    cleanup();
    host?.dispose();
    host = undefined;
    http.defaults.adapter = adapter;
});

type Attributes = Partial<Server['attributes']>;

async function renderInServer(children: ReactNode, attributes: Attributes = {}, permissions?: string[]) {
    const base = createTestServer({ identifier: 'a1b2c3d4', owner: !permissions, permissions });
    const server = { ...base, attributes: { ...base.attributes, ...attributes } };

    host = createExtensionTestHost({ server });
    await act(async () => {
        render(children, { wrapper: host!.Wrapper });
    });

    return server;
}

describe('server settings', () => {
    it('lays the page out from its blocks', async () => {
        await renderInServer(<View name='page.server.settings' />);

        for (const title of ['SFTP Details', 'Debug Information', 'Change Server Details', 'Reinstall Server']) {
            expect(screen.getAllByText(title)[0]).toBeVisible();
        }
    });

    it('shows the SFTP details of the viewer', async () => {
        await renderInServer(<View name='server.sftp' />, { sftp_details: { ip: '2001:db8::10', port: 2022 } });

        expect(screen.getByDisplayValue('sftp://[2001:db8::10]:2022')).toBeVisible();
        expect(screen.getByDisplayValue('Alex.a1b2c3d4')).toBeVisible();
        expect(screen.getByRole('link', { name: 'Launch SFTP' })).toHaveAttribute(
            'href',
            'sftp://Alex.a1b2c3d4@[2001:db8::10]:2022'
        );
    });

    it('leaves out the blocks the viewer may not use', async () => {
        await renderInServer(<View name='page.server.settings' />, {}, ['settings.rename']);

        expect(screen.queryByText('SFTP Details')).not.toBeInTheDocument();
        expect(screen.queryByText('Reinstall Server')).not.toBeInTheDocument();
        expect(screen.getByText('Change Server Details')).toBeVisible();
        expect(screen.getByText('Debug Information')).toBeVisible();
    });

    it('renames the server', async () => {
        const server = await renderInServer(<View name='server.rename' />, { name: 'Survival', description: null });

        fireEvent.change(screen.getByLabelText('Server Name'), { target: { value: 'Creative' } });
        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        });

        await waitFor(() =>
            expect(host!.queryClient.getQueryData(serverQueryOptions('a1b2c3d4').queryKey)).toMatchObject({
                attributes: { name: 'Creative' },
            })
        );
        expect(requests).toContainEqual({
            method: 'post',
            url: `/api/client/servers/${server.attributes.uuid}/settings/rename`,
            body: { name: 'Creative', description: '' },
        });
    });

    it('explains why a server skipping its install script cannot be reinstalled', async () => {
        await renderInServer(<View name='server.reinstall' />, { skip_scripts: true });

        expect(screen.getByText(SKIPPED_INSTALL_SCRIPT_MESSAGE)).toBeVisible();
        expect(screen.getByRole('button', { name: 'Reinstall Server' })).toBeDisabled();
    });

    it('reinstalls the server once confirmed', async () => {
        const server = await renderInServer(<View name='server.reinstall' />);

        fireEvent.click(screen.getByRole('button', { name: 'Reinstall Server' }));
        await act(async () => {
            fireEvent.click(await screen.findByRole('button', { name: 'Yes, reinstall server' }));
        });

        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        expect(requests).toContainEqual({
            method: 'post',
            url: `/api/client/servers/${server.attributes.uuid}/settings/reinstall`,
            body: undefined,
        });
    });
});

describe('server.autoSleep', () => {
    it('offers the switch to the payer of a plan that includes auto-sleep', async () => {
        subscriptions = [sleepySubscription()];
        await renderInServer(<View name='server.autoSleep' />);

        expect(await screen.findByText('Auto-sleep', { selector: 'h2, h3, p, span, div' })).toBeVisible();
        expect(screen.getByText('You saved 120 tokens by sleeping at the last renewal.')).toBeVisible();
        expect(screen.getByText(/costs 50% less/)).toBeVisible();

        await act(async () => {
            fireEvent.click(screen.getByRole('switch'));
        });

        await waitFor(() =>
            expect(requests).toContainEqual({
                method: 'patch',
                url: '/api/client/subscriptions/a1b2c3d4',
                body: { auto_sleep: false },
            })
        );
    });

    it('says when the game is not supported yet', async () => {
        subscriptions = [sleepySubscription({ supported: false, enabled: false })];
        await renderInServer(<View name='server.autoSleep' />);

        expect(await screen.findByText(/not supported for this game yet/)).toBeVisible();
        expect(screen.queryByRole('switch')).not.toBeInTheDocument();
    });

    it('renders nothing without a plan that includes it, or for someone who does not pay', async () => {
        subscriptions = [sleepySubscription({ allowed: false })];
        await renderInServer(<View name='server.autoSleep' />);
        await waitFor(() => expect(requests.some((request) => request.url === '/api/client/subscriptions')).toBe(true));

        expect(screen.queryByText(/sleeps until anyone starts it/)).not.toBeInTheDocument();
        cleanup();
        host?.dispose();

        subscriptions = [];
        await renderInServer(<View name='server.autoSleep' />, {}, ['control.start']);

        expect(screen.queryByText(/sleeps until anyone starts it/)).not.toBeInTheDocument();
    });
});

describe('server.conflict', () => {
    it.each([
        [{ status: 'install_failed' }, 'Running Installer'],
        [{ status: 'suspended' }, 'Server Suspended'],
        [{ status: null, is_node_under_maintenance: true }, 'Node under Maintenance'],
        [{ status: null, is_transferring: true }, 'Transferring'],
        [{ status: 'restoring_backup' }, 'Restoring from Backup'],
    ] satisfies [Attributes, string][])('names the conflict of %o', async (attributes, title) => {
        await renderInServer(<View name='server.conflict' />, attributes);

        expect(screen.getByRole('heading', { name: title })).toBeVisible();
    });

    it('renders nothing for a server without a conflict', async () => {
        await renderInServer(<View name='server.conflict' />);

        expect(screen.queryByRole('heading')).not.toBeInTheDocument();
    });
});
