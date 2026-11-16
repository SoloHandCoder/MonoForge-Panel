import { describe, expect, it } from 'vitest';
import { shopTabFromPath, shopTabs } from '@/components/admin/shop/shopTabs';

describe('shop tabs', () => {
    it('picks the sub-tab from the path', () => {
        expect(shopTabFromPath('/panel/shop')).toBe('plans');
        expect(shopTabFromPath('/panel/shop/')).toBe('plans');
        expect(shopTabFromPath('/panel/shop/periods')).toBe('periods');
        expect(shopTabFromPath('/panel/shop/token-packs')).toBe('token-packs');
        expect(shopTabFromPath('/panel/shop/tunnels')).toBe('tunnels');
        expect(shopTabFromPath('/panel/shop/nodes')).toBe('nodes');
        expect(shopTabFromPath('/panel/shop/coupons')).toBe('coupons');
        expect(shopTabFromPath('/panel/shop/referrals')).toBe('referrals');
        expect(shopTabFromPath('/panel/shop/unknown')).toBe('plans');
    });

    it('keeps slots for token packs and tunnels after the catalog tabs', () => {
        expect(shopTabs.map((tab) => tab.key)).toEqual([
            'plans',
            'periods',
            'multipliers',
            'addons',
            'limits',
            'nodes',
            'token-packs',
            'tunnels',
            'coupons',
            'referrals',
        ]);
    });
});
