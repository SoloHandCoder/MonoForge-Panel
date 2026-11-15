/** @vitest-environment jsdom */

import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type * as TokenPackQueries from '@/api/store/tokenPacks';
import { isTebexCheckoutUrl, type TokenPackList } from '@/api/store/tokenPacks';
import TokenPacks from './TokenPacks';

const state = vi.hoisted(() => ({
    packs: { enabled: true, data: [] } as TokenPackList,
    checkout: vi.fn<(packId: number) => Promise<string>>(),
}));

vi.mock('@/api/store/tokenPacks', async (importOriginal) => ({
    ...(await importOriginal<typeof TokenPackQueries>()),
    useTokenPacks: () => ({ data: state.packs, error: null, isLoading: false }),
    useTokenPackCheckout: () => ({ mutateAsync: state.checkout }),
}));

// The legal links read the page bootstrap; without one they point at the panel's own pages.
vi.mock('@/api/settings/queries', () => ({ useSiteSettings: () => null }));

const assign = vi.fn();

afterEach(cleanup);
beforeEach(() => {
    vi.clearAllMocks();
    vi.stubGlobal('location', { ...window.location, search: '', assign });
});

describe('token packs', () => {
    it('lists the packs with their bonus and opens the Tebex checkout', async () => {
        state.packs = {
            enabled: true,
            data: [
                { id: 1, name: 'Starter', tokens: 500, bonus_tokens: 50 },
                { id: 2, name: 'Big', tokens: 2000, bonus_tokens: 0 },
            ],
        };
        state.checkout.mockResolvedValue('https://pay.tebex.io/abc');

        render(<TokenPacks />);

        expect(screen.getByText('+50 bonus tokens')).toBeVisible();
        expect(screen.getByText(/By purchasing you agree to the/)).toBeVisible();
        expect(screen.getByRole('link', { name: 'Terms of Service' })).toHaveAttribute('href', '/legal/terms');
        expect(screen.getByRole('link', { name: 'Refund Policy' })).toHaveAttribute('target', '_blank');
        await userEvent.click(screen.getByRole('button', { name: 'Buy Starter' }));

        expect(state.checkout).toHaveBeenCalledWith(1);
        expect(assign).toHaveBeenCalledWith('https://pay.tebex.io/abc');
    });

    it('never follows a checkout link off Tebex', async () => {
        state.packs = { enabled: true, data: [{ id: 1, name: 'Starter', tokens: 500, bonus_tokens: 0 }] };
        state.checkout.mockResolvedValue('https://evil.example.com/pay');

        render(<TokenPacks />);
        await userEvent.click(screen.getByRole('button', { name: 'Buy Starter' }));

        expect(assign).not.toHaveBeenCalled();
        expect(screen.getByRole('alert')).toHaveTextContent('unexpected checkout link');
    });

    it('renders nothing while no Tebex store is connected', () => {
        state.packs = { enabled: false, data: [] };

        const { container } = render(<TokenPacks />);

        expect(container).toBeEmptyDOMElement();
    });

    it('accepts only https links on tebex.io', () => {
        expect(isTebexCheckoutUrl('https://pay.tebex.io/x')).toBe(true);
        expect(isTebexCheckoutUrl('https://tebex.io/x')).toBe(true);
        expect(isTebexCheckoutUrl('http://pay.tebex.io/x')).toBe(false);
        expect(isTebexCheckoutUrl('https://pay.tebex.io.evil.com/x')).toBe(false);
        expect(isTebexCheckoutUrl('javascript:alert(1)')).toBe(false);
    });
});
