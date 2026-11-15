/** @vitest-environment jsdom */

import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { PublicStorePlans } from '@/api/public/storePlans';
import { DISCORD_INVITE_URL } from '@/themes/classic/views/brand/links';
import HomePage, { HOME_TITLE } from './HomePage';
import { cheapestMonthly, periodLabel } from './HomePlans';

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

const plansSection = () => within(screen.getByRole('region', { name: 'Pick your plan' }));

afterEach(cleanup);
beforeEach(() => {
    state.plans = { data: catalog(), isPending: false };
    state.registration = true;
});

describe('public homepage', () => {
    it('titles the page and leads with the anvil, the tagline and the way in', () => {
        render(<HomePage />);

        expect(document.title).toBe(HOME_TITLE);
        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Forge your own game server');
        expect(screen.getAllByRole('img', { name: 'MonoForge' })).not.toHaveLength(0);
        for (const link of screen.getAllByRole('link', { name: /^Get started/ })) {
            expect(link).toHaveAttribute('href', '/auth/register');
        }

        expect(screen.getByRole('link', { name: 'Sign in' })).toHaveAttribute('href', '/auth/login');
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

    it('shows Minecraft Java and Terraria first with their lowest monthly price', () => {
        render(<HomePage />);

        const games = within(screen.getByRole('region', { name: 'Start with the classics' }));
        const titles = games.getAllByRole('heading', { level: 3 }).map((heading) => heading.textContent);

        expect(titles).toEqual(['Minecraft: Java Edition', 'Terraria']);
        expect(games.getByText('From 360 tokens a month')).toBeVisible();
        expect(games.getByText('From 250 tokens a month')).toBeVisible();
    });

    it('lists the live plans with their price for the chosen period and game', () => {
        render(<HomePage />);
        const plans = plansSection();

        expect(plans.getByRole('heading', { name: 'Stone' })).toBeVisible();
        expect(plans.getByText('400')).toBeVisible();
        expect(plans.getByText('2 GB RAM')).toBeVisible();
        expect(plans.getByText('Runs Paper, Vanilla')).toBeVisible();
        // A plan the store cannot place right now is shown but cannot be picked.
        expect(plans.getByText('Sold out for now')).toBeVisible();
        expect(plans.queryByRole('link', { name: 'Get started with Diamond' })).toBeNull();

        fireEvent.click(plans.getByRole('radio', { name: 'Quarterly' }));
        expect(plans.getByRole('radio', { name: 'Quarterly' })).toHaveAttribute('aria-checked', 'true');
        expect(plans.getByText('1,080')).toBeVisible();
        expect(plans.getByText('tokens / 3 months')).toBeVisible();
        expect(plans.getByText('Not sold for this period')).toBeVisible();

        fireEvent.click(plans.getByRole('radio', { name: 'Terraria' }));
        expect(plans.getByRole('heading', { name: 'Guide' })).toBeVisible();
        expect(plans.queryByRole('heading', { name: 'Stone' })).toBeNull();
    });

    it('draws placeholders while loading and invites to Discord when the store is closed', () => {
        state.plans = { data: undefined, isPending: true };
        const { rerender } = render(<HomePage />);

        expect(plansSection().queryByRole('heading', { level: 3 })).toBeNull();

        state.plans = { data: catalog({ enabled: false, periods: [], categories: [] }), isPending: false };
        rerender(<HomePage />);
        expect(plansSection().getByText('New plans are being forged')).toBeVisible();
        expect(plansSection().getByRole('link', { name: 'Join the Discord' })).toHaveAttribute(
            'href',
            DISCORD_INVITE_URL
        );
        expect(screen.getAllByText('Plans coming soon')).toHaveLength(2);
    });

    it('lists the AI assistant only when it is enabled', () => {
        const { rerender } = render(<HomePage />);
        const features = () => within(screen.getByRole('region', { name: 'Everything you need, nothing to install' }));

        expect(features().queryByRole('heading', { name: 'AI assistant' })).toBeNull();
        for (const name of ['Instant setup', 'Ready-made addresses', 'Web file editor', 'Backups', 'Discord support']) {
            expect(features().getByRole('heading', { name })).toBeVisible();
        }

        state.plans = { data: catalog({ assistantEnabled: true }), isPending: false };
        rerender(<HomePage />);
        expect(features().getByRole('heading', { name: 'AI assistant' })).toBeVisible();
    });

    it('answers the common questions and links the legal pages and Discord in the footer', () => {
        render(<HomePage />);

        const question = screen.getByText('How do my friends join?');

        expect(question.closest('details')).not.toHaveAttribute('open');
        expect(screen.getByRole('link', { name: 'refund policy' })).toHaveAttribute('href', '/legal/refunds');

        const footer = within(screen.getByRole('contentinfo'));

        expect(footer.getByRole('link', { name: 'Terms' })).toHaveAttribute('href', '/legal/terms');
        expect(footer.getByRole('link', { name: 'Privacy' })).toHaveAttribute('href', '/legal/privacy');
        expect(footer.getByRole('link', { name: 'Discord' })).toHaveAttribute('href', DISCORD_INVITE_URL);
    });

    it('keeps the decorative backdrop out of the accessibility tree', () => {
        const { container } = render(<HomePage />);

        const blocks = container.querySelectorAll('.home-block');

        expect(blocks.length).toBeGreaterThan(20);
        for (const block of blocks) {
            expect(block.closest('[aria-hidden="true"]')).not.toBeNull();
        }
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
});
