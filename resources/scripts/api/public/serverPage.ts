import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import {
    apiGetAPublicServerPageOptions,
    apiGetAPublicServerPageQueryKey,
    apiVoteForAPublicServerPageMutation,
    apiWakeASleepingServerMutation,
} from '@/api/generated/@tanstack/react-query.gen';
import type { ApiGetAPublicServerPageResponse } from '@/api/generated';

export type PublicServerPage = ApiGetAPublicServerPageResponse;
export type PublicServerStatus = PublicServerPage['status'];

/** The panel caches a page for 30 seconds; an open page asks again every minute, and every 10 s while waking. */
export const PAGE_POLL_INTERVAL = 60_000;
export const WAKING_POLL_INTERVAL = 10_000;

const pageInput = (slug: string) => ({ path: { slug } });

export const publicServerPageQueryOptions = (slug: string, waking = false) =>
    queryOptions({
        ...apiGetAPublicServerPageOptions(pageInput(slug)),
        refetchInterval: waking ? WAKING_POLL_INTERVAL : PAGE_POLL_INTERVAL,
        staleTime: PAGE_POLL_INTERVAL / 2,
        // An unknown or unpublished page answers 404; asking again will not change that.
        retry: (count, error) => !(isAxiosError(error) && error.response?.status === 404) && count < 2,
    });

export const usePublicServerPage = (slug: string, waking = false) =>
    useQuery(publicServerPageQueryOptions(slug, waking));

export const useVoteForServer = (slug: string) => {
    const queryClient = useQueryClient();

    return useMutation({
        ...apiVoteForAPublicServerPageMutation(),
        onSuccess: (result) => {
            queryClient.setQueryData(apiGetAPublicServerPageQueryKey(pageInput(slug)), (page) =>
                page ? { ...page, votes: result.votes } : page
            );
        },
    });
};

export const useWakeServer = () => useMutation(apiWakeASleepingServerMutation());

export const publicServerPageInput = pageInput;

/** The seconds a refused wake says to wait, from its Retry-After header. */
export const retryAfterSeconds = (error: Error): number | null => {
    if (!isAxiosError(error) || error.response?.status !== 429) {
        return null;
    }

    const value = Number(error.response.headers['retry-after']);

    return Number.isFinite(value) && value > 0 ? Math.ceil(value) : 60;
};
