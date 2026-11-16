/** @vitest-environment jsdom */

import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type * as DiscoveryQueries from '@/api/admin/servers/pageDiscovery';
import type { AdminServerPageDiscovery } from '@/api/admin/servers/pageDiscovery';
import ServerPageDiscoveryCard from './ServerPageDiscoveryCard';

const state = vi.hoisted(() => ({
    page: undefined as AdminServerPageDiscovery | undefined,
    mutate: vi.fn(),
}));

vi.mock('@/api/admin/servers/pageDiscovery', async (importOriginal) => ({
    ...(await importOriginal<typeof DiscoveryQueries>()),
    useAdminServerPageDiscovery: () => ({ data: state.page, isError: false }),
    useUpdateAdminServerPageDiscovery: () => ({ mutate: state.mutate, isPending: false }),
}));

const page = (overrides: Partial<AdminServerPageDiscovery> = {}): AdminServerPageDiscovery => ({
    exists: true,
    slug: 'skyblock',
    url: 'https://panel.example.com/s/skyblock',
    published: true,
    discoverable: true,
    hidden_from_discovery: false,
    listed: true,
    ...overrides,
});

afterEach(cleanup);
beforeEach(() => {
    vi.clearAllMocks();
    state.page = page();
});

describe('public page discovery card', () => {
    it('hides a listed page from discovery', async () => {
        const user = userEvent.setup();

        render(<ServerPageDiscoveryCard serverId={7} />);

        expect(screen.getByText(/Listed on the server discovery page/)).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Hide from discovery' }));
        expect(state.mutate).toHaveBeenCalledWith({ path: { server_admin_identifier: '7' }, body: { hidden: true } });
    });

    it('lists a hidden page again', async () => {
        const user = userEvent.setup();

        state.page = page({ hidden_from_discovery: true, listed: false });
        render(<ServerPageDiscoveryCard serverId={7} />);

        expect(screen.getByText(/Hidden from the discovery page by an administrator/)).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'List again' }));
        expect(state.mutate).toHaveBeenCalledWith({ path: { server_admin_identifier: '7' }, body: { hidden: false } });
    });

    it('has nothing to hide without a page', () => {
        state.page = page({ exists: false, slug: null, url: null, published: false, listed: false });
        render(<ServerPageDiscoveryCard serverId={7} />);

        expect(screen.getByText('This server has no public page.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Hide from discovery' })).toBeDisabled();
    });
});
