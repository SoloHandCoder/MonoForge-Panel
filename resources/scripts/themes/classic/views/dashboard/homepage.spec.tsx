/* oxlint-disable rules/classic-theme-imports -- the spec drives the classic views through the app's test host */
/** @vitest-environment jsdom */
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import type * as ServerQueries from '@/api/account/servers/queries';
import { createExtensionTestHost, type ExtensionTestHost } from '@/sdk/testing';
import { createTestServer, createTestServerCard } from '@/views/fixtures';
import type { ServerListItem, ServerListModel } from '@/views/dashboard';
import { statMetric } from '@/views/server/state';
import { registerClassicTheme } from '@/views/classic';
import ServerList from './ServerList';
import ServerQuickActions from './ServerQuickActions';
import Welcome, { welcomeStats } from './Welcome';

vi.mock('@/api/account/servers/queries', async (importOriginal) => ({
    ...(await importOriginal<typeof ServerQueries>()),
    useServerResourceUsage: vi.fn(() => ({ data: undefined })),
}));

beforeAll(() => registerClassicTheme());

const GiB = 1024 ** 3;

let host: ExtensionTestHost | undefined;

afterEach(() => {
    cleanup();
    host?.dispose();
    host = undefined;
    vi.unstubAllGlobals();
});

const item = (options: Partial<ServerListItem> = {}): ServerListItem => ({
    ...createTestServerCard(),
    server: createTestServer(),
    ...options,
});

const ready = (power: 'running' | 'offline', used: number, limit: number): ServerListItem['state'] => ({
    kind: 'ready',
    power,
    cpu: statMetric(1, 100),
    memory: statMetric(used, limit),
    disk: statMetric(0, 0),
});

function listModel(servers: ServerListItem[], options: Partial<ServerListModel> = {}): ServerListModel {
    return {
        servers,
        totals: {
            servers: servers.length,
            runningOnPage: servers.filter((s) => s.state.kind === 'ready' && s.state.power === 'running').length,
            offlineOnPage: 0,
        },
        loading: false,
        pagination: null,
        scope: 'mine',
        canViewOthers: false,
        canCreateServers: false,
        username: 'solohandcoder',
        actions: { setPage: vi.fn(), setScope: vi.fn() },
        ...options,
    };
}

const threeServers = () => [
    item({ uuid: 'a', state: ready('running', GiB, 4 * GiB) }),
    item({ uuid: 'b', state: ready('running', 2 * GiB, 8 * GiB) }),
    item({ uuid: 'c', state: ready('offline', 0, 0) }),
];

async function renderInApp(children: ReactNode) {
    host = createExtensionTestHost({
        path: '/',
        user: {
            uuid: 'u',
            username: 'solohandcoder',
            email: 'a@example.test',
            language: 'en',
            rootAdmin: false,
            useTotp: false,
            createdAt: new Date(0),
            updatedAt: new Date(0),
        },
    });
    const { Wrapper } = host;

    await act(async () => {
        render(children, { wrapper: Wrapper });
    });
}

describe('welcomeStats', () => {
    it('sums the memory of ready servers and counts the running ones', () => {
        expect(
            welcomeStats(
                listModel([
                    item({ uuid: 'a', state: ready('running', GiB, 4 * GiB) }),
                    item({ uuid: 'b', state: ready('offline', 2 * GiB, 8 * GiB) }),
                ])
            )
        ).toEqual({ online: 1, total: 2, memory: { used: 3 * GiB, limit: 12 * GiB } });
    });

    it('reports unlimited memory when any ready server has no limit', () => {
        expect(welcomeStats(listModel(threeServers())).memory).toEqual({ used: 3 * GiB, limit: null });
    });

    it('leaves memory out without any live stats', () => {
        expect(welcomeStats(listModel([]))).toEqual({ online: 0, total: 0, memory: null });
        expect(welcomeStats(listModel([item({ state: { kind: 'loading' } })])).memory).toBeNull();
    });
});

const loaded = { pagination: { total: 3, count: 3, perPage: 25, currentPage: 1, totalPages: 1 } };

