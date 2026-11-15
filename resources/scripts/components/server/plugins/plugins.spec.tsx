/** @vitest-environment jsdom */

import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type * as PluginQueries from '@/api/server/plugins/queries';
import {
    modpackMismatch,
    type InstalledPlugin,
    type ModpackProgress,
    type PluginEnvironment,
    type PluginVersion,
} from '@/api/server/plugins/queries';
import InstalledTab from './InstalledTab';
import ModpackProgressPanel, { modpackPercent } from './ModpackProgressPanel';

const remove = vi.hoisted(() => vi.fn(() => Promise.resolve()));
const update = vi.hoisted(() => vi.fn(() => Promise.resolve()));
const installed = vi.hoisted(() => ({ data: [] as InstalledPlugin[] }));

vi.mock('@/api/server/plugins/queries', async (importOriginal) => ({
    ...(await importOriginal<typeof PluginQueries>()),
    useInstalledPlugins: () => ({
        isPending: false,
        isError: false,
        data: { object: 'list', data: installed.data, meta: { directory: 'plugins' } },
    }),
    useUpdateInstalledPlugin: () => ({ update, isPending: false }),
    useRemoveInstalledPlugin: () => ({ remove, isPending: false }),
}));

const platform = (loader: string | null, gameVersion: string | null): PluginEnvironment['platform'] => ({
    loader,
    game_version: gameVersion,
    type: loader === 'paper' ? 'plugin' : 'mod',
    directory: loader === 'paper' ? 'plugins' : 'mods',
    detected_from: ['files'],
});

const environment: PluginEnvironment = {
    platform: platform('paper', '1.21.4'),
    detected: platform('paper', '1.21.4'),
    sources: { plugin: ['modrinth', 'hangar'], mod: ['modrinth'], modpack: ['modrinth'] },
    loaders: ['paper', 'fabric'],
    curseforge_enabled: false,
};

const version = (gameVersions: string[], loaders: string[]): PluginVersion => ({
    id: 'v1',
    project_id: 'p1',
    name: 'Pack 1.0',
    version_number: '1.0',
    game_versions: gameVersions,
    loaders,
    channel: 'release',
    published_at: null,
    file: { filename: 'pack.mrpack', size: 10, hashes: { sha1: 'abc' } },
    installable: true,
});

const progress = (changes: Partial<ModpackProgress>): ModpackProgress => ({
    status: 'running',
    step: 'files',
    message: null,
    project_title: 'Fabulously Optimized',
    version_name: '6.2.0',
    files_done: 0,
    files_total: 0,
    backup: null,
    started_at: '2026-11-15T12:00:00+00:00',
    finished_at: null,
    ...changes,
});

afterEach(cleanup);
beforeEach(() => {
    vi.clearAllMocks();
    installed.data = [];
});

describe('modpack checks', () => {
    it('warns when the Minecraft version or loader does not match the server', () => {
        const fabric = platform('fabric', '1.21.4');

        expect(modpackMismatch(version(['1.21.4'], ['fabric']), fabric)).toBeNull();
        expect(modpackMismatch(version(['1.20.1'], ['fabric']), fabric)).toBe(
            'This modpack may not run here: it is for Minecraft 1.20.1, the server runs 1.21.4.'
        );
        expect(modpackMismatch(version(['1.21.4'], ['forge', 'neoforge']), fabric)).toBe(
            'This modpack may not run here: it needs Forge or NeoForge, the server runs Fabric.'
        );
        expect(modpackMismatch(version(['1.20.1'], ['quilt']), platform(null, null))).toBeNull();
    });

    it('moves the progress bar with the downloaded files', () => {
        expect(modpackPercent(progress({ step: 'backup' }))).toBe(5);
        expect(modpackPercent(progress({ files_done: 20, files_total: 40 }))).toBe(55);
        expect(modpackPercent(progress({ status: 'completed', step: 'done' }))).toBe(100);
    });

    it('shows where the install is, and why it failed', () => {
        const { rerender } = render(<ModpackProgressPanel progress={progress({ files_done: 3, files_total: 40 })} />);

        expect(screen.getByRole('status').textContent).toContain('Downloading mods (3 of 40)');
        expect(screen.getByRole('progressbar').getAttribute('aria-valuenow')).toBe('30');

        rerender(
            <ModpackProgressPanel progress={progress({ status: 'failed', message: 'mods/a.jar did not match.' })} />
        );
        expect(screen.getByRole('alert').textContent).toContain('mods/a.jar did not match.');

        rerender(<ModpackProgressPanel progress={progress({ status: 'idle', step: 'idle' })} />);
        expect(screen.queryByRole('alert')).toBeNull();
    });
});

describe('installed plugins', () => {
    const jar = (changes: Partial<InstalledPlugin>): InstalledPlugin => ({
        file_name: 'LuckPerms-1.jar',
        size: 2048,
        modified_at: null,
        sha1: 'abc',
        project: {
            source: 'modrinth',
            id: 'p1',
            title: 'LuckPerms',
            slug: 'luckperms',
            icon_url: null,
            url: 'https://modrinth.com/plugin/luckperms',
        },
        version: { id: 'v1', name: 'LuckPerms 1.0', version_number: '1.0' },
        update: { id: 'v2', name: 'LuckPerms 2.0', version_number: '2.0' },
        ...changes,
    });

    it('updates and removes jars for users who may manage plugins', async () => {
        const user = userEvent.setup();

        installed.data = [jar({}), jar({ file_name: 'Mystery.jar', project: null, version: null, update: null })];

        render(<InstalledTab uuid='uuid' environment={environment} overrides={{}} canManage />);

        expect(screen.getByText('2 jars in plugins/, 1 with an update.')).toBeTruthy();
        expect(screen.getByText('Update: 2.0')).toBeTruthy();
        expect(screen.getByText(/Not found on Modrinth/)).toBeTruthy();

        await user.click(screen.getByRole('button', { name: 'Update LuckPerms-1.jar' }));
        expect(update).toHaveBeenCalledWith('LuckPerms-1.jar');

        await user.click(screen.getByRole('button', { name: 'Remove Mystery.jar' }));
        await user.click(await screen.findByRole('button', { name: 'Remove' }));
        expect(remove).toHaveBeenCalledWith('Mystery.jar');
    });

    it('only lists jars for users who may not manage plugins', () => {
        installed.data = [jar({})];

        render(<InstalledTab uuid='uuid' environment={environment} overrides={{}} canManage={false} />);

        expect(screen.getByText('LuckPerms')).toBeTruthy();
        expect(screen.queryByRole('button', { name: /Update|Remove/ })).toBeNull();
    });
});
