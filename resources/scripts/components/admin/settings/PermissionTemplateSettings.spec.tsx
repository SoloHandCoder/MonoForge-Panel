/** @vitest-environment jsdom */
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import type { AxiosAdapter } from 'axios';
import type { PropsWithChildren } from 'react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import http from '@/api/http';
import { systemPermissionsQueryOptions } from '@/api/system/queries';
import PermissionTemplateSettings from './PermissionTemplateSettings';

const permissions = {
    websocket: { description: 'Connect to the console.', keys: { connect: 'Stream the console.' } },
    control: { description: 'Control the power state.', keys: { console: 'Send commands.', start: 'Start it.' } },
    file: { description: 'Manage files.', keys: { read: 'List files.' } },
};

const templates = {
    object: 'list',
    data: [
        {
            object: 'permission_template',
            attributes: {
                id: 7,
                name: 'Staff',
                permissions: ['websocket.connect', 'control.console'],
                created_at: '2026-11-15T12:00:00+00:00',
                updated_at: '2026-11-15T12:00:00+00:00',
            },
        },
    ],
};

let requests: { method: string; url: string; body: unknown }[];
const adapter = http.defaults.adapter;

beforeEach(() => {
    requests = [];
    http.defaults.adapter = (async (config) => {
        const body: unknown = config.data ? JSON.parse(String(config.data)) : undefined;

        requests.push({ method: config.method ?? 'get', url: config.url ?? '', body });

        return {
            data:
                config.method === 'get'
                    ? templates
                    : { object: 'permission_template', attributes: templates.data[0].attributes },
            status: 200,
            statusText: 'OK',
            headers: {},
            config,
        };
    }) satisfies AxiosAdapter;
});

afterEach(() => {
    cleanup();
    http.defaults.adapter = adapter;
});

const renderSettings = () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    queryClient.setQueryData(systemPermissionsQueryOptions.queryKey, {
        object: 'system_permissions',
        attributes: { permissions },
    });
    const wrapper = ({ children }: PropsWithChildren) => (
        <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
    );

    render(<PermissionTemplateSettings />, { wrapper });
};

describe('PermissionTemplateSettings', () => {
    it('creates a global template from the checked permissions, without console access', async () => {
        renderSettings();

        expect(await screen.findByText('Staff')).toBeVisible();
        expect(screen.queryByRole('checkbox', { name: 'connect' })).not.toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Name'), { target: { value: ' Helpers ' } });
        fireEvent.click(screen.getByRole('checkbox', { name: 'read' }));
        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Create template' }));
        });

        await waitFor(() =>
            expect(requests).toContainEqual({
                method: 'post',
                url: '/api/admin/settings/permission-templates',
                body: { name: 'Helpers', permissions: ['file.read'] },
            })
        );
    });

    it('edits a template with its permissions filled in', async () => {
        renderSettings();

        fireEvent.click(await screen.findByRole('button', { name: 'Edit Staff' }));
        expect(screen.getByLabelText('Name')).toHaveValue('Staff');
        expect(screen.getByRole('checkbox', { name: 'console' })).toBeChecked();

        fireEvent.click(screen.getByRole('checkbox', { name: 'start' }));
        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Save template' }));
        });

        await waitFor(() =>
            expect(requests).toContainEqual({
                method: 'put',
                url: '/api/admin/settings/permission-templates/7',
                body: { name: 'Staff', permissions: ['control.console', 'control.start'] },
            })
        );
    });

    it('deletes a template after confirming', async () => {
        renderSettings();

        fireEvent.click(await screen.findByRole('button', { name: 'Delete Staff' }));
        const dialog = await screen.findByRole('dialog');

        await act(async () => {
            fireEvent.click(within(dialog).getByRole('button', { name: 'Delete' }));
        });

        await waitFor(() =>
            expect(requests).toContainEqual({
                method: 'delete',
                url: '/api/admin/settings/permission-templates/7',
                body: undefined,
            })
        );
    });
});
