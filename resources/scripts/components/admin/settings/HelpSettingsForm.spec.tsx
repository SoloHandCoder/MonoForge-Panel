/** @vitest-environment jsdom */

import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type * as HelpQueries from '@/api/admin/settings/help';
import { type AdminHelpArticle, slugify, updateAdminHelpArticleInput } from '@/api/admin/settings/help';
import { HelpArticleForm } from './HelpSettingsForm';

const update = vi.hoisted(() => vi.fn());
const reset = vi.hoisted(() => vi.fn());

vi.mock('@/api/admin/settings/help', async (importOriginal) => ({
    ...(await importOriginal<typeof HelpQueries>()),
    useUpdateAdminHelpArticle: () => ({ mutateAsync: update }),
    useResetAdminHelpArticle: () => ({ mutateAsync: reset }),
}));

const console: AdminHelpArticle = {
    slug: 'the-console',
    title: 'The console',
    category: 'Your server',
    summary: 'Commands and power buttons.',
    content: '# The console\n\nPress **Tab**.',
    order: 60,
    audience: 'public',
    enabled: true,
    shipped: true,
    overridden: false,
    publicUrl: '/help/the-console',
};

afterEach(cleanup);
beforeEach(() => vi.clearAllMocks());

describe('help settings', () => {
    it('previews the markdown without running markup', async () => {
        const { container } = render(
            <HelpArticleForm
                article={{ ...console, content: '# Hi\n\n<img src=x onerror=alert(1)> **bold**' }}
                onSaved={vi.fn()}
                onReset={vi.fn()}
            />
        );

        expect(screen.getByText('Starter text')).toBeVisible();
        expect(screen.getByRole('link', { name: 'View the public page' })).toHaveAttribute('href', '/help/the-console');

        await userEvent.click(screen.getByRole('tab', { name: 'Preview' }));

        expect(screen.getByRole('heading', { name: 'Hi' })).toBeVisible();
        expect(screen.getByText('bold').tagName).toBe('STRONG');
        expect(container.querySelector('img')).toBeNull();
    });

    it('saves the article and turns it off', async () => {
        const user = userEvent.setup();
        const onSaved = vi.fn();

        render(<HelpArticleForm article={console} onSaved={onSaved} onReset={vi.fn()} />);

        fireEvent.change(screen.getByLabelText('Markdown'), { target: { value: '# Our console' } });
        await user.click(screen.getByRole('switch', { name: /Shown/ }));
        await user.click(screen.getByRole('button', { name: 'Save article' }));

        expect(update).toHaveBeenCalledWith({
            path: { article: 'the-console' },
            body: {
                title: 'The console',
                category: 'Your server',
                summary: 'Commands and power buttons.',
                content: '# Our console',
                order: 60,
                enabled: false,
            },
        });
        expect(onSaved).toHaveBeenCalledWith('the-console');
    });

    it('resets a changed article after asking', async () => {
        const user = userEvent.setup();
        const onReset = vi.fn();

        render(<HelpArticleForm article={{ ...console, overridden: true }} onSaved={vi.fn()} onReset={onReset} />);

        expect(screen.getByText('Changed')).toBeVisible();
        await user.click(screen.getByRole('button', { name: 'Reset to default' }));
        await user.click(await screen.findByRole('button', { name: 'Reset to default' }));

        expect(reset).toHaveBeenCalledWith({ path: { article: 'the-console' } });
        expect(onReset).toHaveBeenCalled();
    });

    it('has nothing to reset on an untouched article', () => {
        render(<HelpArticleForm article={console} onSaved={vi.fn()} onReset={vi.fn()} />);

        expect(screen.queryByRole('button', { name: 'Reset to default' })).toBeNull();
    });

    it('adds an article at an address made from its title', async () => {
        const user = userEvent.setup();
        const onSaved = vi.fn();

        render(<HelpArticleForm article={null} onSaved={onSaved} onReset={vi.fn()} />);

        await user.type(screen.getByLabelText('Title'), 'Discord rules!');
        expect(screen.getByText('The article lives at /help/discord-rules.')).toBeVisible();
        fireEvent.change(screen.getByLabelText('Markdown'), { target: { value: '# Discord rules\n\nBe kind.' } });
        await user.click(screen.getByRole('button', { name: 'Add article' }));

        expect(update).toHaveBeenCalledWith({
            path: { article: 'discord-rules' },
            body: {
                title: 'Discord rules!',
                category: null,
                summary: null,
                content: '# Discord rules\n\nBe kind.',
                order: null,
                enabled: true,
            },
        });
        expect(onSaved).toHaveBeenCalledWith('discord-rules');
    });
});

describe('help article input', () => {
    it('sends blanks as null so a shipped article keeps its starter text', () => {
        expect(
            updateAdminHelpArticleInput({
                slug: 'a',
                title: '  ',
                category: '',
                summary: ' Short ',
                content: '',
                order: 'x',
                enabled: true,
            }).body
        ).toEqual({ title: null, category: null, summary: 'Short', content: null, order: null, enabled: true });
    });

    it('makes slugs from titles', () => {
        expect(slugify('  Café & Server — Rules! ')).toBe('cafe-server-rules');
        expect(slugify('a'.repeat(70))).toHaveLength(64);
    });
});
