import { queryOptions, useQuery } from '@tanstack/react-query';
import { apiGetPublicStatusOptions } from '@/api/generated/@tanstack/react-query.gen';
import type { ApiGetPublicStatusResponse } from '@/api/generated';

export type PublicStatus = ApiGetPublicStatusResponse;
export type OverallState = PublicStatus['state'];
export type StatusComponent = PublicStatus['nodes'][number];
export type ComponentState = StatusComponent['status'];
export type StatusDay = StatusComponent['days'][number];
export type StatusIncident = PublicStatus['incidents'][number];

/** The server caches the status for 30 seconds; the page asks again every minute while it is open. */
export const STATUS_POLL_INTERVAL = 60_000;

export const publicStatusQueryOptions = () =>
    queryOptions({
        ...apiGetPublicStatusOptions(),
        refetchInterval: STATUS_POLL_INTERVAL,
        staleTime: STATUS_POLL_INTERVAL / 2,
        // A turned-off status page answers 404; asking again will not change that.
        retry: false,
    });

export const usePublicStatus = () => useQuery(publicStatusQueryOptions());
