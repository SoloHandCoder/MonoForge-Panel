/**
 * The admin Support tab's sub-tabs. Each has a route at /panel/support[/<key>] (see routeTree.ts)
 * that renders SupportAdminContainer, which shows the tab matching the path.
 */
export const supportTabs = [
    { key: 'tickets', label: 'Tickets', path: '/panel/support' },
    { key: 'canned-replies', label: 'Canned replies', path: '/panel/support/canned-replies' },
    { key: 'categories', label: 'Categories', path: '/panel/support/categories' },
] as const;

export type SupportTab = (typeof supportTabs)[number]['key'];

/** The sub-tab a /panel/support path shows; anything unknown shows the tickets. */
export const supportTabFromPath = (pathname: string): SupportTab => {
    const segment = pathname.replace(/\/+$/, '').split('/panel/support')[1]?.split('/')[1] ?? '';

    return supportTabs.find((tab) => tab.key === segment)?.key ?? 'tickets';
};
