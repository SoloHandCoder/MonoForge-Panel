/** @vitest-environment jsdom */
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen } from '@testing-library/react';
import type { PropsWithChildren } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { TokenLedgerPage } from '@/api/account/wallet/queries';
import { parseTokenAdjustment } from '@/components/admin/users/UserTokensCard';
import WalletContainer from './WalletContainer';

const mocks = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock('@/api/http', () => ({ default: { get: mocks.get }, httpErrorToHuman: () => 'error' }));
vi.mock('@/components/store/TokenPacks', () => ({ default: () => <p>Token packs</p> }));

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});

const ledger: TokenLedgerPage = {
    object: 'list',
    data: [
        {
            object: 'token_transaction',
            attributes: {
                id: 2,
                amount: -500,
                balance_after: 1500,
                type: 'server_charge',
                reference_type: 'server',
                reference_id: '12',
                description: 'Renewed My Server',
                created_at: '2026-11-15T12:00:00+00:00',
            },
        },
        {
            object: 'token_transaction',
            attributes: {
                id: 1,
                amount: 2000,
                balance_after: 2000,
                type: 'purchase',
                reference_type: 'tebex',
                reference_id: 'tbx-1',
                description: 'Bought 2000 tokens',
                created_at: '2026-11-14T12:00:00+00:00',
            },
        },
    ],
    meta: { balance: 1500, pagination: { total: 2, count: 2, per_page: 25, current_page: 1, total_pages: 1 } },
};

const wrapper = ({ children }: PropsWithChildren) => (
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        {children}
    </QueryClientProvider>
);

describe('WalletContainer', () => {
    it('shows the balance, the token packs and the signed history', async () => {
        mocks.get.mockResolvedValue({ data: ledger });

        render(<WalletContainer />, { wrapper });

        expect(await screen.findByTestId('wallet-balance')).toHaveTextContent('1,500tokens');
        expect(screen.getByText('Token packs')).toBeInTheDocument();
        expect(screen.getByText('Renewed My Server')).toBeInTheDocument();
        expect(screen.getByText('−500')).toBeInTheDocument();
        expect(screen.getByText('+2,000')).toBeInTheDocument();
        expect(mocks.get).toHaveBeenCalledWith(
            '/api/client/account/wallet',
            expect.objectContaining({ params: { page: 1, per_page: 25 } })
        );
    });
});

describe('parseTokenAdjustment', () => {
    it('accepts whole non-zero tokens only', () => {
        expect(parseTokenAdjustment(' 250 ')).toBe(250);
        expect(parseTokenAdjustment('-50')).toBe(-50);
        expect(parseTokenAdjustment('0')).toBeNull();
        expect(parseTokenAdjustment('1.5')).toBeNull();
        expect(parseTokenAdjustment('abc')).toBeNull();
    });
});
