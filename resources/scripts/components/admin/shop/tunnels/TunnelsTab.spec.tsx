/** @vitest-environment jsdom */

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type * as TunnelQueries from '@/api/admin/tunnels/queries';
import TunnelsTab from './TunnelsTab';

const summary: TunnelQueries.TunnelImportSummary = {
    imported: 1,
    skipped: 1,
    errors: [{ node_id: 2, node_name: 'node-2', message: 'playit rejected this agent key.' }],
    results: [
        {
            node_id: 1,
            node_name: 'node-1',
            playit_tunnel_id: 'a',
            name: 'survival',
            local_ip: '127.0.0.1',
            local_port: 25565,
            address: 'xyz.joinmc.link',
            result: 'imported',
            reason: 'Linked to Survival world.',
            server_ids: [12],
        },
        {
            node_id: 1,
            node_name: 'node-1',
            playit_tunnel_id: 'b',
            name: null,
            local_ip: '127.0.0.1',
            local_port: 25577,
            address: 'def.ply.gg:41000',
            result: 'ambiguous',
            reason: '2 servers on node-1 use port 25577, so the panel cannot tell which one the tunnel is for.',
            server_ids: [14, 15],
        },
    ],
};

const unlinked: TunnelQueries.UnlinkedTunnels = {
    nodes: [
        { id: 1, name: 'node-1', read_only: true },
        { id: 2, name: 'node-2', read_only: false },
    ],
    servers: [
        {
            server_id: 126,
            server_name: 'Survival world',
            server_identifier: '1a2b3c4d',
            node_id: 1,
            node_name: 'node-1',
            local_ip: '127.0.0.1',
            local_port: 25569,
            tunnel_status: 'failed',
        },
    ],
};

const mutate = vi.fn((_node: number | undefined, options?: { onSuccess?: (result: typeof summary) => void }) =>
    options?.onSuccess?.(summary)
);
const idle = { mutate: vi.fn(), mutateAsync: vi.fn(), isPending: false };

vi.mock('@/api/admin/tunnels/queries', async (importOriginal) => ({
    ...(await importOriginal<typeof TunnelQueries>()),
    useAdminTunnels: () => ({
        data: {
            object: 'list',
            data: [],
            meta: { pagination: { total: 0, per_page: 50, current_page: 1, total_pages: 1 } },
        },
        error: null,
        isFetching: false,
        refetch: vi.fn(),
    }),
    useAdminTunnelSettings: () => ({ data: undefined }),
    useCreateTunnel: () => idle,
    useRetryTunnel: () => idle,
    useRecreateTunnel: () => idle,
    useUpdateTunnelSettings: () => idle,
    useImportTunnels: () => ({ mutate, isPending: false }),
    useUnlinkedTunnels: () => ({ data: unlinked }),
}));

afterEach(() => {
    cleanup();
    mutate.mockClear();
});

describe('TunnelsTab', () => {
    it('imports existing tunnels and shows what happened to each', async () => {
        render(
            <QueryClientProvider client={new QueryClient()}>
                <TunnelsTab />
            </QueryClientProvider>
        );

        await userEvent.click(screen.getByRole('button', { name: /Import existing tunnels/ }));

        expect(mutate).toHaveBeenCalledOnce();

        const dialog = await screen.findByRole('dialog');

        expect(dialog.textContent).toContain('1 imported, 1 skipped.');
        expect(dialog.textContent).toContain('node-2: playit rejected this agent key.');
        expect(dialog.textContent).toContain('survival');
        expect(dialog.textContent).toContain('Linked to Survival world.');
        expect(dialog.textContent).toContain('Ambiguous');
        expect(dialog.textContent).toContain('node-1 · port 25577 · def.ply.gg:41000');
    });

    it('lists servers without a tunnel and says when an agent key is read-only', () => {
        render(
            <QueryClientProvider client={new QueryClient()}>
                <TunnelsTab />
            </QueryClientProvider>
        );

        const alert = screen.getByRole('alert');

        expect(alert.textContent).toContain(
            'This agent key is read-only: create tunnels in the playit dashboard; the panel links them automatically.'
        );
        expect(alert.textContent).toContain('Nodes: node-1.');
        expect(screen.getByText('Survival world')).toBeTruthy();
        expect(screen.getByText('#126 on node-1')).toBeTruthy();
        expect(screen.getByText('127.0.0.1:25569')).toBeTruthy();
    });
});
