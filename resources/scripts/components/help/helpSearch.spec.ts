import { describe, expect, it } from 'vitest';
import type { HelpArticle } from '@/api/help';
import { helpCategories, toHelpArticles } from '@/api/help';
import { MAX_QUERY, normalize, plainText, queryTerms, searchHelp } from './helpSearch';

const article = (
    slug: string,
    title: string,
    content: string,
    summary = '',
    category = 'Getting started'
): HelpArticle => ({
    slug,
    title,
    category,
    summary,
    content,
});

const articles: HelpArticle[] = [
    article(
        'joining',
        'Joining your server',
        '# Joining\n\nEvery server gets a **playit.gg** address. "Address pending" means the tunnel is not ready.'
    ),
    article(
        'backups',
        'Backups and schedules',
        '# Backups\n\nLock a backup to keep it. Schedules can restart the server.',
        'Make and restore backups.',
        'Your server'
    ),
    article(
        'wallet',
        'Tokens and the Wallet',
        '# Tokens\n\nTokens never expire. Buy packs through [Tebex](https://tebex.io).',
        '',
        'Tokens and billing'
    ),
    article('cafe', 'Café rules', '# Café\n\nCoffee is served.', '', 'More help'),
];

describe('plainText', () => {
    it('drops markdown markers and keeps link labels', () => {
        expect(
            plainText('# Title\n\n- **Bold** and *em* with `code`\n> quote\n1. [Tebex](https://tebex.io) item')
        ).toBe('Title Bold and em with code quote Tebex item');
    });
});

describe('queryTerms', () => {
    it('splits on anything but letters and numbers, lower case and without accents', () => {
        expect(queryTerms('  Address-PENDING, Café! ')).toEqual(['address', 'pending', 'cafe']);
        expect(normalize('Été')).toBe('ete');
    });

    it('ignores what is past the longest query', () => {
        expect(queryTerms(`${'a'.repeat(MAX_QUERY)} extra`)).toEqual(['a'.repeat(MAX_QUERY)]);
    });
});

describe('searchHelp', () => {
    it('finds nothing for an empty query', () => {
        expect(searchHelp(articles, '   ')).toEqual([]);
    });

    it('searches titles, summaries and the text', () => {
        expect(searchHelp(articles, 'address pending').map((result) => result.article.slug)).toEqual(['joining']);
        expect(searchHelp(articles, 'restore').map((result) => result.article.slug)).toEqual(['backups']);
        expect(searchHelp(articles, 'expire').map((result) => result.article.slug)).toEqual(['wallet']);
        // A link's label is text; its address is not.
        expect(searchHelp(articles, 'tebex').map((result) => result.article.slug)).toEqual(['wallet']);
        expect(searchHelp(articles, 'https')).toEqual([]);
    });

    it('needs every word, matches the start of words and ignores accents', () => {
        expect(searchHelp(articles, 'backup restart').map((result) => result.article.slug)).toEqual(['backups']);
        expect(searchHelp(articles, 'backup tebex')).toEqual([]);
        expect(searchHelp(articles, 'sched').map((result) => result.article.slug)).toEqual(['backups']);
        expect(searchHelp(articles, 'ckup')).toEqual([]);
        expect(searchHelp(articles, 'cafe').map((result) => result.article.slug)).toEqual(['cafe']);
    });

    it('ranks a title match above a match in the text', () => {
        expect(searchHelp(articles, 'server').map((result) => result.article.slug)).toEqual(['joining', 'backups']);
        expect(searchHelp(articles, 'backups')[0]?.article.slug).toBe('backups');
    });

    it('shows the text around the match, or the summary for a title match', () => {
        const [joining] = searchHelp(articles, 'tunnel');

        expect(joining?.snippet).toContain('the tunnel is not ready');
        expect(joining?.snippet).not.toContain('**');
        expect(searchHelp(articles, 'backups')[0]?.snippet).toBe('Make and restore backups.');
    });

    it('treats markup in an article as plain text', () => {
        const hostile = article('x', 'Rules', '<script>alert(1)</script> <img src=x onerror=alert(2)>');

        expect(searchHelp([hostile], 'script')[0]?.snippet).toBe(
            '<script>alert(1)</script> <img src=x onerror=alert(2)>'
        );
    });
});

describe('helpCategories', () => {
    it('groups the articles by category in the order they came', () => {
        expect(helpCategories(articles).map((category) => [category.name, category.articles.length])).toEqual([
            ['Getting started', 1],
            ['Your server', 1],
            ['Tokens and billing', 1],
            ['More help', 1],
        ]);
    });

    it('fills in missing fields and drops rows without a slug', () => {
        expect(toHelpArticles([{ slug: 'a' }, { title: 'No slug' }])).toEqual([
            { slug: 'a', title: 'a', category: 'More help', summary: '', content: '' },
        ]);
    });
});
