import type { ServerStartupVariable } from '@/api/server/startup/queries';

export type WorldFieldKind = 'name' | 'size' | 'difficulty' | 'players' | 'password' | 'motd';

export interface WorldFieldDefinition {
    readonly kind: WorldFieldKind;
    readonly label: string;
    /** The startup variables the common Terraria, TShock and tModLoader eggs use for it. */
    readonly variables: readonly string[];
    /** Fixed choices, as the server reads them; free text when left out. */
    readonly choices?: readonly { readonly value: string; readonly label: string }[];
}

export const WORLD_FIELDS: readonly WorldFieldDefinition[] = [
    { kind: 'name', label: 'World name', variables: ['WORLD_NAME', 'WORLDNAME', 'WORLD'] },
    {
        kind: 'size',
        label: 'World size',
        variables: ['WORLD_SIZE', 'WORLDSIZE', 'AUTOCREATE'],
        choices: [
            { value: '1', label: 'Small' },
            { value: '2', label: 'Medium' },
            { value: '3', label: 'Large' },
        ],
    },
    {
        kind: 'difficulty',
        label: 'Difficulty',
        variables: ['WORLD_DIFFICULTY', 'DIFFICULTY'],
        choices: [
            { value: '0', label: 'Classic' },
            { value: '1', label: 'Expert' },
            { value: '2', label: 'Master' },
            { value: '3', label: 'Journey' },
        ],
    },
    { kind: 'players', label: 'Max players', variables: ['MAX_PLAYERS', 'MAXPLAYERS', 'PLAYERS'] },
    { kind: 'password', label: 'Server password', variables: ['SERVER_PASSWORD', 'PASSWORD', 'WORLD_PASSWORD'] },
    { kind: 'motd', label: 'Message of the day', variables: ['SERVER_MOTD', 'MOTD'] },
];

export interface WorldField {
    readonly definition: WorldFieldDefinition;
    readonly variable: ServerStartupVariable['attributes'];
    /** What the server uses now: its own value, or the egg's default. */
    readonly value: string;
}

/** The world settings this server's egg has, in the order above; each variable is used once. */
export function worldFields(variables: readonly ServerStartupVariable[]): WorldField[] {
    const byName = new Map(variables.map((variable) => [variable.attributes.env_variable, variable.attributes]));
    const used = new Set<string>();

    return WORLD_FIELDS.flatMap((definition) => {
        const name = definition.variables.find((candidate) => byName.has(candidate) && !used.has(candidate));
        const variable = name ? byName.get(name) : undefined;

        if (!name || !variable) {
            return [];
        }

        used.add(name);

        return [{ definition, variable, value: variable.server_value ?? variable.default_value }];
    });
}

/** Why a typed value cannot be saved, or null. The egg's own rules are checked by the panel too. */
export function worldFieldError(field: WorldField, value: string): string | null {
    const { kind, choices } = field.definition;

    if (choices && !choices.some((choice) => choice.value === value)) {
        return 'Pick one of the choices.';
    }

    if (kind === 'players' && !/^\d{1,3}$/.test(value)) {
        return 'Use a number of players, such as 8.';
    }

    if (kind === 'players' && (Number(value) < 1 || Number(value) > 255)) {
        return 'Terraria allows 1 to 255 players.';
    }

    if (kind === 'name' && value.trim() === '') {
        return 'Give the world a name.';
    }

    return value.length > 191 ? 'Keep it under 192 characters.' : null;
}
