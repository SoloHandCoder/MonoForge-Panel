/** @vitest-environment jsdom */

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type * as VersionQueries from '@/api/server/minecraft/versions';
import { waitForMinecraftInstall } from '@/api/server/minecraft/versions';
import type { Server } from '@/api/server/types';
import { createTestServer } from '@/views/fixtures';
import VersionsPage, { currentSoftwareText, versionLabel } from './VersionsPage';

/** A server allowed `backups` backups. */
function serverWithBackups(backups: number): Server {
    const server = createTestServer();

    return {
        ...server,
        attributes: { ...server.attributes, feature_limits: { ...server.attributes.feature_limits, backups } },
    };
}

const state = vi.hoisted(() => ({
    permissions: new Set(['file.read', 'versions.manage', 'backup.create']),
    statuses: [] as string[],
    backups: 0,
}));
const install = vi.hoisted(() => vi.fn(async () => ({ attributes: { id: 'job' } })));
const backup = vi.hoisted(() => vi.fn(async () => ({})));

vi.mock('@/api/generated', async (importOriginal) => ({
    ...(await importOriginal<object>()),
    clientGetMinecraftVersionInstallProgress: vi.fn(async () => {
        const status = state.statuses.shift() ?? 'completed';

        return {
            data: {
                object: 'minecraft_version_install',
                attributes: {
                    id: 'job',
                    status,
                    message: status === 'failed' ? "server.jar did not match the catalog's sha256 hash." : null,
                    file_name: 'server.jar',
                    directory: '',
                    version_number: 'Paper 1.21.4',
                },
            },
        };
    }),
}));

const option = (
    id: string,
    label: string,
    change: Partial<VersionQueries.MinecraftSoftwareOption> = {}
): VersionQueries.MinecraftSoftwareOption => ({
    id: id as VersionQueries.MinecraftSoftwareOption['id'],
    label,
    description: `${label} server.`,
    has_snapshots: false,
    needs_installer: false,
    available: true,
    unavailable_reason: null,
    ...change,
});

vi.mock('@/api/server/minecraft/versions', async (importOriginal) => ({
    ...(await importOriginal<typeof VersionQueries>()),
    useMinecraftSoftware: () => ({
        isPending: false,
        error: null,
        data: {
            current: { software: 'paper', minecraft_version: '1.21.1' },
            software: [
                option('vanilla', 'Vanilla', { has_snapshots: true }),
                option('paper', 'Paper'),
                option('forge', 'Forge', {
                    needs_installer: true,
                    available: false,
                    unavailable_reason: 'Ask an administrator to switch this server to a Forge egg.',
                }),
            ],
        },
    }),
    useMinecraftVersions: (_uuid: string, software: string | null, snapshots: boolean) => ({
        isPending: false,
        error: null,
        data:
            software === 'vanilla' && snapshots
                ? [
                      { id: '25w03a', minecraft: '25w03a', type: 'snapshot' },
                      { id: '1.21.4', minecraft: '1.21.4', type: 'release' },
                  ]
                : [{ id: '1.21.4', minecraft: '1.21.4', type: 'release' }],
    }),
    useInstallMinecraftVersion: () => ({ install, isPending: false }),
}));

vi.mock('@/api/server/backups/queries', async (importOriginal) => ({
    ...(await importOriginal<object>()),
    useCreateServerBackup: () => ({ mutateAsync: backup }),
    useServerBackups: () => ({ isPending: false, data: { meta: { pagination: { total: state.backups } } } }),
}));

vi.mock('@/api/server/queries', async (importOriginal) => ({
    ...(await importOriginal<object>()),
    useCurrentServerUuid: () => 'uuid',
    useCurrentServerName: () => 'Survival',
    useCurrentServer: <T,>(select?: (server: Server) => T) => (select ? select(serverWithBackups(2)) : undefined),
}));

vi.mock('@/sdk/core', async (importOriginal) => ({
    ...(await importOriginal<object>()),
    useServerPermission: (permission: string) => state.permissions.has(permission),
}));

const renderPage = () =>
    render(
        <QueryClientProvider client={new QueryClient()}>
            <VersionsPage />
        </QueryClientProvider>
    );

beforeEach(() => {
    state.permissions = new Set(['file.read', 'versions.manage', 'backup.create']);
    state.statuses = [];
    state.backups = 0;
});

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});

