import { queryOptions, useQuery } from '@tanstack/react-query';
import { apiListPublicHelpArticlesOptions } from '@/api/generated/@tanstack/react-query.gen';
import type { ApiListPublicHelpArticlesResponse } from '@/api/generated';

/** A help center article as the public pages show it. */
export interface HelpArticle {
    slug: string;
    title: string;
    category: string;
    /** One sentence for the list and the search description; may be empty. */
    summary: string;
    /** The article as markdown, rendered with the safe renderer. */
    content: string;
}

export interface HelpCategory {
    name: string;
    articles: HelpArticle[];
}

type ArticleRow = Partial<Record<keyof HelpArticle, string>>;

/** A row of a help API response with every field filled in; null without a slug. */
export const toHelpArticle = (row: ArticleRow): HelpArticle | null =>
    row.slug
        ? {
              slug: row.slug,
              title: row.title || row.slug,
              category: row.category || 'More help',
              summary: row.summary ?? '',
              content: row.content ?? '',
          }
        : null;

export const toHelpArticles = (rows: readonly ArticleRow[] | undefined): HelpArticle[] =>
    (rows ?? []).map(toHelpArticle).filter((article): article is HelpArticle => article !== null);

/** The articles under their categories, in the order the server sent them (help center order). */
export function helpCategories(articles: readonly HelpArticle[]): HelpCategory[] {
    const categories = new Map<string, HelpArticle[]>();

    for (const article of articles) {
        const list = categories.get(article.category);

        if (list) {
            list.push(article);
        } else {
            categories.set(article.category, [article]);
        }
    }

    return [...categories].map(([name, list]) => ({ name, articles: list }));
}

const selectArticles = (response: ApiListPublicHelpArticlesResponse): HelpArticle[] =>
    toHelpArticles(response.articles);

/** The help center's articles for players, from the public help API (no session needed). */
export const publicHelpQueryOptions = () =>
    queryOptions({
        ...apiListPublicHelpArticlesOptions(),
        staleTime: 60_000,
    });

export const usePublicHelp = () => useQuery({ ...publicHelpQueryOptions(), select: selectArticles });
