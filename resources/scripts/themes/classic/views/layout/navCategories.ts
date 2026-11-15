import type { NavItem } from '@/sdk/theme';

/**
 * The sidebar sorts an area's navigation into labelled categories.
 *
 * An item lands in a category by, in this order:
 * 1. its `group`, when that names a category by key or label (case-insensitive) — how extension
 *    screens (`nav.group` in the manifest) and new core routes (`staticData.nav.group`) opt in;
 * 2. for core items, its id (the route segment, `index` for the area root) in the area's id table;
 * 3. otherwise the area's fallback category, `more`.
 *
 * Categories keep their declared order, items keep their navigation order, and a category without
 * items is not drawn — so `billing` stays hidden until something is filed under it.
 */
export interface NavCategory {
    readonly key: string;
    readonly label: string;
}

export interface NavCategoryScheme {
    readonly categories: readonly NavCategory[];
    /** Core item id → category key. */
    readonly items: Readonly<Record<string, string>>;
    /** Where items nothing else places go; one of `categories`. */
    readonly fallback: string;
}

export interface NavSection {
    readonly category: NavCategory;
    readonly items: readonly NavItem[];
}

export const ADMIN_NAV: NavCategoryScheme = {
    categories: [
        { key: 'general', label: 'General' },
        { key: 'users', label: 'Users & servers' },
        { key: 'billing', label: 'Billing' },
        { key: 'infrastructure', label: 'Infrastructure' },
        { key: 'game', label: 'Game config' },
        { key: 'system', label: 'System' },
        { key: 'more', label: 'More' },
    ],
    items: {
        index: 'general',
        settings: 'general',
        announcements: 'general',
        activity: 'general',
        users: 'users',
        servers: 'users',
        // The token shop and its billing pages, before their routes declare a group of their own.
        shop: 'billing',
        billing: 'billing',
        store: 'billing',
        orders: 'billing',
        products: 'billing',
        nodes: 'infrastructure',
        usage: 'infrastructure',
        storage: 'infrastructure',
        locations: 'infrastructure',
        databases: 'infrastructure',
        mounts: 'infrastructure',
        eggs: 'game',
        nests: 'game',
        tags: 'game',
        api: 'system',
        themes: 'system',
        extensions: 'system',
    },
    fallback: 'more',
};

export const SERVER_NAV: NavCategoryScheme = {
    categories: [
        { key: 'overview', label: 'Overview' },
        { key: 'data', label: 'Files & data' },
        { key: 'automation', label: 'Automation' },
        { key: 'configuration', label: 'Configuration' },
        { key: 'access', label: 'Access' },
        { key: 'more', label: 'More' },
        { key: 'admin', label: 'Administration' },
    ],
    items: {
        index: 'overview',
        activity: 'overview',
        files: 'data',
        databases: 'data',
        backups: 'data',
        schedules: 'automation',
        startup: 'configuration',
        network: 'configuration',
        // playit.gg tunnels sit beside the server's allocations.
        tunnels: 'configuration',
        settings: 'configuration',
        users: 'access',
        // The root admin's "Open Admin Area" link.
        admin: 'admin',
    },
    fallback: 'more',
};

const normalise = (value: string) => value.trim().toLowerCase();

/** The category key an item belongs to under a scheme. */
export function navItemCategory(item: NavItem, scheme: NavCategoryScheme): string {
    if (item.group) {
        const group = normalise(item.group);
        const named = scheme.categories.find(
            (category) => category.key === group || normalise(category.label) === group
        );

        if (named) {
            return named.key;
        }
    }

    if (item.extensionId === undefined) {
        const key = scheme.items[item.id];

        if (key && scheme.categories.some((category) => category.key === key)) {
            return key;
        }
    }

    return scheme.fallback;
}

/** The non-empty categories of a navigation, in the scheme's order. */
export function groupNavItems(items: readonly NavItem[], scheme: NavCategoryScheme): NavSection[] {
    const byKey = new Map<string, NavItem[]>();

    for (const item of items) {
        const key = navItemCategory(item, scheme);

        byKey.set(key, [...(byKey.get(key) ?? []), item]);
    }

    return scheme.categories.flatMap((category) => {
        const members = byKey.get(category.key);

        return members?.length ? [{ category, items: members }] : [];
    });
}