describe('VersionsPage', () => {
    it('shows what the server runs and which software the egg cannot switch to', () => {
        renderPage();

        expect(screen.getByText('This server runs Paper 1.21.1, as far as the panel can tell.')).toBeTruthy();
        expect((screen.getByRole('radio', { name: 'Forge' }) as HTMLInputElement).disabled).toBe(true);
        expect(screen.getByText('Ask an administrator to switch this server to a Forge egg.')).toBeTruthy();
    });

    it('installs a version after confirming, with an optional backup first', async () => {
        renderPage();

        await userEvent.click(screen.getByRole('radio', { name: 'Paper' }));
        await userEvent.selectOptions(screen.getByLabelText('Paper version'), '1.21.4');
        await userEvent.click(screen.getByRole('button', { name: 'Install' }));

        expect(screen.getByText(/replaces its server jar/)).toBeTruthy();
        await userEvent.click(screen.getByRole('checkbox'));
        await userEvent.click(screen.getAllByRole('button', { name: 'Install' }).at(-1)!);

        await waitFor(() => expect(screen.getByText('Switched to Paper 1.21.4')).toBeTruthy());
        expect(backup).toHaveBeenCalledWith(
            expect.objectContaining({ body: expect.objectContaining({ name: 'Before Paper 1.21.4' }) })
        );
        expect(install).toHaveBeenCalledWith('paper', '1.21.4');
    });

    it('shows why a switch failed', async () => {
        state.statuses = ['failed'];
        renderPage();

        await userEvent.click(screen.getByRole('radio', { name: 'Paper' }));
        await userEvent.selectOptions(screen.getByLabelText('Paper version'), '1.21.4');
        await userEvent.click(screen.getByRole('button', { name: 'Install' }));
        await userEvent.click(screen.getAllByRole('button', { name: 'Install' }).at(-1)!);

        await waitFor(() => expect(screen.getByText('The switch failed')).toBeTruthy());
        expect(screen.getByText("server.jar did not match the catalog's sha256 hash.")).toBeTruthy();
        expect(backup).not.toHaveBeenCalled();
    });

    it('lists snapshots only when asked, for software that has them', async () => {
        renderPage();

        await userEvent.click(screen.getByRole('radio', { name: 'Vanilla' }));
        expect(screen.queryByRole('option', { name: '25w03a · snapshot' })).toBeNull();

        await userEvent.click(screen.getByRole('switch', { name: /Show snapshots/ }));
        expect(screen.getByRole('option', { name: '25w03a · snapshot' })).toBeTruthy();
    });

    it('explains the permission instead of offering Install without versions.manage', async () => {
        state.permissions = new Set(['file.read']);
        renderPage();

        await userEvent.click(screen.getByRole('radio', { name: 'Paper' }));

        expect(screen.queryByRole('button', { name: 'Install' })).toBeNull();
        expect(screen.getByText(/needs the Versions permission/)).toBeTruthy();
    });

    it('offers no backup when every slot is used', async () => {
        state.backups = 2;
        renderPage();

        await userEvent.click(screen.getByRole('radio', { name: 'Paper' }));
        await userEvent.selectOptions(screen.getByLabelText('Paper version'), '1.21.4');
        await userEvent.click(screen.getByRole('button', { name: 'Install' }));

        expect(screen.queryByRole('checkbox')).toBeNull();
        expect(screen.getByText(/no free backup slot/)).toBeTruthy();
    });
});

describe('version helpers', () => {
    it('labels loader versions with their Minecraft version', () => {
        expect(versionLabel({ id: '1.21.4', minecraft: '1.21.4', type: 'release' })).toBe('1.21.4');
        expect(versionLabel({ id: '21.4.100-beta', minecraft: '1.21.4', type: 'release' })).toBe(
            '21.4.100-beta (Minecraft 1.21.4)'
        );
        expect(currentSoftwareText({ software: null, minecraft_version: null })).toBe(
            'The panel could not tell which software this server runs.'
        );
    });

    it('polls a switch until it ends', async () => {
        state.statuses = ['queued', 'running', 'completed'];
        const sleep = vi.fn(async () => {});

        await expect(waitForMinecraftInstall('uuid', 'job', sleep)).resolves.toMatchObject({ status: 'completed' });
        expect(sleep).toHaveBeenCalledTimes(2);
    });
});
