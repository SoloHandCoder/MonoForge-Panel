/** @vitest-environment jsdom */

import { cleanup, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type * as DiscoveryQueries from '@/api/public/serverDiscovery';
import type { DiscoveredServer, DiscoveryFilters, ServerDiscoveryList } from '@/api/public/serverDiscovery';
import { discoveryQuery } from '@/api/public/serverDiscovery';
import ServerDiscoveryPage from './ServerDiscoveryPage';

const state = vi.hoisted(() => ({
    list: undefined as ServerDiscoveryList | undefined,
    filters: [] as DiscoveryFilters[],
    copy: vi.fn(async () => true),
}));

vi.mock('@/api/public/serverDiscovery', async (importOriginal) => ({
    ...(await importOriginal<typeof DiscoveryQueries>()),
    useServerDiscovery: (filters: DiscoveryFilters) => {
        state.filters.push(filters);

        return { data: state.list, error: null, isPending: false, isFetching: false, refetch: vi.fn() };
    },
}));
vi.mock('@/lib/clipboard', () => ({ default: state.copy }));
vi.mock('@tanstack/react-router', () => ({
    Link: ({ to, children, className }: { to: string; children: React.ReactNode; className?: string }) => (
        <a href={to} className={className}>
            {children}
        </a>
    ),
}));

const server = (overrides: Partial<DiscoveredServer> = {}): DiscoveredServer => ({
    slug: 'skyblock',
    url: '/s/skyblock',
    name: 'Skyblock Island',
    tagline: 'Islands and events',
    description: 'A friendly server <img src=x onerror=alert(1)>',
    game: { slug: 'minecraft', name: 'Minecraft', color: '#62B47A' },
    status: 'online',
    players: { online: 3, max: 20 },
    votes: 42,
    address: 'skyblock.joinmc.link',
    theme: {
        accent: '#8b5cf6',
        accent_text: '#000000',
        background: { style: 'solid', from: '#241a44', to: null, image_url: null },
        text: '#ffffff',
        button_style: 'rounded',
    },
    sections: { players: true, votes: true, address: true, description: true },
    created_at: '2026-11-10T18:30:00+00:00',
    ...overrides,
});

const list = (data: DiscoveredServer[], hasMore = false): ServerDiscoveryList => ({
    data,
    meta: { page: 1, per_page: 24, has_more: hasMore },
    games: [
        { slug: 'minecraft', name: 'Minecraft', color: '#62B47A' },
        { slug: 'terraria', name: 'Terraria', color: '#4C9A2A' },
    ],
});

afterEach(cleanup);
beforeEach(() => {
    vi.clearAllMocks();
    state.filters = [];
    state.list = list([server()]);
});

describe('server discovery page', () => {
    it('lists each server with its game, status, players, votes and actions', async () => {
        const user = userEvent.setup();
        const { container } = render(<ServerDiscoveryPage />);

        expect(screen.getByRole('heading', { level: 1, name: 'Discover servers' })).toBeInTheDocument();

        const card = screen.getByRole('article');

        expect(within(card).getByRole('heading', { name: 'Skyblock Island' })).toBeInTheDocument();
        expect(within(card).getByText('Islands and events')).toBeInTheDocument();
        expect(within(card).getByText('Minecraft')).toBeInTheDocument();
        expect(within(card).getByText('Online')).toBeInTheDocument();
        expect(within(card).getByText('3 / 20 online')).toBeInTheDocument();
        expect(within(card).getByText('42 votes')).toBeInTheDocument();
        // The owner's text is shown as text, never as html.
        expect(within(card).getByText(/<img src=x onerror=alert\(1\)>/)).toBeInTheDocument();
        expect(container.querySelector('img[src="x"]')).toBeNull();
        expect(within(card).getByRole('link', { name: /View page/ })).toHaveAttribute('href', '/s/skyblock');

        await user.click(within(card).getByRole('button', { name: /Copy address/ }));
        expect(state.copy).toHaveBeenCalledWith('skyblock.joinmc.link');
    });

    it('leaves out what the owner hides', () => {
        state.list = list([
            server({
                players: null,
                address: null,
                description: '',
                sections: { players: false, votes: false, address: false, description: false },
            }),
        ]);
        render(<ServerDiscoveryPage />);

        const card = screen.getByRole('article');

        expect(within(card).queryByRole('button', { name: /Copy address/ })).not.toBeInTheDocument();
        expect(within(card).queryByText(/votes/)).not.toBeInTheDocument();
        expect(within(card).queryByText(/online$/)).not.toBeInTheDocument();
    });

    it('filters by name, game and sort, starting again at the first page', async () => {
        const user = userEvent.setup();

        state.list = list([server()], true);
        render(<ServerDiscoveryPage />);

        await user.click(screen.getByRole('button', { name: /Next/ }));
        expect(state.filters.at(-1)).toMatchObject({ page: 2 });

        await user.selectOptions(screen.getByLabelText('Game'), 'terraria');
        expect(state.filters.at(-1)).toMatchObject({ game: 'terraria', page: 1 });

        await user.selectOptions(screen.getByLabelText('Sort by'), 'votes');
        expect(state.filters.at(-1)).toMatchObject({ sort: 'votes', page: 1 });

        await user.type(screen.getByLabelText('Search servers by name'), 'sky');
        expect(state.filters.at(-1)).toMatchObject({ search: 'sky', page: 1 });
    });

    it('says when nothing matches', async () => {
        const user = userEvent.setup();

        state.list = list([]);
        render(<ServerDiscoveryPage />);

        expect(screen.getByText('No servers are listed yet.')).toBeInTheDocument();
        await user.type(screen.getByLabelText('Search servers by name'), 'zzz');
        expect(screen.getByText('No servers match these filters.')).toBeInTheDocument();
    });

    it('sends only the filters that are set', () => {
        expect(discoveryQuery({ sort: 'players', page: 1 })).toEqual({ sort: 'players', page: 1 });
        expect(discoveryQuery({ game: 'minecraft', search: '  sky ', sort: 'newest', page: 3 })).toEqual({
            game: 'minecraft',
            search: 'sky',
            sort: 'newest',
            page: 3,
        });
    });
});
