/**
 * The Shop tab's sub-tabs, in order. Each has a route at /panel/shop[/<key>] (see routeTree.ts)
 * that renders ShopContainer, which shows the tab matching the path.
 *
 * "Token packs & Tebex" renders TebexShopTab.tsx (Task P) and "Tunnels" renders tunnels/TunnelsTab.tsx (Task V).
 */
export const shopTabs = [
    { key: 'plans', label: 'Plans', path: '/panel/shop' },
    { key: 'periods', label: 'Billing periods', path: '/panel/shop/periods' },
    { key: 'multipliers', label: 'Price multipliers', path: '/panel/shop/multipliers' },
    { key: 'addons', label: 'Add-ons', path: '/panel/shop/addons' },
    { key: 'limits', label: 'Limits', path: '/panel/shop/limits' },
    { key: 'nodes', label: 'Nodes', path: '/panel/shop/nodes' },
    { key: 'trials', label: 'Trials', path: '/panel/shop/trials' },
    { key: 'token-packs', label: 'Token packs & Tebex', path: '/panel/shop/token-packs' },
    { key: 'tunnels', label: 'Tunnels', path: '/panel/shop/tunnels' },
    { key: 'coupons', label: 'Coupons', path: '/panel/shop/coupons' },
    { key: 'referrals', label: 'Referrals', path: '/panel/shop/referrals' },
] as const;

export type ShopTab = (typeof shopTabs)[number]['key'];

/** The sub-tab a /panel/shop path shows; anything unknown shows Plans. */
export const shopTabFromPath = (pathname: string): ShopTab => {
    const segment = pathname.replace(/\/+$/, '').split('/panel/shop')[1]?.split('/')[1] ?? '';

    return shopTabs.find((tab) => tab.key === segment)?.key ?? 'plans';
};
