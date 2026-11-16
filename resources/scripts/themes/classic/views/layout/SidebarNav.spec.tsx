/** @vitest-environment jsdom */
/* oxlint-disable rules/classic-theme-imports -- the spec publishes the account navigation and reads the layout fixtures */
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { publishAreaNav } from '@/router/nav';
import { createExtensionTestHost, renderView, type ExtensionTestHost, type RenderedView } from '@/sdk/testing';
import { getViewDefinition } from '@/views/registry';
import { View, ViewFixtureProvider } from '@/views/View';
import type { AppLayoutModel } from '@/views/layouts';
import type { SlotName } from '@/extensions/registry';
import SidebarNav from './SidebarNav';

let host: ExtensionTestHost | undefined;
let rendered: RenderedView | undefined;

beforeAll(() => {
    publishAreaNav('account', [
        { segment: '', label: 'Account', exact: true, icon: 'user-round' },
        { segment: 'api', label: 'API Credentials', icon: 'key-round' },
        { segment: 'ssh', label: 'SSH Keys', icon: 'terminal' },
        { segment: 'activity', label: 'Activity', icon: 'history' },
        { segment: 'wallet', label: 'Wallet', icon: 'wallet' },
    ]);
});

afterEach(() => {
    rendered?.unmount();
    rendered = undefined;
    cleanup();
    host?.dispose();
    host = undefined;
});

const fixture = (name: string): AppLayoutModel => getViewDefinition('layout.app')!.fixtures![name]!() as AppLayoutModel;

async function renderNav(model: AppLayoutModel, path = '/') {
    host = createExtensionTestHost({ path });

    await act(async () => {
        render(<SidebarNav model={model} />, { wrapper: host!.Wrapper });
    });

    return host;
}

