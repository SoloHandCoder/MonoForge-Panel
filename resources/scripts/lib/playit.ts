import type { Server } from '@/api/server/types';

/** The server attributes, with the playit.gg address the panel adds (until the generated types carry it). */
type ServerAttributes = Server['attributes'] & {
    readonly playit_address?: string | null;
    readonly address_pending?: boolean;
    readonly address_source?: 'gateway' | 'playit' | null;
};

/**
 * The public address of a server: its playit.gg tunnel (such as `xyz.joinmc.link`) or, on a node in
 * game gateway mode, the gateway address (such as `play.example.com:25570`). Null when it has none and
 * its primary allocation is the address.
 */
export function playitAddress(attributes: ServerAttributes): string | null {
    return attributes.playit_address || null;
}

/** Whether the public address is a playit.gg tunnel (not the game gateway). */
export function isPlayitAddress(attributes: ServerAttributes): boolean {
    return playitAddress(attributes) !== null && attributes.address_source !== 'gateway';
}

/**
 * Whether the server is on a node with a playit.gg agent or the game gateway but has no public address yet. Its allocation
 * (such as `0.0.0.0:25569`) is not reachable from outside then, so players see "Address pending".
 */
export function addressPending(attributes: ServerAttributes): boolean {
    return attributes.address_pending === true && !playitAddress(attributes);
}

const prefixes = { secret_key: /^secret_key\s*=\s*/i, agent_id: /^agent_id\s*=\s*/i };

/**
 * A value pasted from playit.toml without its `name =` prefix, one pair of quotes or surrounding
 * whitespace, as the panel stores it: `secret_key = "ab12"` becomes `ab12`.
 */
export function cleanPlayitValue(value: string, name: 'secret_key' | 'agent_id'): string {
    const unquote = (text: string): string => {
        const first = text.charAt(0);

        return text.length >= 2 && (first === '"' || first === "'") && text.endsWith(first)
            ? text.slice(1, -1).trim()
            : text;
    };

    const trimmed = unquote(value.trim());
    const prefix = prefixes[name];

    return prefix.test(trimmed) ? unquote(trimmed.replace(prefix, '').trim()) : trimmed;
}
