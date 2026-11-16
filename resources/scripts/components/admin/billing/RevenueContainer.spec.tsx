/** @vitest-environment jsdom */

import type { ReactNode } from 'react';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { AdminRevenueQuery } from '@/api/admin/billing/queries';
import { revenueFixture } from './fixtures';
import RevenueContainer from './RevenueContainer';

const queries: AdminRevenueQuery[] = [];

vi.mock('@/api/admin/billing/queries', () => ({
    useAdminRevenue: (query: AdminRevenueQuery) => {
        queries.push(query);

        return { data: revenueFixture, error: null, isFetching: false, refetch: vi.fn() };
    },
}));
vi.mock('@/components/admin/AdminContentBlock', () => ({
    default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

beforeEach(() => {
    queries.length = 0;
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-11-16T12:00:00'));
});

afterEach(() => {
    vi.useRealTimers();
    cleanup();
});

describe('RevenueContainer', () => {
    it('shows the KPIs, money per currency, subscriptions and plans', () => {
        render(<RevenueContainer />);

        expect(screen.getByText('Tokens sold', { selector: 'p' }).nextSibling).toHaveTextContent('1,500');
        expect(screen.getByText('Net tokens', { selector: 'p' }).nextSibling).toHaveTextContent('700');
        expect(screen.getByText('800 tokens refunded or charged back')).toBeInTheDocument();

        const money = screen.getByRole('table', { name: /money per currency/i });

        expect(within(money).getByText('€14.98')).toBeInTheDocument();
        expect(within(money).getByText('-$3.50')).toBeInTheDocument();
        expect(screen.getByText(/1 dispute is open/)).toBeInTheDocument();

        expect(screen.getByText('1 short')).toBeInTheDocument();
        expect(screen.getByText('2 · 400 tokens')).toBeInTheDocument();
        expect(screen.getByText('=Small')).toBeInTheDocument();
        expect(screen.getByText('Deleted plan')).toBeInTheDocument();
        expect(screen.getByText('40 tokens')).toBeInTheDocument();
        expect(screen.getByText('7 tokens')).toBeInTheDocument();
    });

    it('labels each chart with its total and reads a day with the arrow keys', () => {
        render(<RevenueContainer />);

        const chart = screen.getByRole('group', { name: /^Tokens sold per day: 1,500 in total over 3 days/ });

        fireEvent.keyDown(chart, { key: 'ArrowRight' });
        expect(within(chart).getByText('Nov 14: 0')).toBeInTheDocument();
        fireEvent.keyDown(chart, { key: 'End' });
        expect(within(chart).getByText('Nov 16: 500')).toBeInTheDocument();

        expect(screen.getAllByText('Show as a table')).toHaveLength(2);
    });

    it('switches presets and only asks for a custom range the panel accepts', () => {
        render(<RevenueContainer />);
        expect(queries.at(-1)).toEqual({ range: '30' });

        fireEvent.click(screen.getByRole('button', { name: '7 days' }));
        expect(queries.at(-1)).toEqual({ range: '7' });
        expect(screen.getByRole('button', { name: '7 days' })).toHaveAttribute('aria-pressed', 'true');

        fireEvent.click(screen.getByRole('button', { name: 'Custom' }));
        fireEvent.change(screen.getByLabelText('From'), { target: { value: '2026-11-10' } });
        fireEvent.change(screen.getByLabelText('To'), { target: { value: '2026-11-01' } });
        expect(screen.getByRole('alert')).toHaveTextContent('on or after');
        expect(screen.getByRole('button', { name: 'Show' })).toBeDisabled();

        fireEvent.change(screen.getByLabelText('To'), { target: { value: '2026-11-12' } });
        fireEvent.click(screen.getByRole('button', { name: 'Show' }));
        expect(queries.at(-1)).toEqual({ range: 'custom', from: '2026-11-10', to: '2026-11-12' });
    });
});
