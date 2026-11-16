import type { HelpArticle } from '@/api/help';

export interface HelpSearchResult {
    article: HelpArticle;
    /** A piece of the article's text around the first match, or its summary. */
    snippet: string;
}

/** The longest query searched; anything after it is ignored. */
export const MAX_QUERY = 100;

const SNIPPET_LENGTH = 160;

/** Markdown as plain text: links keep their label, markers and line breaks go. */
export function plainText(markdown: string): string {
    return markdown
        .replaceAll(/\[([^\]]*)\]\([^)\s]*\)/g, '$1')
        .replaceAll(/^\s{0,3}(?:#{1,6}\s+|>\s?|[-*+]\s+|\d+[.)]\s+)/gm, '')
        .replaceAll(/[*_`~]+/g, '')
        .replaceAll(/\s+/g, ' ')
        .trim();
}

/** Lower case without accents, so "Café" matches "cafe". */
export function normalize(text: string): string {
    return text.normalize('NFD').replaceAll(/\p{M}/gu, '').toLowerCase();
}

export function queryTerms(query: string): string[] {
    return normalize(query.slice(0, MAX_QUERY))
        .split(/[^\p{L}\p{N}]+/u)
        .filter((term) => term.length > 0);
}

interface Indexed {
    article: HelpArticle;
    title: string;
    summary: string;
    body: string;
    /** The body's plain text as written, for snippets. */
    text: string;
}

const indexCache = new WeakMap<HelpArticle, Indexed>();

function indexed(article: HelpArticle): Indexed {
    const known = indexCache.get(article);

    if (known) {
        return known;
    }

    const text = plainText(article.content);
    const entry = {
        article,
        title: normalize(article.title),
        summary: normalize(article.summary),
        body: normalize(text),
        text,
    };

    indexCache.set(article, entry);

    return entry;
}

/** A word in the text starting with the term ("back" finds "backups"). */
function hasWord(text: string, term: string): boolean {
    let from = text.indexOf(term);

    while (from !== -1) {
        if (from === 0 || !/[\p{L}\p{N}]/u.test(text.charAt(from - 1))) {
            return true;
        }

        from = text.indexOf(term, from + 1);
    }

    return false;
}

function score(entry: Indexed, terms: readonly string[]): number {
    let total = 0;

    for (const term of terms) {
        const inTitle = hasWord(entry.title, term);
        const inSummary = hasWord(entry.summary, term);
        const inBody = hasWord(entry.body, term);

        if (!inTitle && !inSummary && !inBody) {
            return 0;
        }

        total += (inTitle ? 10 : 0) + (inSummary ? 4 : 0) + (inBody ? 1 : 0);
    }

    return total;
}

function snippet(entry: Indexed, terms: readonly string[]): string {
    // The normalized body keeps the length of the text for ordinary letters, so positions line up.
    const position = entry.body.length === entry.text.length ? entry.body.indexOf(terms[0] ?? '') : -1;

    if (position === -1 || hasWord(entry.title, terms[0] ?? '')) {
        return entry.article.summary || entry.text.slice(0, SNIPPET_LENGTH);
    }

    const start = Math.max(0, position - 40);
    const piece = entry.text.slice(start, start + SNIPPET_LENGTH).trim();

    return `${start > 0 ? '…' : ''}${piece}${start + SNIPPET_LENGTH < entry.text.length ? '…' : ''}`;
}

/**
 * The articles that hold every word of the query in their title, summary or text, best first:
 * a title match counts most, then the summary, then the text. Ties keep the help center order.
 */
export function searchHelp(articles: readonly HelpArticle[], query: string): HelpSearchResult[] {
    const terms = queryTerms(query);

    if (terms.length === 0) {
        return [];
    }

    return articles
        .map((article, order) => {
            const entry = indexed(article);

            return { entry, order, score: score(entry, terms) };
        })
        .filter((match) => match.score > 0)
        .sort((a, b) => b.score - a.score || a.order - b.order)
        .map(({ entry }) => ({ article: entry.article, snippet: snippet(entry, terms) }));
}