describe('SidebarNav on the dashboard', () => {
    it('lists the servers, the account pages and the developer keys', async () => {
        await renderNav(fixture('user'));

        expect(screen.getByRole('link', { name: 'Servers' })).toHaveAttribute('href', '/');
        expect(screen.getByRole('link', { name: 'Account' })).toHaveAttribute('href', '/account');
        expect(screen.getByRole('link', { name: 'Activity' })).toHaveAttribute('href', '/account/activity');
        expect(screen.getByRole('link', { name: 'Wallet' })).toHaveAttribute('href', '/account/wallet');
        expect(screen.getByRole('link', { name: 'API keys' })).toHaveAttribute('href', '/account/api');
        expect(screen.getByRole('link', { name: 'SSH keys' })).toHaveAttribute('href', '/account/ssh');
        expect(screen.getByText('Developer')).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Admin area' })).not.toBeInTheDocument();
    });

    it('offers the admin area only when the model links to it', async () => {
        await renderNav(fixture('admin'));

        expect(screen.getByRole('link', { name: 'Admin area' })).toHaveAttribute('href', '/panel');
    });

    it('marks the active item', async () => {
        await renderNav(fixture('user'));

        expect(screen.getByRole('link', { name: 'Servers' })).toHaveAttribute('data-status', 'active');
        expect(screen.getByRole('link', { name: 'Account' })).not.toHaveAttribute('data-status', 'active');
    });

    it('links to the Discord invite in a new tab', async () => {
        await renderNav(fixture('user'));

        expect(screen.getByRole('link', { name: 'Join our Discord' })).toHaveAttribute(
            'href',
            'https://discord.gg/B3fd45pgvC'
        );
        expect(screen.getByRole('link', { name: 'Join our Discord' })).toHaveAttribute('target', '_blank');
        expect(screen.getByRole('link', { name: 'Join our Discord' })).toHaveAttribute('rel', 'noopener noreferrer');
    });

    it('links to the help center through the router', async () => {
        await renderNav(fixture('user'), '/help/the-console');

        const help = screen.getByRole('link', { name: 'Help' });

        expect(help).toHaveAttribute('href', '/help');
        // A router link marks itself active on the help center; a plain anchor would not.
        expect(help).toHaveAttribute('data-status', 'active');
        expect(help).not.toHaveAttribute('target');
    });

    it('offers the Store only while the shop is open', async () => {
        await renderNav(fixture('user'));
        expect(screen.getByRole('link', { name: 'Store' })).toHaveAttribute('href', '/store');
        cleanup();
        host?.dispose();

        await renderNav({ ...fixture('user'), storeEnabled: false });
        expect(screen.queryByRole('link', { name: 'Store' })).not.toBeInTheDocument();
    });

    it('shows the server count once it has loaded', async () => {
        await renderNav(fixture('user'));
        expect(screen.queryByTestId('server-count')).not.toBeInTheDocument();
        cleanup();
        host?.dispose();

        await renderNav({ ...fixture('user'), serverCount: 7 });
        expect(screen.getByTestId('server-count')).toHaveTextContent('7');
    });

    it('tells screen readers how many servers there are', async () => {
        await renderNav({ ...fixture('user'), serverCount: 7 });

        expect(screen.getByTestId('server-count')).toHaveAttribute('aria-hidden');
        expect(screen.getByRole('link', { name: /7 servers/ })).toHaveAttribute('href', '/');
    });

    it('shows the brand, the search and the user', async () => {
        await renderNav({ ...fixture('admin'), brand: { name: 'MonoForge', logoUrl: null } });

        const home = screen.getByRole('link', { name: 'MonoForge' });

        expect(home).toHaveAttribute('href', '/');
        expect(home.querySelector('svg')).not.toBeNull();
        expect(screen.getByRole('button', { name: 'Search' })).toBeInTheDocument();
        expect(screen.getByText('alex')).toBeInTheDocument();
        expect(screen.getByText('Administrator')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Sign out' })).toBeInTheDocument();
    });

    it('replaces the mark and name with the brand logo', async () => {
        await renderNav(fixture('logo'));

        expect(screen.getByRole('img', { name: 'Pterodactyl' })).toHaveAttribute(
            'src',
            '/favicons/android-chrome-192x192.png'
        );
        expect(screen.getByRole('link', { name: 'Pterodactyl' }).querySelector('svg')).toBeNull();
    });
});

describe('SidebarNav in an area', () => {
    it('draws the account area with the developer keys', async () => {
        await renderNav(fixture('accountArea'), '/account/api');

        expect(screen.getByRole('link', { name: 'API keys' })).toHaveAttribute('data-status', 'active');
        expect(screen.getByRole('link', { name: 'SSH keys' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Servers' })).toBeInTheDocument();
    });

    it('draws a server with its state and its pages', async () => {
        await renderNav(fixture('serverArea'), '/server/a1b2c3d4/files');

        expect(screen.getByRole('link', { name: 'All servers' })).toHaveAttribute('href', '/');
        const nav = screen.getByRole('navigation', { name: 'Server' });

        expect(nav).toHaveTextContent('Survival');
        expect(nav).toHaveTextContent('Online');
        expect(nav).not.toHaveTextContent('running');
        expect(within(nav).getByRole('link', { name: 'Console' })).toBeInTheDocument();
        expect(within(nav).getByRole('link', { name: 'Files' })).toHaveAttribute('data-status', 'active');
        expect(screen.queryByRole('link', { name: 'API keys' })).not.toBeInTheDocument();
    });

    it.each([
        ['running', 'Online'],
        ['starting', 'Starting'],
        ['stopping', 'Stopping'],
        ['offline', 'Offline'],
        [null, 'Unknown'],
    ] as const)('labels the %s state as %s, like the server cards', async (state, label) => {
        const model = fixture('serverArea');

        await renderNav(
            model.area.kind === 'server' ? { ...model, area: { ...model.area, state } } : model,
            '/server/a1b2c3d4'
        );

        const badge = within(screen.getByRole('navigation', { name: 'Server' })).getByText(label);

        expect(badge.textContent).toBe(label);
    });

    it('adds the server admin link for root admins', async () => {
        const model = fixture('serverArea');
        const area = model.area.kind === 'server' ? model.area : null;
        const adminLink = {
            id: 'admin',
            label: 'Open Admin Area',
            destination: { to: '/panel/servers/$id', params: { id: '1' } },
            icon: 'external-link',
            group: null,
            order: 99,
            exact: false,
        } as const;

        await renderNav({ ...model, area: { ...area!, adminLink } }, '/server/a1b2c3d4');

        expect(screen.getByRole('link', { name: 'Open Admin Area' })).toHaveAttribute('href', '/panel/servers/1');
    });

    it('draws the admin area with a way back to the panel', async () => {
        await renderNav(fixture('adminArea'), '/panel/users');

        expect(screen.getByRole('link', { name: 'Back to panel' })).toHaveAttribute('href', '/');
        const nav = screen.getByRole('navigation', { name: 'Administration' });

        expect(within(nav).getByRole('link', { name: 'Users' })).toHaveAttribute('data-status', 'active');
        expect(within(nav).getByRole('link', { name: 'Themes' })).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'API keys' })).not.toBeInTheDocument();
    });
});

describe('SidebarNav categories', () => {
    beforeEach(() => window.localStorage.clear());

    const categories = (name: string) =>
        within(screen.getByRole('navigation', { name }))
            .getAllByRole('group')
            .map((group) => [
                within(group).getByRole('button').textContent,
                within(group)
                    .queryAllByRole('link')
                    .map((link) => link.textContent),
            ]);

    const serverModel = (allowed?: readonly string[]): AppLayoutModel => {
        const model = fixture('serverArea');

        if (model.area.kind !== 'server') {
            return model;
        }

        const items = model.area.nav.items.filter((item) => !allowed || allowed.includes(item.id));

        return { ...model, area: { ...model.area, nav: { items } } };
    };

    it('sorts the admin tabs into labelled categories', async () => {
        await renderNav(fixture('adminArea'), '/panel/users');

        expect(categories('Administration')).toEqual([
            ['General', ['Overview', 'Activity', 'Announcements', 'Settings']],
            ['Users & servers', ['Users', 'Servers']],
            ['Billing', ['Shop']],
            ['Infrastructure', ['Locations', 'Nodes', 'Live usage', 'Storage', 'Database Hosts', 'Mounts']],
            ['Game config', ['Eggs', 'Tags']],
            ['System', ['Extensions', 'Themes', 'API Keys']],
        ]);
    });

    it('files extension screens it does not know under More', async () => {
        const model = fixture('adminArea');
        const area = model.area.kind === 'admin' ? model.area : null;
        // The fixture already has the Shop, filed under Billing.
        const assistant = { ...area!.nav.items[4]!, id: 'assistant', label: 'AI Assistant', order: 51 };

        await renderNav({ ...model, area: { ...area!, nav: { items: [...area!.nav.items, assistant] } } }, '/panel');

        const labels = categories('Administration');

        expect(labels).toContainEqual(['Billing', ['Shop']]);
        expect(labels.at(-1)).toEqual(['More', ['AI Assistant']]);
    });

    it('groups the server pages for players', async () => {
        await renderNav(serverModel(), '/server/a1b2c3d4');

        expect(categories('Server')).toEqual([
            ['Overview', ['Console', 'Activity']],
            ['Files & data', ['Files', 'Databases', 'Backups']],
            ['Automation', ['Schedules']],
            ['Configuration', ['Network', 'Startup', 'Settings']],
            ['Access', ['Users']],
        ]);
    });

    it('hides the categories a subuser has no permission for', async () => {
        await renderNav(serverModel(['', 'index', 'files', 'backups']), '/server/a1b2c3d4');

        expect(categories('Server')).toEqual([
            ['Overview', ['Console']],
            ['Files & data', ['Files', 'Backups']],
        ]);
    });

    it('folds a category from its label and remembers it', async () => {
        await renderNav(fixture('adminArea'), '/panel/users');

        const system = screen.getByRole('button', { name: 'System' });

        expect(system).toHaveAttribute('aria-expanded', 'true');
        fireEvent.click(system);
        expect(system).toHaveAttribute('aria-expanded', 'false');
        expect(screen.queryByRole('link', { name: 'Themes' })).not.toBeInTheDocument();
        expect(window.localStorage.getItem('monoforge.sidebar.collapsed.admin')).toBe('system');

        cleanup();
        host?.dispose();
        await renderNav(fixture('adminArea'), '/panel/users');

        expect(screen.getByRole('button', { name: 'System' })).toHaveAttribute('aria-expanded', 'false');
        fireEvent.click(screen.getByRole('button', { name: 'System' }));
        expect(screen.getByRole('link', { name: 'Themes' })).toBeInTheDocument();
        expect(window.localStorage.getItem('monoforge.sidebar.collapsed.admin')).toBe('');
    });

    it('keeps the category of the current page open', async () => {
        window.localStorage.setItem('monoforge.sidebar.collapsed.admin', 'users');
        await renderNav(fixture('adminArea'), '/panel/users');

        const people = screen.getByRole('button', { name: 'Users & servers' });

        expect(people).toHaveAttribute('aria-expanded', 'true');
        fireEvent.click(people);
        expect(screen.getByRole('link', { name: 'Users' })).toHaveAttribute('data-status', 'active');
    });

    it('still draws every category when storage is unavailable', async () => {
        const read = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('blocked');
        });
        const write = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('blocked');
        });

        try {
            await renderNav(fixture('adminArea'), '/panel/users');
            fireEvent.click(screen.getByRole('button', { name: 'System' }));
            expect(screen.getByRole('button', { name: 'System' })).toHaveAttribute('aria-expanded', 'false');
            expect(screen.getByRole('button', { name: 'General' })).toHaveAttribute('aria-expanded', 'true');
        } finally {
            read.mockRestore();
            write.mockRestore();
        }
    });
});

