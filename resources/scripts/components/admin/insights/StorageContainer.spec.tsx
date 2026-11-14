/** @vitest-environment jsdom */

import type { ReactNode } from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { storageUsageFixture } from './fixtures';
import StorageContainer from './StorageContainer';

vi.mock('@tanstack/react-router', () => ({
    Link: ({ to, children, className }: { to: string; children: ReactNode; className?: string }) => (
        <a href={to} className={className}>
            {children}
        </a>
    ),
}));
vi.mock('@/api/admin/insights/queries', () => ({
    useAdminStorageUsage: () => ({ data: storageUsageFixture, error: null, refetch: vi.fn() }),
}));
vi.mock('@/components/admin/AdminContentBlock', () => ({
    default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

afterEach(cleanup);

describe('StorageContainer', () => {
    it('shows the website card and each node’s largest servers', () => {
        render(<StorageContainer />);

        expect(screen.getByText('Website')).toBeInTheDocument();
        expect(screen.getByText('50 MiB')).toBeInTheDocument();
        expect(screen.getByText('Unknown')).toBeInTheDocument();
        expect(screen.getByText('1 backup')).toBeInTheDocument();
        expect(screen.getByText('19 GiB / 40 GiB')).toBeInTheDocument();
        expect(screen.getByText('— / 10 GiB')).toBeInTheDocument();
        expect(screen.getByText('No reading while the node is offline')).toBeInTheDocument();
    });
});
