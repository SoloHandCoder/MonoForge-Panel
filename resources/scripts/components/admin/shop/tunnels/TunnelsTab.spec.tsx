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
});
