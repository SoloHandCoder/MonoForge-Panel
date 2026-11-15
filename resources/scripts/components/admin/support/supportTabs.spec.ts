import { describe, expect, it } from 'vitest';
import { ADMIN_NAV, navItemCategory } from '@/themes/classic/views/layout/navCategories';
import type { NavItem } from '@/sdk/theme';
import { navItemDestination } from '@/views/navigation';
import { supportTabFromPath } from './supportTabs';

describe('support tabs', () => {
    it('picks the tab from the path and falls back to the tickets', () => {
        expect(supportTabFromPath('/panel/support')).toBe('tickets');
        expect(supportTabFromPath('/panel/support/')).toBe('tickets');
        expect(supportTabFromPath('/panel/support/canned-replies')).toBe('canned-replies');
        expect(supportTabFromPath('/panel/support/categories')).toBe('categories');
        expect(supportTabFromPath('/panel/support/12')).toBe('tickets');
    });

    it('files the Support link under users and servers in the admin sidebar', () => {
        const item: NavItem = {
            id: 'support',
            label: 'Support',
            destination: navItemDestination('/panel/support', {}),
            icon: 'life-buoy',
            group: null,
            order: 3,
            exact: false,
        };

        expect(navItemCategory(item, ADMIN_NAV)).toBe('users');
    });
});
