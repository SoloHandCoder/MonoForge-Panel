import { describe, expect, it } from 'vitest';
import { createTestServer } from '@/views/fixtures';
import { consoleGame, GAME_COMMANDS, suggestCommands, trackPlayers } from './consoleGame';
import { consoleLogFileName } from './useServerTerminal';

describe('consoleGame', () => {
    it('knows Minecraft by the EULA feature or the egg tags, and Terraria by its tags', () => {
        expect(consoleGame(createTestServer({ eggFeatures: ['eula'] }))).toBe('minecraft');
        expect(consoleGame(createTestServer({ eggTags: ['paper'] }))).toBe('minecraft');
        expect(consoleGame(createTestServer({ eggTags: ['tshock'] }))).toBe('terraria');
        expect(consoleGame(createTestServer({ eggTags: ['valheim'] }))).toBeNull();
    });
});

describe('suggestCommands', () => {
    const minecraft = GAME_COMMANDS.minecraft;

    it('completes one word at a time', () => {
        expect(suggestCommands('g', minecraft)).toEqual(['gamemode', 'give']);
        expect(suggestCommands('gamemode ', minecraft)).toEqual([
            'gamemode survival',
            'gamemode creative',
            'gamemode adventure',
            'gamemode spectator',
        ]);
        expect(suggestCommands('TIME  s', minecraft)).toEqual(['time set']);
    });

    it('offers nothing for an empty line, an exact match or an unknown game', () => {
        expect(suggestCommands('', minecraft)).toEqual([]);
        expect(suggestCommands('list', minecraft)).toEqual([]);
        expect(suggestCommands('say hello', minecraft)).toEqual([]);
        expect(suggestCommands('s', [])).toEqual([]);
    });

    it('knows the Terraria commands', () => {
        expect(suggestCommands('p', GAME_COMMANDS.terraria)).toEqual(['playing', 'password']);
    });
});

describe('trackPlayers', () => {
    it('reads the Minecraft list output of vanilla, Paper and Bedrock', () => {
        expect(
            trackPlayers('minecraft', null, '[12:00:00 INFO]: There are 3 of a max of 20 players online: a, b, c')
        ).toEqual({ online: 3, max: 20 });
        expect(trackPlayers('minecraft', null, '[INFO] There are 0 out of maximum 10 players online.')).toEqual({
            online: 0,
            max: 10,
        });
        expect(trackPlayers('minecraft', null, 'There are 1/10 players online:')).toEqual({ online: 1, max: 10 });
    });

    it('moves a known count with joins and leaves, but not with chat', () => {
        const players = { online: 1, max: 20 };

        expect(trackPlayers('minecraft', players, '[12:00:00 INFO]: Steve joined the game')).toEqual({
            online: 2,
            max: 20,
        });
        expect(trackPlayers('minecraft', players, '[12:00:00 INFO]: Steve left the game')).toEqual({
            online: 0,
            max: 20,
        });
        expect(trackPlayers('minecraft', players, '[12:00:00 INFO]: <Steve> Alex joined the game')).toBe(players);
        expect(trackPlayers('minecraft', null, '[12:00:00 INFO]: Steve joined the game')).toBeNull();
        expect(trackPlayers('minecraft', null, '[12:00:09 INFO]: Done (7.412s)! For help, type "help"')).toEqual({
            online: 0,
            max: null,
        });
    });

    it('reads Terraria and ignores games it does not know', () => {
        expect(trackPlayers('terraria', null, ': 2 players connected.')).toEqual({ online: 2, max: null });
        expect(trackPlayers('terraria', null, 'No players connected.')).toEqual({ online: 0, max: null });
        expect(trackPlayers('terraria', { online: 2, max: null }, 'Bob has left.')).toEqual({ online: 1, max: null });
        expect(trackPlayers(null, null, 'There are 3 of a max of 20 players online')).toBeNull();
    });
});

describe('consoleLogFileName', () => {
    it('names the download after the server and the time, safely', () => {
        expect(consoleLogFileName('My ../Server <1>', new Date(2026, 10, 15, 9, 5))).toBe(
            'my-server-1-console-2026-11-15-0905.log'
        );
        expect(consoleLogFileName('***', new Date(2026, 0, 2, 3, 4))).toBe('server-console-2026-01-02-0304.log');
    });
});
