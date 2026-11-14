/** @vitest-environment jsdom */
import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import {
    accountServersQueryOptions,
    serverResourceUsageQueryOptions,
    type ServerPowerState,
} from '@/api/account/servers/queries';
import { noticeFeedQueryOptions, publicNoticeFeedQueryOptions } from '@/api/notices/queries';
import type { SdkServerResources } from '@/sdk/server';
import { publishAreaNav } from '@/router/nav';
import { createExtensionTestHost, createTestServer, type ExtensionTestHost } from '@/sdk/testing';
import { Main } from './a11y';
import { cssUrl } from './brand';
import { registerClassicTheme } from './classic';
import { Navigation, NavigationLink } from './navigation';
import { clearThemeLayers, getViewDefinition, registerViewLayer, type ViewProps } from './registry';
import { ViewFixtureProvider, View, type ViewFixtureSelection } from './View';
import type { LayoutViewName } from '@/router/viewRoutes';
import type { AppLayoutModel } from './layouts';
import './layouts';

let host: ExtensionTestHost | undefined;

beforeAll(() => {
    // Menus measure their trigger as it resizes.
    vi.stubGlobal(
        'ResizeObserver',
        class {
            observe() {}
            disconnect() {}
        }
    );
    registerClassicTheme();
    registerViewLayer('classic', 'classic', {
        'app.search': ({ className }: { className?: string }) => (
            <button type='button' className={className}>
                Search
            </button>
        ),
    });
});

afterEach(() => {
    cleanup();
    host?.dispose();
    host = undefined;
    delete (window as Window & { SiteConfiguration?: unknown }).SiteConfiguration;
});

const contexts = {
    'layout.auth': 'auth',
    'layout.app': 'app',
    'layout.account': 'account',
    'layout.server': 'server',
    'layout.admin': 'admin',
} as const satisfies Record<LayoutViewName, string>;

/** The first page of the server list, which the shell counts the servers from. */
function seedServers(panel: ExtensionTestHost, total: number) {
    panel.queryClient.setQueryData(accountServersQueryOptions({ page: 1 }).queryKey, {
        object: 'list',
        data: [],
        meta: { pagination: { total, count: 0, per_page: 50, current_page: 1, total_pages: 1 } },
    } as never);

    // The shell's notice feeds, so it renders without a request.
    const feed = { object: 'notice_feed', attributes: { announcements: [], notices: [] } };

    panel.queryClient.setQueryData(noticeFeedQueryOptions().queryKey, feed);
    panel.queryClient.setQueryData(publicNoticeFeedQueryOptions().queryKey, feed);
}

async function renderLayout(name: LayoutViewName, selection: ViewFixtureSelection | null, path = '/', servers = 0) {
    host = createExtensionTestHost({ path });
    seedServers(host, servers);

    const layout = (
        <View name={name}>
            <p>Routed page</p>
        </View>
    );

    await act(async () => {
        render(selection ? <ViewFixtureProvider {...selection}>{layout}</ViewFixtureProvider> : layout, {
            wrapper: host!.Wrapper,
        });
    });

    return host;
}

const appModel = (overrides: Partial<AppLayoutModel> = {}): AppLayoutModel => ({
    ...(getViewDefinition('layout.app')!.fixtures!.admin!() as AppLayoutModel),
    brand: { name: 'Panel', logoUrl: null },
    ...overrides,
});

describe('classic layouts', () => {
    it.each(Object.keys(contexts) as LayoutViewName[])('%s renders the page inside main', async (name) => {
        await renderLayout(name, {});

        expect(await screen.findByRole('main')).toHaveTextContent('Routed page');
    });
});

