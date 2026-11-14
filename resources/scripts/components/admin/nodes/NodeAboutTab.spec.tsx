/** @vitest-environment jsdom */

import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import NodeAboutTab from './NodeAboutTab';

const mocks = vi.hoisted(() => ({ daemon: '1.12.0' }));

vi.mock('@tanstack/react-router', () => ({ useNavigate: () => vi.fn() }));
vi.mock('@/api/admin/version/queries', () => ({
    useAdminVersion: () => ({
        data: { current: '1.0.0', latest: '1.0.0', is_latest: true, daemon: mocks.daemon, discord: '', donations: '' },
    }),
}));
vi.mock('@/api/admin/nodes/queries', () => ({
    deleteAdminNodeInput: vi.fn(),
    useAdminNodeUtilization: () => ({ data: undefined }),
    useAdminNodeSystemInformation: () => ({
        data: { version: '1.11.13', os: 'linux', architecture: 'amd64', kernel_version: '6.1', cpu_count: 4 },
        error: null,
    }),
    useDeleteAdminNode: () => ({ isPending: false, mutateAsync: vi.fn() }),
}));
vi.mock('@/components/admin/nodes/useNodeDetail', () => ({
    useNodeDetail: () => ({
        node: {
            attributes: { id: 4, name: 'Node', description: null, maintenance_mode: false, servers_count: 0 },
        },
    }),
}));

afterEach(() => {
    cleanup();
    mocks.daemon = '1.12.0';
});

describe('NodeAboutTab', () => {
    it('shows the latest daemon version when the version service knows it', () => {
        render(<NodeAboutTab />);

        expect(screen.getByText('1.11.13')).toBeVisible();
        expect(screen.getByText('Latest: 1.12.0')).toBeVisible();
    });

    it('leaves the latest label out when the version service has no data', () => {
        mocks.daemon = '';
        render(<NodeAboutTab />);

        expect(screen.getByText('1.11.13')).toBeVisible();
        expect(screen.queryByText(/Latest:/)).not.toBeInTheDocument();
    });
});
