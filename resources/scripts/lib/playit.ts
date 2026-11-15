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
