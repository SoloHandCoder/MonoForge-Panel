/** @vitest-environment jsdom */

import { AxiosError, AxiosHeaders } from 'axios';
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type * as PageQueries from '@/api/public/serverPage';
import type { PublicServerPage } from '@/api/public/serverPage';
import { PublicServerView, bannerStyle } from './PublicServerPage';

interface MutationState {
    mutate: ReturnType<typeof vi.fn>;
    isPending: boolean;
    isError: boolean;
    data?: unknown;
}

const vote = vi.hoisted(() => ({ current: { mutate: vi.fn(), isPending: false, isError: false } as MutationState }));
const wake = vi.hoisted(() => ({ current: { mutate: vi.fn(), isPending: false, isError: false } as MutationState }));
const copy = vi.hoisted(() => vi.fn(async () => true));

vi.mock('@/api/public/serverPage', async (importOriginal) => ({
    ...(await importOriginal<typeof PageQueries>()),
    useVoteForServer: () => vote.current,
    useWakeServer: () => wake.current,
}));
vi.mock('@/lib/clipboard', () => ({ default: copy }));

const page = (overrides: Partial<PublicServerPage> = {}): PublicServerPage => ({
    slug: 'skyblock',
    name: 'Skyblock Island',
    description: 'A **friendly** server <script>alert(1)</script>',
    banner: { color: '#22c55e', image_url: null },
    game: 'Minecraft',
    software: 'Paper',
    version: '1.21.4',
    status: 'online',
    players: { online: 3, max: 20 },
    address: 'skyblock.joinmc.link',
    join_url: null,
    votes: 42,
    ...overrides,
});

const renderView = (value: PublicServerPage, onWoken = vi.fn()) =>
    render(<PublicServerView page={value} slug='skyblock' onWoken={onWoken} />);

afterEach(cleanup);
beforeEach(() => {
    vi.clearAllMocks();
    vote.current = { mutate: vi.fn(), isPending: false, isError: false };
    wake.current = { mutate: vi.fn(), isPending: false, isError: false };
});

describe('public server page', () => {
    it('shows the name, game, status with players, address and votes', () => {
        renderView(page());

        expect(screen.getByRole('heading', { level: 1, name: 'Skyblock Island' })).toBeInTheDocument();
        expect(screen.getByRole('status')).toHaveTextContent('Online · 3 / 20 players');
        expect(screen.getByText('Minecraft')).toBeInTheDocument();
        expect(screen.getByText('Paper')).toBeInTheDocument();
        expect(screen.getByText('1.21.4')).toBeInTheDocument();
        expect(screen.getByText('skyblock.joinmc.link')).toBeInTheDocument();
        expect(screen.getByText('42')).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /Add in game/ })).not.toBeInTheDocument();
    });

    it('renders the description as safe markdown, never as html', () => {
        const { container } = renderView(page());

        expect(screen.getByText('friendly').tagName).toBe('STRONG');
        expect(container.querySelector('script')).toBeNull();
        expect(screen.getByText(/<script>alert\(1\)<\/script>/)).toBeInTheDocument();
    });

    it('asks visitors to contact the owner when there is no public address', () => {
        renderView(page({ address: null }));

        expect(
            screen.getByText('This server has no public address yet. Ask the owner how to join.')
        ).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Copy address/ })).not.toBeInTheDocument();
    });

    it('copies the address and offers the in-game link where there is one', async () => {
        const user = userEvent.setup();

        renderView(page({ join_url: 'minecraft://?addExternalServer=Skyblock|skyblock.joinmc.link:19132' }));

        await user.click(screen.getByRole('button', { name: /Copy address/ }));

        expect(copy).toHaveBeenCalledWith('skyblock.joinmc.link');
        expect(await screen.findByRole('button', { name: /Copied/ })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /Add in game/ })).toHaveAttribute(
            'href',
            'minecraft://?addExternalServer=Skyblock|skyblock.joinmc.link:19132'
        );
    });

    it('votes and says when a vote was not counted', async () => {
        const user = userEvent.setup();
        const { rerender } = renderView(page());

        await user.click(screen.getByRole('button', { name: /Vote/ }));
        expect(vote.current.mutate).toHaveBeenCalledWith({ path: { slug: 'skyblock' } });

        vote.current = { ...vote.current, data: { counted: false, votes: 42, next_vote_at: '2026-11-16T12:00:00Z' } };
        rerender(<PublicServerView page={page()} slug='skyblock' onWoken={vi.fn()} />);

        expect(screen.getByText(/You already voted. Vote again/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Vote/ })).toBeDisabled();
    });

    it('offers to wake a sleeping server and reports a refused wake', async () => {
        const user = userEvent.setup();
        const onWoken = vi.fn();

        wake.current.mutate = vi.fn(
            (_input, options: { onSuccess: (result: { woken: boolean; sleeping: boolean }) => void }) =>
                options.onSuccess({ woken: true, sleeping: false })
        );
        renderView(page({ status: 'sleeping', players: null }), onWoken);

        expect(screen.getByRole('status')).toHaveTextContent('Sleeping');
        await user.click(screen.getByRole('button', { name: /Wake server/ }));
        expect(onWoken).toHaveBeenCalled();

        cleanup();
        const refused = new AxiosError('Too many', '429', undefined, undefined, {
            status: 429,
            statusText: 'Too Many Requests',
            headers: { 'retry-after': '42' },
            config: { headers: new AxiosHeaders() },
            data: {},
        });

        wake.current.mutate = vi.fn((_input, options: { onError: (error: AxiosError) => void }) =>
            options.onError(refused)
        );
        renderView(page({ status: 'sleeping', players: null }));
        await user.click(screen.getByRole('button', { name: /Wake server/ }));

        expect(await screen.findByText('It was woken a moment ago. Try again in 42 seconds.')).toBeInTheDocument();
    });

    it('does not offer the wake button to a server that is awake', () => {
        renderView(page({ status: 'offline', players: null }));

        expect(screen.getByRole('status')).toHaveTextContent('Offline');
        expect(screen.queryByRole('button', { name: /Wake server/ })).not.toBeInTheDocument();
    });
});

describe('banner style', () => {
    it('uses the image only for a panel banner path and falls back to the colour', () => {
        expect(bannerStyle({ color: null, image_url: null }).backgroundColor).toBe('#8b5cf6');
        expect(bannerStyle({ color: '#22c55e', image_url: null }).backgroundImage).toContain('#22c55e');
        expect(
            bannerStyle({ color: null, image_url: `/s/skyblock/banner/${'a'.repeat(40)}.png` }).backgroundImage
        ).toBe(`url("/s/skyblock/banner/${'a'.repeat(40)}.png")`);
        expect(
            bannerStyle({ color: null, image_url: 'https://evil.example/x.png") ; background: red' }).backgroundImage
        ).toContain('linear-gradient');
        expect(bannerStyle({ color: 'red;x:y', image_url: null }).backgroundColor).toBe('#8b5cf6');
    });
});
