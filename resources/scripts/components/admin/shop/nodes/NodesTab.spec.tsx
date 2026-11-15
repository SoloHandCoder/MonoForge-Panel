/** @vitest-environment jsdom */

import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type * as ShopQueries from '@/api/admin/shop/queries';
import NodesTab from './NodesTab';

const node = (id: number, name: string, overrides: Partial<ShopQueries.ShopNode> = {}): ShopQueries.ShopNode => ({
    id,
    name,
    location: { id: 1, short: 'de', long: 'Frankfurt' },
    public: true,
    maintenance: false,
    shop_enabled: false,
    shop_servers: 0,
    limits: { memory: 0, disk: 0, cpu: 0 },
    used: { memory: 0, disk: 0, cpu: 0 },
    ...overrides,
});

const mutate = vi.fn();
const saveLimits = vi.fn();

vi.mock('@/api/admin/shop/queries', async (importOriginal) => ({
    ...(await importOriginal<typeof ShopQueries>()),
    useAdminShopNodes: () => ({
        data: [
            node(1, 'home-1', {
                shop_enabled: true,
                shop_servers: 3,
                limits: { memory: 8192, disk: 0, cpu: 400 },
                used: { memory: 6144, disk: 30720, cpu: 300 },
            }),
            node(2, 'spare', { public: false }),
        ],
        error: null,
        refetch: vi.fn(),
    }),
    useSetShopNodeEnabled: () => ({ mutate, isPending: false }),
    useSetShopNodeLimits: () => ({ mutate: saveLimits, isPending: false }),
}));

afterEach(() => {
    cleanup();
    mutate.mockClear();
    saveLimits.mockClear();
});

describe('NodesTab', () => {
    it('lists the nodes with their location, shop servers and availability', () => {
        render(<NodesTab />);

        expect(screen.getByText('home-1')).toBeTruthy();
        expect(screen.getByText('de · Frankfurt · 3 shop servers')).toBeTruthy();
        expect(screen.getByText('de · Frankfurt · 0 shop servers')).toBeTruthy();
        expect(screen.getByText('Private')).toBeTruthy();
        expect(screen.getByRole('switch', { name: 'Available in the shop: home-1' }).getAttribute('aria-checked')).toBe(
            'true'
        );
        expect(screen.getByRole('switch', { name: 'Available in the shop: spare' }).getAttribute('aria-checked')).toBe(
            'false'
        );
    });

    it('makes a node available in the shop', async () => {
        render(<NodesTab />);

        await userEvent.click(screen.getByRole('switch', { name: 'Available in the shop: spare' }));

        expect(mutate).toHaveBeenCalledWith({ id: 2, enabled: true });
    });

    it('shows what the shop servers use against each node limit', () => {
        render(<NodesTab />);

        expect(screen.getByText('6 GiB / 8 GiB')).toBeTruthy();
        expect(screen.getByText('300% / 400%')).toBeTruthy();
        expect(screen.getAllByText('0% / no limit')).toHaveLength(1);
        expect(screen.getAllByLabelText('Memory sold')).toHaveLength(2);
    });

    it('saves the node limits', async () => {
        render(<NodesTab />);
        const memory = screen.getByRole('textbox', { name: 'Memory (MB) limit: spare' });

        await userEvent.clear(memory);
        await userEvent.type(memory, '4096');
        await userEvent.tab();

        const [, save] = screen.getAllByRole('button', { name: 'Save limits' });

        await userEvent.click(save);

        expect(saveLimits).toHaveBeenCalledWith({ id: 2, limits: { memory: 4096, disk: 0, cpu: 0 } });
    });
});
