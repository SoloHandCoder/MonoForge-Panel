import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import {
    clientDeleteServerPublicPageBannerMutation,
    clientGetServerPublicPageOptions,
    clientGetServerPublicPageQueryKey,
    clientUpdateServerPublicPageMutation,
    clientUploadServerPublicPageBannerMutation,
} from '@/api/generated/@tanstack/react-query.gen';
import type { ClientGetServerPublicPageResponse, ClientUpdateServerPublicPageRequest } from '@/api/generated';
import { notifyHttpError } from '@/plugins/notifications';

export type ServerPageSettings = ClientGetServerPublicPageResponse;
export type ServerPageInput = ClientUpdateServerPublicPageRequest;

/** The rules the panel checks for the part of the address after /s/. */
export const SLUG_MIN = 3;
export const SLUG_MAX = 32;
export const DESCRIPTION_MAX = 2000;
export const TAGLINE_MAX = 80;
export const LINK_MAX = 255;
export const BANNER_MAX_BYTES = 1024 * 1024;
export const BANNER_TYPES = ['image/png', 'image/jpeg', 'image/webp'] as const;

const slugPattern = /^[a-z0-9](?:[a-z0-9]|-(?!-))*[a-z0-9]$/;

/** The first problem with a slug, checked before it is sent; the panel also checks it is free and not reserved. */
export const slugProblem = (slug: string): string | null => {
    if (slug.length < SLUG_MIN || slug.length > SLUG_MAX) {
        return `Use ${SLUG_MIN} to ${SLUG_MAX} characters.`;
    }

    return slugPattern.test(slug)
        ? null
        : 'Use lowercase letters, numbers and single hyphens, starting and ending with a letter or number.';
};

const DISCORD_HOSTS = new Set(['discord.gg', 'discord.com', 'www.discord.com']);

const httpsUrl = (value: string): URL | null => {
    if (value.length > LINK_MAX || /[\s<>"'\\]/.test(value)) {
        return null;
    }

    try {
        const url = new URL(value);

        return url.protocol === 'https:' && !url.username && !url.password && !url.port && url.hostname.includes('.')
            ? url
            : null;
    } catch {
        return null;
    }
};

/** The first problem with a social link, checked before it is sent; the panel checks it again. */
export const linkProblem = (kind: 'discord' | 'website', value: string): string | null => {
    if (value.trim() === '') {
        return null;
    }

    const url = httpsUrl(value.trim());

    if (kind === 'website') {
        return url ? null : 'Use an https address, such as https://example.com.';
    }

    const invite =
        url &&
        DISCORD_HOSTS.has(url.hostname) &&
        (url.hostname === 'discord.gg'
            ? /^\/[A-Za-z0-9-]{2,32}\/?$/.test(url.pathname)
            : /^\/invite\/[A-Za-z0-9-]{2,32}\/?$/.test(url.pathname));

    return invite ? null : 'Use a Discord invite link, such as https://discord.gg/abc123.';
};

/** A suggested slug from the server name: "My Skyblock!" becomes "my-skyblock". */
export const suggestSlug = (name: string): string =>
    name
        .normalize('NFKD')
        .replaceAll(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replaceAll(/[^a-z0-9]+/g, '-')
        .replaceAll(/^-+|-+$/g, '')
        .slice(0, SLUG_MAX)
        .replace(/-+$/, '');

const pageInput = (uuid: string) => ({ path: { server_uuid: uuid } });

export const serverPageQueryOptions = (uuid: string) => queryOptions(clientGetServerPublicPageOptions(pageInput(uuid)));

export const useServerPage = (uuid: string) => useQuery(serverPageQueryOptions(uuid));

/** Every change answers with the saved settings, which replace the cached ones. */
const useStoreSettings = (uuid: string) => {
    const queryClient = useQueryClient();

    return (settings: ServerPageSettings) =>
        queryClient.setQueryData(clientGetServerPublicPageQueryKey(pageInput(uuid)), settings);
};

export const useSaveServerPage = (uuid: string) => {
    const store = useStoreSettings(uuid);

    return useMutation({
        ...clientUpdateServerPublicPageMutation(),
        onSuccess: (settings) => {
            store(settings);
            toast.success(settings.published ? 'Public page saved.' : 'Public page saved and hidden.');
        },
    });
};

export const saveServerPageInput = (uuid: string, body: ServerPageInput) => ({ ...pageInput(uuid), body });

export const useUploadServerPageBanner = (uuid: string) => {
    const store = useStoreSettings(uuid);

    return useMutation({
        ...clientUploadServerPublicPageBannerMutation(),
        onSuccess: (settings) => {
            store(settings);
            toast.success('Banner uploaded.');
        },
        onError: (error) => notifyHttpError(error, 'Unable to upload the banner'),
    });
};

export const uploadServerPageBannerInput = (uuid: string, banner: File) => ({ ...pageInput(uuid), body: { banner } });

export const useDeleteServerPageBanner = (uuid: string) => {
    const store = useStoreSettings(uuid);

    return useMutation({
        ...clientDeleteServerPublicPageBannerMutation(),
        onSuccess: (settings) => {
            store(settings);
            toast.success('Banner removed.');
        },
        onError: (error) => notifyHttpError(error, 'Unable to remove the banner'),
    });
};

export const deleteServerPageBannerInput = pageInput;
