/** @vitest-environment jsdom */

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { PropsWithChildren } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { readCheckoutReturn, rememberCheckout, type TokenPurchase } from '@/api/account/wallet/purchases';
import TokenPurchaseReturn from './TokenPurchaseReturn';

const REF = '0b8f6c2e-5d4a-4e7b-9c1f-3a2d6e8b7f10';

const mocks = vi.hoisted(() => ({ getPurchase: vi.fn(), navigate: vi.fn() }));

vi.mock('@/api/generated', () => ({ clientGetTokenPurchase: mocks.getPurchase }));
vi.mock('@tanstack/react-router', () => ({
    Link: ({ to, children, className }: PropsWithChildren<{ to: string; className?: string }>) => (
        <a href={to} className={className}>
            {children}
        </a>
    ),
    useNavigate: () => mocks.navigate,
}));

const purchase = (overrides: Partial<TokenPurchase> = {}): TokenPurchase => ({
    id: REF,
    status: 'pending',
    pack_name: '500 tokens',
    tokens: 0,
    balance: 1000,
    created_at: '2026-11-15T12:00:00+00:00',
    ...overrides,
});

const answer = (data: TokenPurchase) => ({ data: { data } });

function renderReturn(search: string) {
    vi.stubGlobal('location', { ...window.location, search });
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    return render(<TokenPurchaseReturn />, {
        wrapper: ({ children }: PropsWithChildren) => (
            <QueryClientProvider client={client}>{children}</QueryClientProvider>
        ),
    });
}

/** Lets the timers and the query's promises run. */
const advance = async (ms: number) => {
    await act(async () => {
        await vi.advanceTimersByTimeAsync(ms);
    });
};

beforeEach(() => {
    vi.useFakeTimers();
    window.sessionStorage.clear();
});

afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.clearAllMocks();
});

