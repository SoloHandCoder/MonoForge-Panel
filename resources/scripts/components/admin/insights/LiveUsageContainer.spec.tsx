/** @vitest-environment jsdom */

import type { ReactNode } from 'react';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { liveUsageFixture } from './fixtures';
import LiveUsageContainer from './LiveUsageContainer';

vi.mock('@tanstack/react-router', () => ({
    Link: ({ to, children, className }: { to: string; children: ReactNode; className?: string }) => (
        <a href={to} className={className}>
            {children}
        </a>
    ),
}));
vi.mock('@/api/admin/insights/queries', () => ({
    LIVE_USAGE_INTERVAL: 5000,
    useAdminLiveUsage: () => ({ data: liveUsageFixture, error: null, refetch: vi.fn() }),
}));
vi.mock('@/components/admin/AdminContentBlock', () => ({
    default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

afterEach(cleanup);

describe('LiveUsageContainer', () => {
    it('shows node CPU against its thread capacity, 100% per thread', () => {
        render(<LiveUsageContainer />);

        expect(screen.getByText('250% / 2100%')).toBeInTheDocument();
        expect(screen.getByText('1 of 2 running')).toBeInTheDocument();
    });

    it('greys out an unreachable node', () => {
        render(<LiveUsageContainer />);

        expect(screen.getByText('Offline')).toBeInTheDocument();
        expect(screen.getByText('1 server, unreachable')).toBeInTheDocument();
        expect(screen.getByText('Bravo').closest('.opacity-60')).not.toBeNull();
        expect(screen.getByText('Alpha').closest('.opacity-60')).toBeNull();
    });

    it('expands a node into its servers, busiest first and unknown readings last', () => {
        render(<LiveUsageContainer />);

        const toggle = screen.getByRole('button', { name: /1 of 2 running/ });

        expect(toggle).toHaveAttribute('aria-expanded', 'false');

        fireEvent.click(toggle);

        expect(toggle).toHaveAttribute('aria-expanded', 'true');

        const rows = within(document.getElementById('node-usage-1-servers')!).getAllByRole('listitem');

        expect(rows.map((row) => within(row).getAllByRole('link')[0].textContent)).toEqual(['Busy', 'Idle', 'Awaiting']);
        expect(within(rows[0]).getByText('250% / 400%')).toBeInTheDocument();
        expect(within(rows[1]).getByText('0%')).toBeInTheDocument();
        expect(within(rows[2]).getByText('Unknown')).toBeInTheDocument();
    });

    it('never renders a node address', () => {
        const { container } = render(<LiveUsageContainer />);

        expect(container.textContent).not.toMatch(/\d+\.\d+\.\d+\.\d+|example\.test/);
    });
});
