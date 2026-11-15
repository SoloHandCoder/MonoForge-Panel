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
    ...overrides,
});

const mutate = vi.fn();

vi.mock('@/api/admin/shop/queries', async (importOriginal) => ({
    ...(await importOriginal<typeof ShopQueries>()),
    useAdminShopNodes: () => ({
        data: [node(1, 'home-1', { shop_enabled: true, shop_servers: 3 }), node(2, 'spare', { public: false })],
        error: null,
        refetch: vi.fn(),
    }),
    useSetShopNodeEnabled: () => ({ mutate, isPending: false }),
}));

afterEach(() => {
    cleanup();
    mutate.mockClear();
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
});
