/** @vitest-environment jsdom */

import type { ReactNode } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { liveUsageFixture } from '@/components/admin/insights/fixtures';
import OverviewContainer from './OverviewContainer';

vi.mock('@tanstack/react-router', () => ({
    Link: ({ to, children, className }: { to: string; children: ReactNode; className?: string }) => (
        <a href={to} className={className}>
            {children}
        </a>
    ),
}));
vi.mock('@/api/admin/version/queries', () => ({
    useAdminVersion: () => ({
        data: {
            current: '1.0.0',
            latest: '1.0.0',
            is_latest: true,
            daemon: '1.0.0',
            discord: 'https://example.com/discord',
            donations: '',
        },
        error: null,
        refetch: vi.fn(),
    }),
}));
vi.mock('@/api/admin/insights/queries', () => ({
    useAdminLiveUsage: () => ({ data: liveUsageFixture, error: null }),
}));
vi.mock('@/api/admin/users/queries', () => ({
    useAdminUsers: () => ({ data: { meta: { pagination: { total: 42 } } } }),
}));
vi.mock('@/api/admin/activity/queries', () => ({
    adminActivityLogsQueryOptions: () => ({
        queryKey: ['admin-activity'],
        queryFn: async () => ({
            data: Array.from({ length: 7 }, (_, index) => ({
                object: 'activity_log',
                attributes: {
                    id: `log-${index}`,
                    event: `user:account.event-${index}`,
                    ip: '203.0.113.9',
                    timestamp: new Date().toISOString(),
                    relationships: { actor: { object: 'user', attributes: { username: 'owner' } } },
                },
            })),
            meta: { pagination: { total: 7 } },
        }),
    }),
}));
vi.mock('@/api/admin/updates/queries', () => ({
    isUpdateInProgress: () => false,
    useAdminUpdateStatus: () => ({ data: undefined }),
    useApplyAdminUpdate: () => ({ mutateAsync: vi.fn(), isPending: false }),
}));
vi.mock('@/components/admin/AdminContentBlock', () => ({
    default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));
vi.mock('@/extensions/Slot', () => ({ default: () => null }));

afterEach(cleanup);

const renderOverview = () =>
    render(
        <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
            <OverviewContainer />
        </QueryClientProvider>
    );

describe('OverviewContainer', () => {
    it('sums live usage into the stat tiles', () => {
        renderOverview();

        expect(screen.getByText('42')).toBeInTheDocument();
        expect(screen.getByText('/ 2')).toBeInTheDocument();
        expect(screen.getByText('/ 3')).toBeInTheDocument();
        expect(screen.getAllByText('250% / 2100%').length).toBeGreaterThan(0);
        expect(screen.getByText('7 / 48 GiB')).toBeInTheDocument();
    });

    it('shows the five latest activity entries without addresses', async () => {
        renderOverview();

        expect(await screen.findByText('user:account.event-0')).toBeInTheDocument();
        expect(screen.getByText('user:account.event-4')).toBeInTheDocument();
        expect(screen.queryByText('user:account.event-5')).toBeNull();
        expect(document.body.textContent).not.toContain('203.0.113.9');
    });

    it('links to the insight pages and to MonoForge on GitHub, nowhere upstream', () => {
        renderOverview();

        for (const [name, href] of [
            ['Announcements', '/panel/announcements'],
            ['Live usage', '/panel/usage'],
            ['Storage', '/panel/storage'],
            ['Settings', '/panel/settings'],
        ]) {
            expect(screen.getAllByRole('link', { name }).some((link) => link.getAttribute('href') === href)).toBe(true);
        }

        expect(screen.getByRole('link', { name: 'MonoForge on GitHub' })).toHaveAttribute(
            'href',
            'https://github.com/SoloHandCoder/MonoForge-Panel'
        );
        for (const link of screen.getAllByRole('link')) {
            expect(link.getAttribute('href')).not.toMatch(/pterodactyl/i);
        }
    });
});
