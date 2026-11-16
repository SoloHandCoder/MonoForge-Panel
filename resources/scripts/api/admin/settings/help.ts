import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import {
    adminListAdminGuidesOptions,
    adminListAdminGuidesQueryKey,
    adminListHelpArticlesOptions,
    adminListHelpArticlesQueryKey,
    adminResetHelpArticleMutation,
    adminUpdateHelpArticleMutation,
    apiListPublicHelpArticlesQueryKey,
} from '@/api/generated/@tanstack/react-query.gen';
import type {
    AdminListAdminGuidesResponse,
    AdminListHelpArticlesResponse,
    AdminUpdateHelpArticleResponse,
} from '@/api/generated';
import { toHelpArticles, type HelpArticle } from '@/api/help';
import { notifyHttpError } from '@/plugins/notifications';

/** A help article as the admin editor sees it. */
export interface AdminHelpArticle extends HelpArticle {
    order: number;
    /** `admin`: a guide for root administrators, read under Admin > Help. */
    audience: 'public' | 'admin';
    enabled: boolean;
    /** Ships with the panel; a reset brings back the starter text. Added articles are deleted by a reset. */
    shipped: boolean;
    /** The administrator changed it. */
    overridden: boolean;
    publicUrl: string;
}

type ArticleResponse = AdminUpdateHelpArticleResponse;

export const toAdminHelpArticle = (row: ArticleResponse): AdminHelpArticle | null => {
    const [article] = toHelpArticles([row]);

    return article
        ? {
              ...article,
              order: row.order ?? 0,
              audience: row.audience === 'admin' ? 'admin' : 'public',
              enabled: row.enabled ?? true,
              shipped: row.shipped ?? false,
              overridden: row.overridden ?? false,
              publicUrl: row.public_url ?? `/help/${article.slug}`,
          }
        : null;
};

export const toAdminHelpArticles = (response: AdminListHelpArticlesResponse): AdminHelpArticle[] =>
    (response.data ?? []).map(toAdminHelpArticle).filter((entry): entry is AdminHelpArticle => entry !== null);

export const adminHelpArticlesQueryOptions = () => adminListHelpArticlesOptions();

export const useAdminHelpArticles = () => useQuery({ ...adminHelpArticlesQueryOptions(), select: toAdminHelpArticles });

/** The guides for running the panel (Admin > Help). */
export const adminGuidesQueryOptions = () => adminListAdminGuidesOptions();

export const useAdminGuides = () =>
    useQuery({
        ...adminGuidesQueryOptions(),
        select: (response: AdminListAdminGuidesResponse): HelpArticle[] => toHelpArticles(response.data),
    });

export interface HelpArticleValues {
    slug: string;
    title: string;
    category: string;
    summary: string;
    content: string;
    /** Empty keeps the shipped place (or puts an added article last). */
    order: string;
    enabled: boolean;
}

const blankToNull = (value: string): string | null => (value.trim() === '' ? null : value);

export const updateAdminHelpArticleInput = (values: HelpArticleValues) => {
    const order = values.order.trim() === '' ? null : Number(values.order);

    return {
        path: { article: values.slug },
        body: {
            title: blankToNull(values.title.trim()),
            category: blankToNull(values.category.trim()),
            summary: blankToNull(values.summary.trim()),
            content: blankToNull(values.content),
            order: order !== null && Number.isInteger(order) ? order : null,
            enabled: values.enabled,
        },
    };
};

/** A slug made from a title: "Discord rules!" becomes "discord-rules". */
export const slugify = (title: string): string =>
    title
        .normalize('NFD')
        .replaceAll(/\p{M}/gu, '')
        .toLowerCase()
        .replaceAll(/[^a-z0-9]+/g, '-')
        .replaceAll(/^-+|-+$/g, '')
        .slice(0, 64)
        .replace(/-+$/, '');

export const HELP_SLUG_PATTERN = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

const useInvalidateHelp = () => {
    const queryClient = useQueryClient();

    return () =>
        Promise.all([
            queryClient.invalidateQueries({ queryKey: adminListHelpArticlesQueryKey() }),
            queryClient.invalidateQueries({ queryKey: adminListAdminGuidesQueryKey() }),
            queryClient.invalidateQueries({ queryKey: apiListPublicHelpArticlesQueryKey() }),
        ]);
};

export const useUpdateAdminHelpArticle = () => {
    const invalidate = useInvalidateHelp();

    return useMutation({
        ...adminUpdateHelpArticleMutation(),
        onSuccess: async (response) => {
            await invalidate();
            toast.success(`${response.title ?? 'Article'} saved`);
        },
        onError: (error) => notifyHttpError(error, 'Unable to save the article'),
    });
};

export const useResetAdminHelpArticle = () => {
    const invalidate = useInvalidateHelp();

    return useMutation({
        ...adminResetHelpArticleMutation(),
        onSuccess: async () => {
            await invalidate();
        },
        onError: (error) => notifyHttpError(error, 'Unable to reset the article'),
    });
};
