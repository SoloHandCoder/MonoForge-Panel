import type { ExtensionScreenRegistration } from '@/extensions/registry';

export type NavArea = 'account' | 'server' | 'admin';

/** A panel feature that can be switched off; its links hide while it is. */
export type NavFeature = 'assistant';

export interface RouteNavMeta {
    label: string;
    exact?: boolean;
    /** Subuser permissions; any one shows the link. */
    permission?: string | string[];
    params?: Record<string, string>;
    /** Sent with the link, e.g. the file manager's root directory. */
    hash?: string;
    group?: string;
    badge?: string;
    /** A lucide icon name. */
    icon?: string;
    /** Hides the link while the feature is off. */
    feature?: NavFeature;
    /**
     * Server links only: the game (a slug from Admin > Games) the server's egg must belong to,
     * such as `minecraft` for the plugins and versions pages.
     */
    game?: string;
    /** Gates the link and supplies its live badge. */
    screen?: ExtensionScreenRegistration;
}

export interface AreaNavEntry extends RouteNavMeta {
    /** Relative to the area root; '' is the index screen. */
    segment: string;
}

declare module '@tanstack/react-router' {
    interface StaticDataRouteOption {
        /** Omit to keep the route out of the sub-navigation. */
        nav?: RouteNavMeta;
    }
}

interface NavigationByArea {
    account: AreaNavEntry[];
    server: AreaNavEntry[];
    admin: AreaNavEntry[];
}

const areas: NavigationByArea = { account: [], server: [], admin: [] };

export function publishAreaNav(area: NavArea, entries: AreaNavEntry[]): void {
    areas[area] = entries;
}

export function getAreaNav(area: NavArea): AreaNavEntry[] {
    return areas[area];
}
