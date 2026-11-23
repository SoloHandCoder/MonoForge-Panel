/** @vitest-environment jsdom */

import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { PublicStorePlans } from '@/api/public/storePlans';
import { DISCORD_INVITE_URL } from '@/themes/classic/views/brand/links';
import HomePage, { HOME_TITLE } from './HomePage';
import { cheapestMonthly, periodLabel, planIncludes } from './HomePlans';

const state = vi.hoisted(() => ({
    plans: { data: undefined as PublicStorePlans | undefined, isPending: false },
    registration: true,
}));

vi.mock('@tanstack/react-router', () => ({
    Link: ({
        to,
        search,
        className,
        children,
    }: {
        to: string;
        search?: { redirect?: string };
        className?: string;
        children: ReactNode;
    }) => (
        <a href={search?.redirect ? `${to}?redirect=${search.redirect}` : to} className={className}>
            {children}
        </a>
    ),
}));
vi.mock('@/api/public/storePlans', () => ({ usePublicStorePlans: () => state.plans }));
vi.mock('@/api/settings/queries', () => ({
    useSiteSettings: () => ({ registration: { enabled: state.registration } }),
}));

const catalog = (overrides: Partial<PublicStorePlans> = {}): PublicStorePlans => ({
    enabled: true,
    assistantEnabled: false,
    periods: [
        { id: 1, name: 'Monthly', days: 30 },
        { id: 2, name: 'Quarterly', days: 90 },
    ],
    categories: [
        {
            name: 'Minecraft',
            slug: 'minecraft',
            description: 'Java edition servers.',
            plans: [
                {
                    id: 3,
                    name: 'Stone',
                    description: 'A small survival server.',
                    memory: 2048,
                    disk: 10240,
                    cpu: 100,
                    databases: 1,
                    backups: 2,
                    games: ['Paper', 'Vanilla'],
                    available: true,
                    prices: [
                        { periodId: 1, days: 30, tokens: 400 },
                        { periodId: 2, days: 90, tokens: 1080 },
                    ],
                },
                {
                    id: 4,
                    name: 'Diamond',
                    description: null,
                    memory: 8192,
                    disk: 40960,
                    cpu: 300,
                    databases: 2,
                    backups: 5,
                    games: ['Paper'],
                    available: false,
                    prices: [{ periodId: 1, days: 30, tokens: 1600 }],
                },
            ],
        },
        {
            name: 'Terraria',
            slug: 'terraria',
            description: null,
            plans: [
                {
                    id: 7,
                    name: 'Guide',
                    description: null,
                    memory: 1024,
                    disk: 5120,
                    cpu: 100,
                    databases: 0,
                    backups: 1,
                    games: ['tModLoader'],
                    available: true,
                    prices: [{ periodId: 1, days: 30, tokens: 250 }],
                },
            ],
        },
    ],
    ...overrides,
});

const plansSection = () => within(screen.getByRole('region', { name: 'Plans and prices' }));
const gamesSection = () => within(screen.getByRole('region', { name: 'Pick a game, name your server' }));

afterEach(cleanup);
beforeEach(() => {
    state.plans = { data: catalog(), isPending: false };
    state.registration = true;
});

