/** @vitest-environment jsdom */
import { act, cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeAll, describe, expect, it } from 'vitest';
import { publishAreaNav } from '@/router/nav';
import { createExtensionTestHost, createTestServer, type ExtensionTestHost } from '@/sdk/testing';
import { matchesGame, useNavigation } from './navigation';

let host: ExtensionTestHost | undefined;

beforeAll(() => {
    publishAreaNav('server', [
        { segment: '', label: 'Console', exact: true },
        { segment: 'plugins', label: 'Plugins & mods', permission: 'file.read', game: 'minecraft' },
        { segment: 'versions', label: 'Versions', permission: 'file.read', game: 'minecraft' },
        { segment: 'world', label: 'World', permission: 'startup.read', game: 'terraria' },
    ]);
});

afterEach(() => {
    cleanup();
    host?.dispose();
    host = undefined;
});

function Labels() {
    const { items } = useNavigation('server');

    return (
        <ul>
            {items.map((item) => (
                <li key={item.id}>{item.label}</li>
            ))}
        </ul>
    );
}

async function labelsFor(game: string | null): Promise<string[]> {
    host = createExtensionTestHost({ server: createTestServer({ identifier: 'abc', game }), path: '/server/abc' });

    await act(async () => {
        render(<Labels />, { wrapper: host!.Wrapper });
    });

    return screen.getAllByRole('listitem').map((item) => item.textContent ?? '');
}

describe('game-specific server pages', () => {
    it('lists the Minecraft pages only for a server whose egg belongs to Minecraft', async () => {
        expect(await labelsFor('minecraft')).toEqual(['Console', 'Plugins & mods', 'Versions']);
    });

    it('lists the Terraria world settings only for Terraria', async () => {
        expect(await labelsFor('terraria')).toEqual(['Console', 'World']);
    });

    it('lists no game page for a server no game claims', async () => {
        expect(await labelsFor(null)).toEqual(['Console']);
    });

    it('matches a link without a game on every server', () => {
        expect(matchesGame(undefined, undefined)).toBe(true);
        expect(matchesGame('minecraft', undefined)).toBe(false);
        expect(matchesGame('minecraft', createTestServer({ game: 'minecraft' }))).toBe(true);
    });
});
