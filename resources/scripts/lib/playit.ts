import type { Server } from '@/api/server/types';

/** The server attributes, with the playit.gg address the panel adds (until the generated types carry it). */
type ServerAttributes = Server['attributes'] & { readonly playit_address?: string | null };

/**
 * The playit.gg address of a server (such as `xyz.joinmc.link`), or null when the server has no live
 * tunnel and its primary allocation is the address.
 */
export function playitAddress(attributes: ServerAttributes): string | null {
    return attributes.playit_address || null;
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