describe('public homepage', () => {
    it('titles the page with the name, says what it is and leads to sign-up', () => {
        render(<HomePage />);

        expect(document.title).toBe(HOME_TITLE);
        expect(screen.getByRole('heading', { level: 1, name: 'MonoForge' })).toHaveTextContent('MonoForge');
        expect(
            screen.getByText(/Minecraft Java and Terraria servers on our own machines in Switzerland/)
        ).toBeVisible();
        expect(screen.getAllByRole('img', { name: 'MonoForge' })).not.toHaveLength(0);
        expect(screen.getByRole('img', { name: /console for the server Friday Survival/ })).toBeInTheDocument();
        for (const link of screen.getAllByRole('link', { name: /^Get started/ })) {
            expect(link).toHaveAttribute('href', '/auth/register');
        }

        for (const link of screen.getAllByRole('link', { name: 'Sign in' })) {
            expect(link).toHaveAttribute('href', '/auth/login');
        }
    });

    it('sends "Get started" to sign in, back to the chosen plan, when sign-up is off', () => {
        state.registration = false;
        render(<HomePage />);

        expect(screen.getAllByRole('link', { name: 'Get started' })[0]).toHaveAttribute('href', '/auth/login');
        expect(plansSection().getByRole('link', { name: 'Get started with Stone' })).toHaveAttribute(
            'href',
            '/auth/login?redirect=/store/3'
        );
    });

    it('shows Minecraft Java first and Terraria on a switch, each with its lowest monthly price', () => {
        render(<HomePage />);
        const games = gamesSection();

        expect(games.getByRole('radio', { name: 'Minecraft: Java Edition' })).toHaveAttribute('aria-checked', 'true');
        expect(games.getByText('From 360 tokens a month')).toBeVisible();

        fireEvent.click(games.getByRole('radio', { name: 'Terraria' }));
        expect(games.getByRole('radio', { name: 'Terraria' })).toHaveAttribute('aria-checked', 'true');
        expect(games.getByText('From 250 tokens a month')).toBeVisible();
        expect(games.getByText(/fight bosses together/)).toBeVisible();
    });

    it('works out the page and join address of the name the visitor types', () => {
        render(<HomePage />);
        const games = gamesSection();
        const input = games.getByLabelText('Server name');
        const said = () => games.getByText(/^Its page:/).closest('p');

        // Before anything is typed, the worked example.
        expect(said()).toHaveTextContent('Its page: /s/friday-survival');
        expect(said()).toHaveTextContent('Friends join at friday-survival.play.monoforge.net');

        fireEvent.change(input, { target: { value: "Mira's Build Club!" } });
        expect(said()).toHaveTextContent('Its page: /s/miras-build-club');
        expect(said()).toHaveTextContent('Friends join at miras-build-club.play.monoforge.net');
        // The page picture follows the name too.
        expect(games.getByText("Mira's Build Club!")).toBeVisible();
        expect(games.getAllByText('miras-build-club.play.monoforge.net')).not.toHaveLength(0);

        fireEvent.change(input, { target: { value: 'Staff Hangout' } });
        expect(games.getByText('That name is kept for the panel itself. Try another one.')).toBeVisible();
        expect(games.queryByText(/^Its page:/)).toBeNull();

        fireEvent.change(input, { target: { value: 'ab' } });
        expect(games.getByText('Use at least 3 letters or digits.')).toBeVisible();

        // Terraria is joined through playit.gg, so no play.monoforge.net address is promised.
        fireEvent.change(input, { target: { value: 'Boss Rush' } });
        fireEvent.click(games.getByRole('radio', { name: 'Terraria' }));
        expect(said()).toHaveTextContent('Its page: /s/boss-rush');
        expect(games.queryByText(/play\.monoforge\.net/)).toBeNull();
        expect(games.getByText('Your playit.gg address and port')).toBeVisible();
    });

    it('lists the live plans by name and opens the chosen one with its price for the period and game', () => {
        render(<HomePage />);
        const plans = plansSection();

        expect(plans.getByRole('button', { name: 'Stone' })).toHaveAttribute('aria-expanded', 'true');
        expect(plans.getByRole('heading', { name: 'Diamond' })).toBeVisible();
        expect(plans.getByText('400')).toBeVisible();
        expect(plans.getByText('tokens / month')).toBeVisible();
        expect(plans.getByText(/^2 GB memory, 10 GB storage/)).toBeVisible();
        expect(plans.getByText('Runs Paper, Vanilla')).toBeVisible();

        // A plan the store cannot place right now is shown but cannot be picked.
        fireEvent.click(plans.getByRole('button', { name: 'Diamond' }));
        expect(plans.getByRole('button', { name: 'Diamond' })).toHaveAttribute('aria-expanded', 'true');
        expect(plans.getByRole('button', { name: 'Stone' })).toHaveAttribute('aria-expanded', 'false');
        expect(plans.getByText('Sold out for now')).toBeVisible();
        expect(plans.queryByRole('link', { name: 'Get started with Diamond' })).toBeNull();

        fireEvent.click(plans.getByRole('radio', { name: 'Quarterly' }));
        expect(plans.getByRole('radio', { name: 'Quarterly' })).toHaveAttribute('aria-checked', 'true');
        expect(plans.getByText('Not sold for this period')).toBeVisible();

        fireEvent.click(plans.getByRole('button', { name: 'Stone' }));
        expect(plans.getByText('1,080')).toBeVisible();
        expect(plans.getByText('tokens / 3 months')).toBeVisible();
        expect(plans.getByText('About 360 tokens a month.')).toBeVisible();

        fireEvent.click(plans.getByRole('radio', { name: 'Terraria' }));
        expect(plans.getByRole('heading', { name: 'Guide' })).toBeVisible();
        expect(plans.getByRole('button', { name: 'Guide' })).toHaveAttribute('aria-expanded', 'true');
        expect(plans.queryByRole('heading', { name: 'Stone' })).toBeNull();
    });

    it('says prices are on their way while loading and invites to Discord when the store is closed', () => {
        state.plans = { data: undefined, isPending: true };
        const { rerender } = render(<HomePage />);

        expect(plansSection().getByText('Fetching today’s prices…')).toBeVisible();
        expect(plansSection().queryByRole('heading', { level: 3 })).toBeNull();

        state.plans = { data: catalog({ enabled: false, periods: [], categories: [] }), isPending: false };
        rerender(<HomePage />);
        expect(plansSection().getByText('New plans are being forged')).toBeVisible();
        expect(plansSection().getByRole('link', { name: 'Join the Discord' })).toHaveAttribute(
            'href',
            DISCORD_INVITE_URL
        );
        expect(gamesSection().getByText('Plans coming soon')).toBeVisible();
    });

    it('shows six parts of the panel, the AI assistant only when it is enabled', () => {
        const { rerender } = render(<HomePage />);
        const panel = () => within(screen.getByRole('region', { name: 'Run it all from your browser' }));

        expect(panel().queryByRole('heading', { name: 'An assistant for crashes' })).toBeNull();
        for (const name of [
            'Every Minecraft version, one click',
            'Plugins, mods and modpacks',
            'A file editor that knows Skript',
            'Backups you can lock',
            'Schedules without cron',
            'People on Discord',
        ]) {
            expect(panel().getByRole('heading', { name })).toBeVisible();
        }

        expect(panel().getAllByRole('listitem')).toHaveLength(6);
        // Every part is pictured by the panel's own screen, described for screen readers.
        expect(panel().getAllByRole('img')).toHaveLength(6);

        state.plans = { data: catalog({ assistantEnabled: true }), isPending: false };
        rerender(<HomePage />);
        expect(panel().getByRole('heading', { name: 'An assistant for crashes' })).toBeVisible();
        expect(panel().queryByRole('heading', { name: 'Schedules without cron' })).toBeNull();
        expect(panel().getAllByRole('listitem')).toHaveLength(6);
    });

    it('explains paying with tokens step by step, and where it runs', () => {
        render(<HomePage />);
        const paying = within(screen.getByRole('region', { name: 'How paying works' }));

        expect(paying.getByText('First, you buy tokens.')).toBeVisible();
        expect(paying.getByText('While nobody plays,')).toBeVisible();
        expect(paying.getByRole('img', { name: /84 tokens saved by sleeping/ })).toBeInTheDocument();
        expect(paying.getByText('All of it runs')).toBeVisible();
        // No map: an old map of some Swiss region would read as the servers' real location.
        expect(paying.queryByRole('img', { name: /map/i })).not.toBeInTheDocument();
    });

    it('answers the common questions in the open and links the legal pages and Discord in the footer', () => {
        render(<HomePage />);

        const faq = within(screen.getByRole('region', { name: 'Questions people ask' }));

        expect(faq.getByText('How do my friends join?')).toBeVisible();
        expect(faq.getByText(/address of their own on play\.monoforge\.net/)).toBeVisible();
        expect(faq.getByText('Where do the servers run?')).toBeVisible();
        expect(screen.getByRole('link', { name: 'refund policy' })).toHaveAttribute('href', '/legal/refunds');
        expect(screen.getByRole('link', { name: 'help center' })).toHaveAttribute('href', '/help');

        const footer = within(screen.getByRole('contentinfo'));

        expect(footer.getByRole('link', { name: 'Terms' })).toHaveAttribute('href', '/legal/terms');
        expect(footer.getByRole('link', { name: 'Privacy' })).toHaveAttribute('href', '/legal/privacy');
        expect(footer.getByRole('link', { name: 'Refunds' })).toHaveAttribute('href', '/legal/refunds');
        expect(footer.getByRole('link', { name: 'Discord' })).toHaveAttribute('href', DISCORD_INVITE_URL);
        expect(footer.getByRole('link', { name: 'Help center' })).toHaveAttribute('href', '/help');
        expect(footer.getByRole('link', { name: 'Status' })).toHaveAttribute('href', '/status');
        expect(footer.getByRole('link', { name: 'Discover servers' })).toHaveAttribute('href', '/servers');
        // The found pictures are credited with a link to their source.
        expect(footer.getByRole('link', { name: 'Durand vignette, The Met, CC0' })).toHaveAttribute(
            'href',
            expect.stringContaining('commons.wikimedia.org')
        );
    });
});

describe('homepage formatting', () => {
    it('names billing periods', () => {
        expect(periodLabel(30)).toBe('month');
        expect(periodLabel(90)).toBe('3 months');
        expect(periodLabel(365)).toBe('year');
        expect(periodLabel(7)).toBe('7 days');
    });

    it('finds the lowest monthly price in a category', () => {
        const [minecraft] = catalog().categories;

        // 1080 tokens for 90 days is 360 a month, below the 400 monthly price.
        expect(cheapestMonthly(minecraft)).toBe(360);
        expect(cheapestMonthly({ ...minecraft, plans: [] })).toBeNull();
    });

    it('says what a plan includes in one sentence', () => {
        const [stone, diamond] = catalog().categories[0].plans;

        expect(planIncludes(stone)).toBe('2 GB memory, 10 GB storage, 1 CPU thread, 2 backups and 1 database.');
        expect(planIncludes(diamond)).toMatch(/5 backups and 2 databases\.$/);
    });
});
