/** @vitest-environment jsdom */

import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { HelpArticle } from '@/api/help';
import { HelpArticleView } from './HelpArticlePage';
import { HelpCenterView } from './HelpCenterPage';

vi.mock('@tanstack/react-router', () => ({
    Link: ({
        to,
        params,
        className,
        children,
        ...rest
    }: {
        to: string;
        params?: { slug?: string };
        className?: string;
        children: ReactNode;
        'aria-current'?: 'page';
    }) => (
        <a href={params?.slug ? to.replace('$slug', params.slug) : to} className={className} {...rest}>
            {children}
        </a>
    ),
    useParams: () => ({}),
}));

afterEach(cleanup);

const articles: HelpArticle[] = [
    {
        slug: 'getting-started',
        title: 'Getting started',
        category: 'Getting started',
        summary: 'Create your account.',
        content: '# Getting started\n\nWelcome!',
    },
    {
        slug: 'joining-your-server',
        title: 'Joining your server',
        category: 'Getting started',
        summary: 'Find your address.',
        content: '# Joining your server\n\nShare the **playit.gg** address. "Address pending" means wait a moment.',
    },
    {
        slug: 'the-console',
        title: 'The console',
        category: 'Your server',
        summary: 'Commands and power buttons.',
        content:
            '# The console\n\nPress **Tab** to complete commands.\n\n<script>alert(1)</script>\n\n[bad](javascript:alert(2))',
    },
];

describe('the help center', () => {
    it('lists the articles under their categories', () => {
        render(<HelpCenterView articles={articles} />);

        const started = within(screen.getByRole('region', { name: 'Getting started' }));

        expect(started.getByRole('link', { name: /Joining your server/ })).toHaveAttribute(
            'href',
            '/help/joining-your-server'
        );
        expect(started.getByText('Find your address.')).toBeVisible();
        expect(screen.getByRole('region', { name: 'Your server' })).toHaveTextContent('The console');
    });

    it('searches titles and text as you type', async () => {
        render(<HelpCenterView articles={articles} />);

        fireEvent.change(screen.getByLabelText('Search the help center'), { target: { value: 'address pending' } });

        await waitFor(() => expect(screen.getByText('1 article for “address pending”')).toBeVisible());
        expect(screen.getByRole('link', { name: /Joining your server/ })).toBeVisible();
        expect(screen.queryByRole('region', { name: 'Your server' })).toBeNull();

        fireEvent.change(screen.getByLabelText('Search the help center'), { target: { value: 'nothing here' } });
        await waitFor(() => expect(screen.getByText('No articles match “nothing here”.')).toBeVisible());

        fireEvent.click(screen.getByRole('button', { name: 'Clear the search' }));
        await waitFor(() => expect(screen.getByRole('region', { name: 'Your server' })).toBeVisible());
    });
});

describe('a help article', () => {
    it('renders the markdown safely with the other articles beside it', () => {
        const { container } = render(<HelpArticleView articles={articles} slug='the-console' />);

        expect(screen.getByRole('heading', { level: 1, name: 'The console' })).toBeVisible();
        expect(screen.getByText('Tab').tagName).toBe('STRONG');
        // Raw HTML stays text and a javascript: link is not a link.
        expect(container.querySelector('script')).toBeNull();
        expect(screen.getByText('<script>alert(1)</script>')).toBeVisible();
        expect(screen.queryByRole('link', { name: 'bad' })).toBeNull();

        const nav = within(screen.getByRole('navigation', { name: 'Help articles' }));

        expect(nav.getByRole('link', { name: 'The console' })).toHaveAttribute('aria-current', 'page');
        expect(screen.getByRole('link', { name: /Previous\s*Joining your server/ })).toHaveAttribute(
            'href',
            '/help/joining-your-server'
        );
        expect(document.title).toBe('The console | MonoForge Help');
    });

    it('says so when the article does not exist', () => {
        render(<HelpArticleView articles={articles} slug='missing' />);

        expect(screen.getByRole('heading', { name: 'Article not found' })).toBeVisible();
        expect(screen.getByRole('link', { name: 'Browse the help center' })).toHaveAttribute('href', '/help');
    });
});
