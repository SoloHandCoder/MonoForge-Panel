/** @vitest-environment jsdom */

import type { ReactNode } from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
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
vi.mock('@/components/admin/AdminContentBlock', () => ({
    default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));
vi.mock('@/extensions/Slot', () => ({ default: () => null }));

afterEach(cleanup);

describe('OverviewContainer', () => {
    it('points Documentation and GitHub at the MonoForge repository', () => {
        render(<OverviewContainer />);

        expect(screen.getByRole('link', { name: 'Documentation' })).toHaveAttribute(
            'href',
            'https://github.com/SoloHandCoder/MonoForge-Panel#readme'
        );
        expect(screen.getByRole('link', { name: 'GitHub' })).toHaveAttribute(
            'href',
            'https://github.com/SoloHandCoder/MonoForge-Panel'
        );
    });

    it('links nowhere upstream', () => {
        render(<OverviewContainer />);

        for (const link of screen.getAllByRole('link')) {
            expect(link.getAttribute('href')).not.toMatch(/pterodactyl/i);
        }
    });
});
