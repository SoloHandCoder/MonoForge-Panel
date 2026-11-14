/** @vitest-environment jsdom */

import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { AdminVersion } from '@/api/admin/version/queries';
import type { AdminUpdateStatus } from '@/api/admin/updates/queries';
import OverviewVersionCard from './OverviewVersionCard';

const mocks = vi.hoisted(() => ({
    status: undefined as AdminUpdateStatus | undefined,
    mutateAsync: vi.fn(() => Promise.resolve()),
}));

vi.mock('@/api/admin/updates/queries', () => ({
    isUpdateInProgress: (state: string | undefined) => state === 'queued' || state === 'running',
    useAdminUpdateStatus: () => ({ data: mocks.status }),
    useApplyAdminUpdate: () => ({ mutateAsync: mocks.mutateAsync, isPending: false }),
}));

afterEach(cleanup);

const version: AdminVersion = {
    current: '3f9c2ab',
    latest: '9e8d7c6',
    is_latest: false,
    daemon: '',
    discord: '',
    donations: '',
};

const build = { version: '3f9c2ab', commit: '3f9c2ab1d4e5f60718293a4b5c6d7e8f90a1b2c3', built_at: null };

const status = (overrides: Partial<AdminUpdateStatus> = {}): AdminUpdateStatus => ({
    mode: 'notify',
    current_version: '3f9c2ab',
    current: build,
    latest: {
        version: '9e8d7c6',
        commit: '9e8d7c6b5a4f30291817263544a3b2c1d0e9f8a7',
        built_at: null,
        tag: 'build-9e8d7c6',
        url: 'https://github.com/SoloHandCoder/MonoForge-Panel/releases/tag/build-9e8d7c6',
    },
    update_available: true,
    status: { state: 'idle', step: null, message: null, from: null, to: null, error: null, updated_at: null },
    ...overrides,
});

describe('OverviewVersionCard', () => {
    beforeEach(() => {
        mocks.status = undefined;
        mocks.mutateAsync.mockClear();
    });

    it('shows the running build as up to date', () => {
        mocks.status = status({
            update_available: false,
            latest: { ...build, tag: 'build-3f9c2ab', url: 'https://github.com/x' },
        });
        render(<OverviewVersionCard version={{ ...version, is_latest: true }} />);

        expect(screen.getByRole('alert')).toHaveTextContent('MonoForge build 3f9c2ab — up to date');
        expect(screen.queryByRole('button', { name: 'Update now' })).not.toBeInTheDocument();
    });

    it('offers an available update behind a confirmation', async () => {
        mocks.status = status();
        render(<OverviewVersionCard version={version} />);

        expect(screen.getByRole('alert')).toHaveTextContent('Update available (9e8d7c6)');
        expect(screen.getByRole('link', { name: '9e8d7c6' })).toHaveAttribute(
            'href',
            'https://github.com/SoloHandCoder/MonoForge-Panel/releases/tag/build-9e8d7c6'
        );
        for (const link of screen.getAllByRole('link')) {
            expect(link.getAttribute('href')).not.toMatch(/pterodactyl/i);
        }

        fireEvent.click(screen.getByRole('button', { name: 'Update now' }));
        expect(mocks.mutateAsync).not.toHaveBeenCalled();
        const buttons = await screen.findAllByRole('button', { name: 'Update now' });
        const confirm = buttons.at(-1);

        expect(confirm).toBeDefined();
        fireEvent.click(confirm!);
        expect(mocks.mutateAsync).toHaveBeenCalledTimes(1);
    });

    it('shows progress while an update runs and the last failure afterwards', () => {
        mocks.status = status({
            status: { ...status().status, state: 'running', step: 'migrating', to: '9e8d7c6' },
        });
        const { rerender } = render(<OverviewVersionCard version={version} />);

        expect(screen.getByRole('alert')).toHaveTextContent('Updating MonoForge to build 9e8d7c6');
        expect(screen.getByRole('alert')).toHaveTextContent('Running database migrations');

        mocks.status = status({
            status: {
                ...status().status,
                state: 'failed',
                error: 'The downloaded build does not match its sha256 checksum.',
            },
        });
        rerender(<OverviewVersionCard version={version} />);
        expect(screen.getByRole('alert')).toHaveTextContent(
            'The last update failed: The downloaded build does not match'
        );
    });

    it('falls back to the version endpoint while the status loads', () => {
        render(<OverviewVersionCard version={version} />);

        expect(screen.getByRole('alert')).toHaveTextContent('Update available (9e8d7c6)');
        expect(screen.getByRole('alert')).toHaveTextContent('You are running MonoForge build 3f9c2ab.');
    });
});