describe('layout.app', () => {
    it('renders the navigation from its model and the routed page inside main', async () => {
        const signOut = vi.fn();

        await renderLayout('layout.app', { models: { 'layout.app': appModel({ signOut }) } });

        expect(await screen.findByRole('link', { name: 'Panel' })).toHaveAttribute('href', '/');
        expect(screen.getByRole('link', { name: 'Servers' })).toHaveAttribute('data-status', 'active');
        expect(screen.getByRole('link', { name: 'Admin area' })).toHaveAttribute('href', '/panel');
        expect(screen.getByRole('link', { name: 'Account' })).toHaveAttribute('href', '/account');
        expect(screen.getByRole('button', { name: 'Search' })).toHaveClass('rounded-md', 'px-3');
        expect(screen.getByRole('main')).toHaveTextContent('Routed page');
        expect(screen.getByRole('navigation', { name: 'Main' }).closest('aside')).not.toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Sign out' }));
        expect(signOut).toHaveBeenCalledOnce();
    });

    it('leaves the admin link out for other users', async () => {
        await renderLayout('layout.app', { fixtures: { 'layout.app': 'user' } });

        expect(await screen.findByRole('link', { name: 'Servers' })).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Admin area' })).not.toBeInTheDocument();
    });

    it('counts the servers from the first page of the server list', async () => {
        await renderLayout('layout.app', null, '/', 12);

        expect(await screen.findByTestId('server-count')).toHaveTextContent('12');
    });

    it('shows the theme logo in place of the panel name', async () => {
        await renderLayout('layout.app', { fixtures: { 'layout.app': 'logo' } });

        expect(await screen.findByRole('img', { name: 'Pterodactyl' })).toHaveAttribute(
            'src',
            '/favicons/android-chrome-192x192.png'
        );
    });

    it('offers a scheme switcher only when the theme has more than one scheme', async () => {
        await renderLayout('layout.app', { fixtures: { 'layout.app': 'user' } });
        expect(screen.queryByRole('button', { name: 'Color scheme' })).not.toBeInTheDocument();
        cleanup();
        host?.dispose();

        const setScheme = vi.fn();

        await renderLayout('layout.app', {
            models: { 'layout.app': appModel({ schemes: ['dark', 'light'], setScheme }) },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Color scheme' }));
        fireEvent.click(await screen.findByRole('menuitemcheckbox', { name: 'Light' }));

        expect(setScheme).toHaveBeenCalledWith('light');
    });

    it('switches the scheme of the live page without a reload', async () => {
        (window as Window & { SiteConfiguration?: unknown }).SiteConfiguration = {
            theme: { scheme: 'dark', schemes: ['dark', 'light'] },
        };
        document.documentElement.className = 'dark';

        await renderLayout('layout.app', null);
        fireEvent.click(await screen.findByRole('button', { name: 'Color scheme' }));
        fireEvent.click(await screen.findByRole('menuitemcheckbox', { name: 'Light' }));

        expect(document.documentElement).toHaveClass('light');
        expect(document.documentElement).not.toHaveClass('dark');
        expect(document.cookie).toContain('pterodactyl_scheme=light');
    });
});

describe('the area layout.app renders', () => {
    const server = createTestServer({
        identifier: 'a1b2c3d4',
        name: 'Survival',
        owner: false,
        permissions: ['file.read'],
    });

    function AreaProbe({ model, children }: ViewProps<'layout.app'>) {
        const { area } = model;

        return (
            <>
                <p data-testid='area'>
                    {area.kind === 'server' ? `${area.kind} ${area.name} ${area.state}` : area.kind}
                </p>
                {'nav' in area && (
                    <nav aria-label='Area'>
                        <Navigation items={area.nav.items}>
                            {(item) => (
                                <NavigationLink item={item}>
                                    {item.icon} {item.label}
                                </NavigationLink>
                            )}
                        </Navigation>
                    </nav>
                )}
                <Main>{children}</Main>
            </>
        );
    }

    async function renderArea(path: string, power: ServerPowerState = 'running') {
        registerViewLayer('aurora', 'theme', { 'layout.app': AreaProbe });
        host = createExtensionTestHost({ path, server });
        seedServers(host, 1);
        host.queryClient.setQueryData(serverResourceUsageQueryOptions(server.attributes.uuid).queryKey, {
            object: 'stats',
            attributes: { current_state: power },
        } as SdkServerResources);

        await act(async () => {
            render(
                <View name='layout.app'>
                    <p>Routed page</p>
                </View>,
                { wrapper: host!.Wrapper }
            );
        });

        return screen.findByTestId('area');
    }

    beforeAll(() => {
        publishAreaNav('account', [
            { segment: '', label: 'Account', exact: true, icon: 'user-round' },
            { segment: 'api', label: 'API Credentials', icon: 'key-round' },
        ]);
        publishAreaNav('server', [
            { segment: '', label: 'Console', exact: true, icon: 'square-terminal' },
            { segment: 'files', label: 'Files', permission: 'file.*', icon: 'folder' },
            { segment: 'databases', label: 'Databases', permission: 'database.*', icon: 'database' },
        ]);
        publishAreaNav('admin', [{ segment: 'users', label: 'Users', icon: 'users' }]);
    });

    afterEach(() => clearThemeLayers());

    it('is the dashboard outside the areas', async () => {
        expect(await renderArea('/')).toHaveTextContent('dashboard');
        expect(screen.queryByRole('navigation', { name: 'Area' })).not.toBeInTheDocument();
    });

    it('carries the navigation of the account and admin areas, with their icons', async () => {
        expect(await renderArea('/account/api')).toHaveTextContent('account');
        expect(screen.getByRole('link', { name: 'key-round API Credentials' })).toHaveAttribute(
            'data-status',
            'active'
        );
        cleanup();
        host?.dispose();

        expect(await renderArea('/panel/users')).toHaveTextContent('admin');
        expect(screen.getByRole('link', { name: 'users Users' })).toHaveAttribute('href', '/panel/users');
    });

    it('carries the server, its power state and the pages its subuser may open', async () => {
        expect(await renderArea('/server/a1b2c3d4/files', 'stopped')).toHaveTextContent('server Survival offline');
        expect(
            within(screen.getByRole('navigation', { name: 'Area' }))
                .getAllByRole('link')
                .map((link) => link.textContent)
        ).toEqual(['square-terminal Console', 'folder Files']);
        expect(screen.getByRole('main')).toHaveTextContent('Routed page');
    });
});

describe.each([
    ['layout.server', 'owner', '/server/a1b2c3d4/files'],
    ['layout.account', 'default', '/account/api'],
    ['layout.admin', 'default', '/panel/users'],
] as const)('%s', (name, fixture, path) => {
    it('leaves its navigation to the shell and places only the page', async () => {
        await renderLayout(name, { fixtures: { [name]: fixture } }, path);

        expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        expect(screen.getByRole('main')).toHaveTextContent('Routed page');
    });
});

it('reads the logo from a url() token, undoing CSS escapes', () => {
    expect(cssUrl('url("/files/logo.png")')).toBe('/files/logo.png');
    expect(cssUrl(" url('/a b.svg') ")).toBe('/a b.svg');
    expect(cssUrl('url("/files/\\3c x\\22.png")')).toBe('/files/<x".png');
    expect(cssUrl('url(/plain.png)')).toBe('/plain.png');
    expect(cssUrl('"/not-a-url.png"')).toBeNull();
    expect(cssUrl('')).toBeNull();
});