describe('Welcome', () => {
    it('greets the user and shows the tiles', async () => {
        await renderInApp(<Welcome model={listModel(threeServers(), loaded)} />);

        expect(screen.getByText(/Hey/).textContent).toContain('Hey solohandcoder');
        expect(screen.getByText('2 / 3')).toBeVisible();
        expect(screen.getByText('servers online')).toBeVisible();
        expect(screen.getByText('memory in use')).toBeVisible();
        expect(screen.getByText(/ \/ ∞$/)).toBeVisible();
    });

    it('offers the Discord invite in a new tab', async () => {
        await renderInApp(<Welcome model={listModel(threeServers(), loaded)} />);

        const discord = screen.getByRole('link', { name: 'Join the Discord' });

        expect(discord).toHaveAttribute('href', 'https://discord.gg/B3fd45pgvC');
        expect(discord).toHaveAttribute('target', '_blank');
        expect(discord).toHaveAttribute('rel', 'noopener noreferrer');
    });

    it('is not a page heading, so the page keeps one h1', async () => {
        await renderInApp(<Welcome model={listModel(threeServers(), loaded)} />);

        expect(screen.queryByRole('heading', { level: 1 })).not.toBeInTheDocument();
    });

    it.each([
        ['every server is online', [ready('running', 0, 1), ready('running', 0, 1)], ', your worlds are running.'],
        [
            'one of several servers is online',
            [ready('running', 0, 1), ready('offline', 0, 1), ready('offline', 0, 1)],
            ', 1 of your 3 servers is online.',
        ],
        [
            'some servers are online',
            [ready('running', 0, 1), ready('running', 0, 1), ready('offline', 0, 1)],
            ', 2 of your 3 servers are online.',
        ],
        ['no server is online', [ready('offline', 0, 1)], ', here are your servers.'],
        ['there are no servers', [], ', here are your servers.'],
    ])('says what is true when %s', async (_case, states, greeting) => {
        const servers = states.map((state, index) => item({ uuid: String(index), state }));

        await renderInApp(<Welcome model={listModel(servers, loaded)} />);

        expect(screen.getByText(/Hey/).textContent).toBe(`Hey solohandcoder${greeting}`);
        expect(screen.getByText('solohandcoder').tagName).toBe('SPAN');
    });

    it('does not claim the servers are running before they have loaded', async () => {
        await renderInApp(<Welcome model={listModel(threeServers(), { loading: true, pagination: null })} />);

        expect(screen.getByText(/Hey/).textContent).toBe('Hey solohandcoder, here are your servers.');
        expect(screen.queryByText('servers online')).not.toBeInTheDocument();
        expect(screen.queryByText('memory in use')).not.toBeInTheDocument();
    });

    it('hides the tiles while the list reloads', async () => {
        await renderInApp(<Welcome model={listModel(threeServers(), { ...loaded, loading: true })} />);

        expect(screen.queryByText('servers online')).not.toBeInTheDocument();
    });

    it('leaves the memory tile out without live stats', async () => {
        await renderInApp(<Welcome model={listModel([item({ state: { kind: 'loading' } })], loaded)} />);

        expect(screen.getByText('servers online')).toBeVisible();
        expect(screen.queryByText('memory in use')).not.toBeInTheDocument();
    });

    it('hides the tiles and speaks neutrally when listing other users servers', async () => {
        await renderInApp(<Welcome model={listModel(threeServers(), { ...loaded, scope: 'others' })} />);

        expect(screen.queryByText('servers online')).not.toBeInTheDocument();
        const text = screen.getByText(/Hey/).textContent ?? '';

        expect(text).toBe("Hey solohandcoder, here are other users' servers.");
        expect(text).not.toMatch(/\byour\b/);
    });
});