describe('token purchase return', () => {
    it('confirms, polls every 2 seconds and stops once the tokens are added', async () => {
        mocks.getPurchase
            .mockResolvedValueOnce(answer(purchase()))
            .mockResolvedValueOnce(answer(purchase()))
            .mockResolvedValue(answer(purchase({ status: 'completed', tokens: 550, balance: 1550 })));

        renderReturn(`?tebex=complete&checkout=${REF}`);
        await advance(0);

        expect(screen.getByRole('heading', { name: 'Confirming your payment…' })).toBeVisible();
        expect(mocks.getPurchase).toHaveBeenCalledTimes(1);
        expect(mocks.getPurchase.mock.calls[0]?.[0]).toMatchObject({ path: { checkout: REF } });

        await advance(2_000);
        expect(mocks.getPurchase).toHaveBeenCalledTimes(2);
        await advance(2_100);
        expect(mocks.getPurchase).toHaveBeenCalledTimes(3);

        expect(screen.getByRole('heading', { name: '+550 tokens added' })).toBeVisible();
        expect(screen.getByTestId('purchase-balance')).toHaveTextContent('1,550 tokens');
        expect(screen.getByRole('link', { name: 'Go to store' })).toHaveAttribute('href', '/store');
        expect(screen.getByRole('button', { name: 'Wallet' })).toBeVisible();

        const calls = mocks.getPurchase.mock.calls.length;

        await advance(20_000);
        expect(mocks.getPurchase).toHaveBeenCalledTimes(calls);
    });

    it('shows only what the server confirmed, never numbers from the URL', async () => {
        mocks.getPurchase.mockResolvedValue(answer(purchase({ status: 'completed', tokens: 550, balance: 1550 })));

        renderReturn(`?tebex=complete&checkout=${REF}&tokens=999999&balance=999999&status=completed`);
        await advance(0);

        expect(screen.getByRole('heading', { name: '+550 tokens added' })).toBeVisible();
        expect(screen.queryByText(/999,999/)).toBeNull();
    });

    it('turns calm after a minute and keeps checking in the background', async () => {
        mocks.getPurchase.mockResolvedValue(answer(purchase()));

        renderReturn(`?tebex=complete&checkout=${REF}`);
        await advance(59_000);
        expect(screen.getByRole('heading', { name: 'Confirming your payment…' })).toBeVisible();

        await advance(1_000);
        expect(screen.getByRole('heading', { name: 'Still confirming' })).toBeVisible();
        expect(screen.getByText('Your tokens will be added automatically; you can close this page.')).toBeVisible();

        const calls = mocks.getPurchase.mock.calls.length;

        await advance(10_000);
        expect(mocks.getPurchase.mock.calls.length).toBeGreaterThan(calls);
    });

    it.each([
        ['refunded', 'Payment refunded'],
        ['declined', 'Payment declined'],
        ['review', 'Payment being checked'],
    ] as const)('shows the %s state and stops polling', async (status, heading) => {
        mocks.getPurchase.mockResolvedValue(answer(purchase({ status, tokens: 0 })));

        renderReturn(`?tebex=complete&checkout=${REF}`);
        await advance(0);

        expect(screen.getByRole('heading', { name: heading })).toBeVisible();
        expect(screen.queryByText(/tokens added/)).toBeNull();
        await advance(10_000);
        expect(mocks.getPurchase).toHaveBeenCalledTimes(1);
    });

    it('a cancelled checkout is checked once and not polled', async () => {
        mocks.getPurchase.mockResolvedValue(answer(purchase()));

        renderReturn(`?tebex=cancelled&checkout=${REF}`);
        await advance(0);

        expect(screen.getByRole('heading', { name: 'Checkout cancelled' })).toBeVisible();
        await advance(10_000);
        expect(mocks.getPurchase).toHaveBeenCalledTimes(1);
    });

    it('a cancelled checkout that was paid after all shows the tokens', async () => {
        mocks.getPurchase.mockResolvedValue(answer(purchase({ status: 'completed', tokens: 550, balance: 1550 })));

        renderReturn(`?tebex=cancelled&checkout=${REF}`);
        await advance(0);

        expect(screen.getByRole('heading', { name: '+550 tokens added' })).toBeVisible();
    });

    it('says so calmly when the purchase is not found', async () => {
        mocks.getPurchase.mockRejectedValue(new Error('Not found'));

        renderReturn(`?tebex=complete&checkout=${REF}`);
        await advance(0);

        expect(screen.getByRole('heading', { name: 'We could not look up this purchase' })).toBeVisible();
        await advance(10_000);
        expect(mocks.getPurchase).toHaveBeenCalledTimes(1);
    });

    it('asks nothing for a reference that is not a UUID, and claims nothing', async () => {
        renderReturn('?tebex=complete&checkout=../../admin');
        await advance(0);

        expect(mocks.getPurchase).not.toHaveBeenCalled();
        expect(screen.getByRole('heading', { name: 'Back from Tebex' })).toBeVisible();
        expect(screen.queryByText(/tokens added/)).toBeNull();
    });

    it('falls back to the checkout remembered before leaving for Tebex', async () => {
        rememberCheckout(REF);
        mocks.getPurchase.mockResolvedValue(answer(purchase()));

        renderReturn('?tebex=complete');
        await advance(0);

        expect(mocks.getPurchase.mock.calls[0]?.[0]).toMatchObject({ path: { checkout: REF } });
    });

    it('closes onto the wallet and forgets the checkout', async () => {
        rememberCheckout(REF);
        mocks.getPurchase.mockResolvedValue(answer(purchase({ status: 'completed', tokens: 550, balance: 1550 })));

        renderReturn(`?tebex=complete&checkout=${REF}`);
        await advance(0);
        fireEvent.click(screen.getByRole('button', { name: 'Wallet' }));

        expect(screen.queryByRole('dialog')).toBeNull();
        expect(mocks.navigate).toHaveBeenCalledWith({ to: '/account/wallet', replace: true });
        expect(window.sessionStorage.getItem('monoforge:tebex-checkout')).toBeNull();
    });

    it('renders nothing without a Tebex return', () => {
        const { container } = renderReturn('');

        expect(container).toBeEmptyDOMElement();
        expect(readCheckoutReturn('?tebex=other')).toBeNull();
    });
});
