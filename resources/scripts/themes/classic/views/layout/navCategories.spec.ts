/* oxlint-disable rules/classic-theme-imports -- the spec runs under vitest */
import { describe, expect, it } from 'vitest';
import type { NavItem } from '@/sdk/theme';
import { ADMIN_NAV, groupNavItems, navItemCategory, SERVER_NAV } from './navCategories';

const item = (id: string, options: Partial<NavItem> = {}): NavItem => ({
    id,
    label: id,
    destination: { to: '/panel' } as NavItem['destination'],
    icon: null,
    group: null,
    order: 0,
    exact: false,
    ...options,
});

const adminIds = [
    'index',
    'activity',
    'announcements',
    'settings',
    'users',
    'locations',
    'nodes',
    'usage',
    'storage',
    'servers',
    'databases',
    'mounts',
    'eggs',
    'tags',
    'extensions',
    'themes',
    'api',
];

const summary = (sections: ReturnType<typeof groupNavItems>) =>
    sections.map(({ category, items }) => [category.label, items.map(({ id }) => id)]);

describe('admin categories', () => {
    it('files every core tab under its category, in navigation order', () => {
        expect(
            summary(
                groupNavItems(
                    adminIds.map((id) => item(id)),
                    ADMIN_NAV
                )
            )
        ).toEqual([
            ['General', ['index', 'activity', 'announcements', 'settings']],
            ['Users & servers', ['users', 'servers']],
            ['Infrastructure', ['locations', 'nodes', 'usage', 'storage', 'databases', 'mounts']],
            ['Game config', ['eggs', 'tags']],
            ['System', ['extensions', 'themes', 'api']],
        ]);
    });

    it('shows Billing only once something is filed under it', () => {
        expect(groupNavItems([item('users')], ADMIN_NAV).map(({ category }) => category.key)).not.toContain('billing');
        expect(summary(groupNavItems([item('users'), item('shop')], ADMIN_NAV))).toEqual([
            ['Users & servers', ['users']],
            ['Billing', ['shop']],
        ]);
    });

    it('puts unknown core tabs and extension screens under More', () => {
        expect(navItemCategory(item('ai-assistant'), ADMIN_NAV)).toBe('more');
        expect(navItemCategory(item('tickets', { extensionId: 'support', group: 'Support' }), ADMIN_NAV)).toBe('more');
        // An extension's screen id never borrows a core tab's category.
        expect(navItemCategory(item('settings', { extensionId: 'acme' }), ADMIN_NAV)).toBe('more');
    });

    it('lets an item opt into a category by key or label through its group', () => {
        expect(navItemCategory(item('tickets', { extensionId: 'support', group: 'system' }), ADMIN_NAV)).toBe('system');
        expect(navItemCategory(item('ai', { group: ' Users & Servers ' }), ADMIN_NAV)).toBe('users');
        expect(navItemCategory(item('nodes', { group: 'billing' }), ADMIN_NAV)).toBe('billing');
    });

    it('draws More last among the categories', () => {
        const keys = groupNavItems([item('ext', { extensionId: 'x' }), item('index'), item('api')], ADMIN_NAV).map(
            ({ category }) => category.key
        );

        expect(keys).toEqual(['general', 'system', 'more']);
    });
});

describe('server categories', () => {
    const serverIds = [
        'index',
        'files',
        'databases',
        'schedules',
        'users',
        'backups',
        'network',
        'startup',
        'settings',
        'activity',
    ];

    it('groups the server pages for players', () => {
        expect(
            summary(
                groupNavItems(
                    serverIds.map((id) => item(id)),
                    SERVER_NAV
                )
            )
        ).toEqual([
            ['Overview', ['index', 'activity']],
            ['Files & data', ['files', 'databases', 'backups']],
            ['Automation', ['schedules']],
            ['Configuration', ['network', 'startup', 'settings']],
            ['Access', ['users']],
        ]);
    });

    it('drops the categories a subuser has no pages in', () => {
        expect(summary(groupNavItems([item('index'), item('files')], SERVER_NAV))).toEqual([
            ['Overview', ['index']],
            ['Files & data', ['files']],
        ]);
    });

    it('keeps the admin link apart and extension screens under More', () => {
        expect(
            summary(
                groupNavItems([item('index'), item('admin'), item('tickets', { extensionId: 'support' })], SERVER_NAV)
            )
        ).toEqual([
            ['Overview', ['index']],
            ['More', ['tickets']],
            ['Administration', ['admin']],
        ]);
    });
});