describe('SidebarNav slots', () => {
    const probe = (name: SlotName) => () => <span data-testid='probe'>{name}</span>;

    const slots = (names: readonly SlotName[]) => Object.fromEntries(names.map((name) => [name, probe(name)]));

    const cases: readonly (readonly [string, readonly SlotName[]])[] = [
        ['user', ['nav.items.before', 'nav.items.after']],
        [
            'accountArea',
            ['nav.items.before', 'nav.items.after', 'account.navigation.before', 'account.navigation.after'],
        ],
        ['serverArea', ['nav.items.before', 'nav.items.after', 'server.navigation.before', 'server.navigation.after']],
        ['adminArea', ['nav.items.before', 'nav.items.after', 'panel.navigation.before', 'panel.navigation.after']],
    ];

    it.each(cases)('renders each slot of %s exactly once', (name, names) => {
        rendered = renderView('layout.app', { fixture: name, slots: slots(names) });

        expect(screen.getAllByTestId('probe').map((element) => element.textContent)).toEqual(
            expect.arrayContaining([...names])
        );
        expect(screen.getAllByTestId('probe')).toHaveLength(names.length);
    });
});

describe('the sidebar drawer', () => {
    it('opens from the menu button and closes after navigating', async () => {
        host = createExtensionTestHost({ path: '/' });

        await act(async () => {
            render(
                <ViewFixtureProvider fixtures={{ 'layout.app': 'user' }}>
                    <View name='layout.app'>
                        <p>Routed page</p>
                    </View>
                </ViewFixtureProvider>,
                { wrapper: host!.Wrapper }
            );
        });

        const menu = screen.getByRole('button', { name: 'Menu' });

        expect(menu).toHaveAttribute('aria-expanded', 'false');
        fireEvent.click(menu);
        expect(menu).toHaveAttribute('aria-expanded', 'true');
        fireEvent.click(screen.getByRole('link', { name: 'Account' }));

        await waitFor(() => expect(host!.location().pathname).toBe('/account'));
        await waitFor(() => expect(menu).toHaveAttribute('aria-expanded', 'false'));
        expect(screen.getByRole('main')).toHaveTextContent('Routed page');
    });
});