describe('server card layout', () => {
    it('keeps every grid to one fixed column so a long description cannot widen the page', async () => {
        await renderInApp(
            <ServerList
                Default={() => null}
                model={listModel(
                    [item({ uuid: 'a', description: 'x'.repeat(400), state: ready('running', GiB, 4 * GiB) })],
                    loaded
                )}
            />
        );

        const card = document.querySelector<HTMLElement>('[data-part="server-card"]')!;

        expect(card.className).toContain('min-w-0');
        const grids = [...card.querySelectorAll<HTMLElement>('.grid')];

        expect(grids.length).toBeGreaterThan(1);
        for (const grid of grids) {
            expect(grid.className).toMatch(/\bgrid-cols-/);
        }
    });

    it('lifts on hover with the translate property it also transitions', async () => {
        await renderInApp(
            <ServerList Default={() => null} model={listModel([item({ state: ready('running', 0, 1) })], loaded)} />
        );

        const card = document.querySelector<HTMLElement>('[data-part="server-card"]')!;

        expect(card.className).toMatch(/transition-\[[^\]]*\btranslate\b/);
    });
});

describe('ServerQuickActions', () => {
    it('hides Restart without the permission', async () => {
        await renderInApp(
            <ServerQuickActions
                item={item({ permissions: { start: true, stop: true, restart: false, console: true, files: true } })}
            />
        );

        expect(screen.queryByRole('button', { name: 'Restart' })).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Console' })).toBeVisible();
        expect(screen.getByRole('link', { name: 'Files' })).toBeVisible();
    });

    it('hides Console without the permission', async () => {
        await renderInApp(
            <ServerQuickActions
                item={item({ permissions: { start: true, stop: true, restart: true, console: false, files: true } })}
            />
        );

        expect(screen.queryByRole('link', { name: 'Console' })).not.toBeInTheDocument();
    });

    it('hides Files without the permission to read files', async () => {
        await renderInApp(
            <ServerQuickActions
                item={item({ permissions: { start: true, stop: true, restart: true, console: true, files: false } })}
            />
        );

        expect(screen.queryByRole('link', { name: 'Files' })).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Console' })).toBeVisible();
    });

    it('restarts only after the dialog is confirmed', async () => {
        const restart = vi.fn();

        await renderInApp(
            <ServerQuickActions
                item={item({ name: 'Survival', actions: { start: vi.fn(), stop: vi.fn(), restart } })}
            />
        );

        fireEvent.click(screen.getByRole('button', { name: 'Restart' }));

        expect(screen.getByText('Players will be disconnected.')).toBeVisible();
        expect(screen.queryByText(/Restart Survival\? Players/)).not.toBeInTheDocument();
        expect(restart).not.toHaveBeenCalled();

        fireEvent.click(screen.getAllByRole('button', { name: 'Restart' }).at(-1)!);

        expect(restart).toHaveBeenCalledOnce();
    });

    it('disables Restart while a signal is on its way', async () => {
        await renderInApp(<ServerQuickActions item={item({ sending: true })} />);

        expect(screen.getByRole('button', { name: 'Restart' })).toBeDisabled();
    });
});

describe('reduced motion', () => {
    it('shows cards without a hidden inline style', async () => {
        vi.stubGlobal(
            'matchMedia',
            vi.fn().mockImplementation((query: string) => ({
                matches: query.includes('reduce'),
                media: query,
                addEventListener: vi.fn(),
                removeEventListener: vi.fn(),
                addListener: vi.fn(),
                removeListener: vi.fn(),
            }))
        );
        await renderInApp(
            <ServerList
                Default={() => null}
                model={listModel(threeServers(), {
                    pagination: { total: 3, count: 3, perPage: 25, currentPage: 1, totalPages: 1 },
                })}
            />
        );

        const cards = document.querySelectorAll<HTMLElement>('[data-part="server-card"]');

        expect(cards).toHaveLength(3);
        for (const card of cards) {
            expect(card.style.opacity).not.toBe('0');
        }
        // Cards stagger through a custom property only; the stylesheet's reduced-motion rule drops the animation.

        for (const card of cards) {
            expect(card.getAttribute('style')).not.toContain('animation');
        }
        // The floating blocks are hidden outright under reduced motion.

        const layer = document.querySelector('[data-part="welcome"] [aria-hidden="true"]');

        expect(layer?.className).toContain('motion-reduce:hidden');
    });
});
