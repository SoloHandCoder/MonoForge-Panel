import type { Server } from '@/api/server/types';

/** A game whose console the panel knows: its commands and its player count lines. */
export type ConsoleGame = 'minecraft' | 'terraria';

// The same names the playit tunnel profile matches (app/Services/Tunnels/TunnelProfile.php).
const GAME_PATTERNS: readonly (readonly [ConsoleGame, RegExp])[] = [
    [
        'minecraft',
        /\b(minecraft|paper|purpur|spigot|bukkit|bungeecord|waterfall|velocity|forge|neoforge|fabric|quilt|sponge|folia|mohist|magma|bedrock|pocketmine|nukkit)\b/,
    ],
    ['terraria', /\b(terraria|tmodloader|tshock)\b/],
];

/**
 * The game a server runs: the game its egg belongs to in Admin > Games when the panel names one,
 * otherwise read from its egg's tags and features and its image; null when unknown.
 */
export function consoleGame({ attributes }: Server): ConsoleGame | null {
    if (attributes.game === 'minecraft' || attributes.game === 'terraria') {
        return attributes.game;
    }

    // A custom game the panel knows nothing about has no known console.
    if (attributes.game) {
        return null;
    }

    const features = (attributes.egg_features ?? []).map((feature) => feature.toLowerCase());

    if (features.includes('eula')) {
        return 'minecraft';
    }

    const haystack = [...(attributes.egg_tags ?? []), attributes.docker_image, ...features]
        .join(' ')
        .toLowerCase()
        .replaceAll(/[_:/.-]+/g, ' ');

    return GAME_PATTERNS.find(([, pattern]) => pattern.test(haystack))?.[0] ?? null;
}

/** The common commands of each game, with the arguments worth completing. */
export const GAME_COMMANDS = {
    minecraft: [
        'say',
        'op',
        'deop',
        'whitelist add',
        'whitelist remove',
        'whitelist list',
        'whitelist on',
        'whitelist off',
        'kick',
        'ban',
        'pardon',
        'gamemode survival',
        'gamemode creative',
        'gamemode adventure',
        'gamemode spectator',
        'time set day',
        'time set night',
        'time set noon',
        'time set midnight',
        'weather clear',
        'weather rain',
        'weather thunder',
        'tp',
        'give',
        'stop',
        'save-all',
        'list',
        'difficulty peaceful',
        'difficulty easy',
        'difficulty normal',
        'difficulty hard',
    ],
    terraria: ['say', 'kick', 'ban', 'save', 'exit', 'time', 'playing', 'password', 'motd'],
} satisfies Record<ConsoleGame, readonly string[]>;

const MAX_SUGGESTIONS = 8;

/** Every command and every whole-word prefix of one ("time", "time set"), in list order. */
function completionsOf(commands: readonly string[]): string[] {
    const seen = new Set<string>();

    for (const command of commands) {
        const words = command.split(' ');

        for (let count = 1; count <= words.length; count++) {
            seen.add(words.slice(0, count).join(' '));
        }
    }

    return [...seen];
}

const completionCache = new Map<readonly string[], string[]>();

/**
 * The commands that complete what has been typed, one word at a time: "g" offers "gamemode" and
 * "give", "gamemode " offers the game modes. Nothing is offered for an empty line or an exact match.
 */
export function suggestCommands(value: string, commands: readonly string[]): string[] {
    const typed = value.trimStart().replaceAll(/\s+/g, ' ').toLowerCase();

    if (typed === '') {
        return [];
    }

    let completions = completionCache.get(commands);

    if (!completions) {
        completions = completionsOf(commands);
        completionCache.set(commands, completions);
    }

    const words = typed.split(' ').length;

    return completions
        .filter(
            (completion) =>
                completion !== typed && completion.startsWith(typed) && completion.split(' ').length === words
        )
        .slice(0, MAX_SUGGESTIONS);
}

/** Players on the server as its console reports them; `max` is null until a line names it. */
export interface ServerPlayers {
    readonly online: number;
    readonly max: number | null;
}

// "There are 3 of a max of 20 players online", "There are 0 out of maximum 20 players online.", "There are 1/10 players online".
const MINECRAFT_LIST =
    /There (?:are|is) (\d+)(?: of a max(?:imum)? of | out of maximum |\/| of )(\d+) players? online/i;
const MINECRAFT_DONE = /\bDone \([\d.,]+m?s\)! For help/;
// After the log header: a name, then the join or leave message. A chat line starts with "<name>".
const MINECRAFT_JOIN = /(?:^|\]:?\s|INFO\s)(?:\[[^\]]+\]\s)?([A-Za-z0-9_.]{2,32}) joined the game\s*$/;
const MINECRAFT_LEAVE = /(?:^|\]:?\s|INFO\s)(?:\[[^\]]+\]\s)?([A-Za-z0-9_.]{2,32}) left the game\s*$/;
const TERRARIA_PLAYING = /^(?:: )?(No|\d+) players? connected\.?\s*$/i;
const TERRARIA_STARTED = /^(?:: )?Server started\s*$/i;
const TERRARIA_JOIN = /^(?:: )?\S.{0,40} has joined\.\s*$/;
const TERRARIA_LEAVE = /^(?:: )?\S.{0,40} has left\.\s*$/;

const step = (players: ServerPlayers | null, by: number): ServerPlayers | null =>
    players ? { ...players, online: Math.max(0, players.online + by) } : null;

function minecraftPlayers(players: ServerPlayers | null, text: string): ServerPlayers | null {
    const list = MINECRAFT_LIST.exec(text);

    if (list) {
        return { online: Number(list[1]), max: Number(list[2]) };
    }

    if (MINECRAFT_DONE.test(text)) {
        return { online: 0, max: players?.max ?? null };
    }

    if (MINECRAFT_JOIN.test(text)) {
        return step(players, 1);
    }

    return MINECRAFT_LEAVE.test(text) ? step(players, -1) : players;
}

function terrariaPlayers(players: ServerPlayers | null, text: string): ServerPlayers | null {
    const playing = TERRARIA_PLAYING.exec(text);

    if (playing) {
        return { online: playing[1].toLowerCase() === 'no' ? 0 : Number(playing[1]), max: players?.max ?? null };
    }

    if (TERRARIA_STARTED.test(text)) {
        return { online: 0, max: players?.max ?? null };
    }

    if (TERRARIA_JOIN.test(text)) {
        return step(players, 1);
    }

    return TERRARIA_LEAVE.test(text) ? step(players, -1) : players;
}

/**
 * The player count after a line of plain console text: list outputs set it, a finished start
 * makes it 0, and joins and leaves move a known count. Unrelated lines return `players` unchanged.
 */
export function trackPlayers(
    game: ConsoleGame | null,
    players: ServerPlayers | null,
    line: string
): ServerPlayers | null {
    if (game === 'minecraft') {
        return minecraftPlayers(players, line.trim());
    }

    return game === 'terraria' ? terrariaPlayers(players, line.trim()) : players;
}
