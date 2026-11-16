import { keepPreviousData, queryOptions, useQuery } from '@tanstack/react-query';
import { apiListDiscoverableServerPagesOptions } from '@/api/generated/@tanstack/react-query.gen';
import type { ApiListDiscoverableServerPagesData, ApiListDiscoverableServerPagesResponse } from '@/api/generated';

export type ServerDiscoveryList = ApiListDiscoverableServerPagesResponse;
export type DiscoveredServer = ServerDiscoveryList['data'][number];
export type DiscoverySort = NonNullable<NonNullable<ApiListDiscoverableServerPagesData['query']>['sort']>;

export const DISCOVERY_SORTS: { value: DiscoverySort; label: string }[] = [
    { value: 'players', label: 'Most players online' },
    { value: 'votes', label: 'Most votes' },
    { value: 'newest', label: 'Newest' },
];

export const DISCOVERY_PER_PAGE = 24;
export const DISCOVERY_SEARCH_MAX = 64;

export interface DiscoveryFilters {
    game?: string;
    search?: string;
    sort: DiscoverySort;
    page: number;
}

/** Only the filters that are set, so an empty search is not sent and every key is stable. */
export const discoveryQuery = (filters: DiscoveryFilters): NonNullable<ApiListDiscoverableServerPagesData['query']> => {
    const query: NonNullable<ApiListDiscoverableServerPagesData['query']> = { sort: filters.sort, page: filters.page };
    const search = filters.search?.trim().slice(0, DISCOVERY_SEARCH_MAX);

    if (filters.game) {
        query.game = filters.game;
    }

    if (search) {
        query.search = search;
    }

    return query;
};

/** The panel caches a list for 30 seconds; the open page asks again every minute. */
export const serverDiscoveryQueryOptions = (filters: DiscoveryFilters) =>
    queryOptions({
        ...apiListDiscoverableServerPagesOptions({ query: discoveryQuery(filters) }),
        staleTime: 30_000,
        refetchInterval: 60_000,
        placeholderData: keepPreviousData,
    });

export const useServerDiscovery = (filters: DiscoveryFilters) => useQuery(serverDiscoveryQueryOptions(filters));
