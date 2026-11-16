/** @vitest-environment jsdom */

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type * as GameQueries from '@/api/admin/games/queries';
import GamesContainer from './GamesContainer';
import { gameDraft, gameRequest, validateGameDraft, type GameDraft } from './GameFormDialog';

const game = (
    id: number,
    name: string,
    attributes: Partial<GameQueries.AdminGame['attributes']> = {}
): GameQueries.AdminGame => ({
    object: 'game',
    attributes: {
        id,
        slug: name.toLowerCase(),
        name,
        color: '#5B8731',
        icon: 'pickaxe',
        built_in: false,
        auto_tunnels: false,
        features: [],
        egg_ids: [],
        detected_egg_ids: [],
        extra_ports: [],
        created_at: '2026-11-16T00:00:00+00:00',
        updated_at: '2026-11-16T00:00:00+00:00',
        ...attributes,
    },
});

const minecraft = game(1, 'Minecraft (Java)', {
    slug: 'minecraft',
    built_in: true,
    auto_tunnels: true,
    features: ['plugins', 'versions', 'player_count'],
    egg_ids: [1, 2],
    detected_egg_ids: [3],
    extra_ports: [{ key: 'voice', name: 'Voice chat', protocol: 'udp', port: 24454, allocation: null }],
});
const valheim = game(2, 'Valheim', { egg_ids: [4] });

const tagged = (name: string) => ({
    object: 'tag',
    attributes: {
        id: 1,
        name,
        slug: name.toLowerCase(),
        color: null,
        is_predefined: false,
        legacy_nest_id: null,
        eggs_count: 0,
        nodes_count: 0,
        created_at: '2026-11-16T00:00:00Z',
        updated_at: '2026-11-16T00:00:00Z',
    },
});

const egg = (id: number, name: string, tag?: string): GameQueries.GameEgg => ({
    object: 'egg',
    attributes: {
        id,
        uuid: `egg-${id}`,
        name,
        author: 'author@example.com',
        description: null,
        features: null,
        docker_image: 'java:21',
        docker_images: { Java: 'java:21' },
        force_outgoing_ip: false,
        config: { files: {}, startup: {}, stop: 'stop', logs: {}, file_denylist: [], extends: null },
        startup: 'java -jar server.jar',
        script: { privileged: false, install: '', entry: 'bash', container: 'alpine', extends: null },
        created_at: '2026-09-15T00:00:00Z',
        updated_at: '2026-09-15T00:00:00Z',
        relationships: { tags: { object: 'list', data: tag ? [tagged(tag)] : [] } },
    },
});

const eggs = [egg(1, 'Paper', 'Minecraft'), egg(2, 'Forge', 'Minecraft'), egg(4, 'Valheim Plus'), egg(5, 'Nginx')];
const remove = { mutateAsync: vi.fn(async () => ({})), isPending: false };
const save = { mutateAsync: vi.fn(async () => ({})), isPending: false };

vi.mock('@/api/admin/games/queries', async (importOriginal) => ({
    ...(await importOriginal<typeof GameQueries>()),
    useAdminGames: () => ({ data: [minecraft, valheim], error: null, isPending: false, refetch: vi.fn() }),
    useAdminGameEggs: () => ({ data: eggs, isPending: false }),
    useCreateAdminGame: () => save,
    useUpdateAdminGame: () => save,
    useDeleteAdminGame: () => remove,
}));

const renderGames = () =>
    render(
        <QueryClientProvider client={new QueryClient()}>
            <GamesContainer />
        </QueryClientProvider>
    );

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});

describe('GamesContainer', () => {
    it('lists the games with their features, tunnels and egg counts', () => {
        renderGames();

        const card = screen.getByRole('heading', { name: 'Minecraft (Java)' }).closest('li')!;

        expect(within(card).getByText('2 eggs · 1 more detected')).toBeTruthy();
        expect(within(card).getByText('Built in')).toBeTruthy();
        expect(within(card).getByText('Version switcher')).toBeTruthy();
        expect(within(card).getByText('Auto tunnels on')).toBeTruthy();
        expect(within(card).getByText('Voice chat (UDP 24454)')).toBeTruthy();
        // Built-in games cannot be deleted.
        expect(within(card).queryByRole('button', { name: /Delete/ })).toBeNull();
    });

    it('deletes a custom game after confirming', async () => {
        renderGames();

        await userEvent.click(screen.getByRole('button', { name: 'Delete Valheim' }));
        await userEvent.click(screen.getByRole('button', { name: 'Delete game' }));

        expect(remove.mutateAsync).toHaveBeenCalledWith({ path: { game_id: 2 }, meta: { name: 'Valheim' } });
    });

    it('edits a game: its eggs are grouped by tag and show the game they move from', async () => {
        renderGames();

        await userEvent.click(screen.getByRole('button', { name: 'Edit Valheim' }));

        const minecraftGroup = screen.getByRole('group', { name: 'Minecraft' });

        expect(within(minecraftGroup).getByText('Paper')).toBeTruthy();
        expect(within(minecraftGroup).getAllByText('in Minecraft (Java)')).toHaveLength(2);
        expect(within(screen.getByRole('group', { name: 'Other' })).getByText('Nginx')).toBeTruthy();

        await userEvent.click(within(minecraftGroup).getByText('Paper'));
        expect(screen.getByText('moves from Minecraft (Java)')).toBeTruthy();

        await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        expect(save.mutateAsync).toHaveBeenCalledWith({
            path: { game_id: 2 },
            body: expect.objectContaining({ name: 'Valheim', egg_ids: [4, 1] }),
        });
    });

    it('refuses a game without a name', async () => {
        renderGames();

        await userEvent.click(screen.getByRole('button', { name: 'New game' }));
        await userEvent.click(screen.getByRole('button', { name: 'Create game' }));

        expect(screen.getByText('Give the game a name of up to 64 characters.')).toBeTruthy();
        expect(save.mutateAsync).not.toHaveBeenCalled();
    });
});

describe('game drafts', () => {
    const draft = (change: Partial<GameDraft> = {}): GameDraft => ({ ...gameDraft(), name: 'Rust', ...change });

    it('validates colours, icons and extra ports', () => {
        expect(validateGameDraft(draft())).toEqual({});
        expect(validateGameDraft(draft({ color: 'red' }))).toHaveProperty('color');
        expect(validateGameDraft(draft({ icon: '<svg>' }))).toHaveProperty('icon');

        const port = { uid: 1, name: 'Voice', protocol: 'udp' as const, port: '', allocation: '' };

        expect(validateGameDraft(draft({ extraPorts: [port] }))['port-0']).toBe(
            'Set a port, an allocation position, or both.'
        );
        expect(validateGameDraft(draft({ extraPorts: [{ ...port, port: '70000' }] }))['port-0']).toBe(
            'The port must be between 1 and 65535.'
        );
        expect(validateGameDraft(draft({ extraPorts: [{ ...port, allocation: '1' }] }))).toEqual({});
    });

    it('turns a draft into the request, leaving an empty colour out', () => {
        const request = gameRequest(
            draft({
                autoTunnels: true,
                extraPorts: [{ uid: 1, name: ' Voice ', protocol: 'udp', port: '24454', allocation: '' }],
            })
        );

        expect(request).toEqual({
            name: 'Rust',
            icon: 'gamepad-2',
            auto_tunnels: true,
            egg_ids: [],
            extra_ports: [{ name: 'Voice', protocol: 'udp', port: 24454, allocation: null }],
        });
        expect(gameRequest(draft({ color: '#2e7d9a' })).color).toBe('#2E7D9A');
    });

    it('fills a draft from a game', () => {
        expect(gameDraft(minecraft)).toMatchObject({
            name: 'Minecraft (Java)',
            autoTunnels: true,
            eggIds: [1, 2],
            extraPorts: [{ name: 'Voice chat', protocol: 'udp', port: '24454', allocation: '' }],
        });
    });
});
