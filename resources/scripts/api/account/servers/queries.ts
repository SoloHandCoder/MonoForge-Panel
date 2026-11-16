import { useCallback, useMemo, useSyncExternalStore } from 'react';
import {
    queryOptions,
    useQueries,
    useQuery,
    useQueryClient,
    type Query,
    type QueryClient,
    type QueryFilters,
    type QueryKey,
} from '@tanstack/react-query';

import {
    clientGetServerResourcesOptions,
    clientListClientServersOptions,
    clientListServerResourcesQueryKey,
} from '@/api/generated/@tanstack/react-query.gen';
import { clientListServerResources } from '@/api/generated';
import type {
    ClientAllocationResource,
    ClientStatsAttributes,
    ClientGetServerResourcesData,
    ClientGetServerResourcesResponse,
    ClientListClientServersData,
    ClientServerResource,
    ClientServerResourcesError,
    ClientStatsResource,
    Options,
} from '@/api/generated';
import { hasGeneratedOperationId } from '@/api/queryKeyGuards';
import { isObject, isString } from '@/lib/objects';

type ClientServerListQuery = NonNullable<ClientListClientServersData['query']>;
type ClientServerListType = ClientServerListQuery['type'];
export type AccountServer = ClientServerResource;
export type ServerPowerState = ClientStatsAttributes['current_state'];
export type { ClientAllocationResource, ClientStatsAttributes };

type ServerListParams = {
    page?: number;
    query?: string;
    type?: ClientServerListType;
};

const clientServersInput = ({ page, query, type }: ServerListParams = {}): Options<ClientListClientServersData> => {
    const queryParameters: ClientServerListQuery = { page, type };

    if (query) {
        queryParameters['filter[*]'] = query;
    }

    return { query: queryParameters };
};

export const accountServersQueryOptions = ({ page = 1, type }: ServerListParams = {}) =>
    clientListClientServersOptions(clientServersInput({ page, type }));

export const useAccountServers = (params: ServerListParams = {}) => useQuery(accountServersQueryOptions(params));

const accountServerSearchQueryOptions = ({ query = '', type }: ServerListParams = {}) =>
    clientListClientServersOptions(clientServersInput({ query, type }));

export const useAccountServerSearch = (params: ServerListParams = {}, enabled = true) =>
    useQuery({ ...accountServerSearchQueryOptions(params), enabled });

const serverResourcesInput = (uuid: string): Options<ClientGetServerResourcesData> => ({
    path: { server_uuid: uuid },
});

export const serverResourceUsageQueryOptions = (
    uuid: string
): ReturnType<typeof clientGetServerResourcesOptions> & { retry: false } => ({
    ...clientGetServerResourcesOptions(serverResourcesInput(uuid)),
    retry: false,
});

/** Wings refreshes usage at most every 20 seconds. */
export const serverResourceUsageRefetchInterval = (query: {
    state: { data?: ClientGetServerResourcesResponse };
}): number | false => (query.state.data?.attributes.is_suspended ? false : 30000);

/** The most servers one batched resources request may ask for; the API refuses more. */
export const serverResourceUsageBatchLimit = 50;

/** The server uuids a batched resources query asks for, read back from its key. */
const batchedUuids = (queryKey: QueryKey): readonly string[] => {
    if (!hasGeneratedOperationId(queryKey, 'clientListServerResources')) {
        return [];
    }

    const [root] = queryKey;
    const servers =
        isObject(root) && 'query' in root && isObject(root.query) ? Reflect.get(root.query, 'servers') : undefined;

    return isString(servers) && servers !== '' ? servers.split(',') : [];
};

export const isServerStatsEntry = (
    entry: ClientStatsResource | ClientServerResourcesError | undefined
): entry is ClientStatsResource => entry !== undefined && entry.object !== 'error';

/**
 * Whether the last batch could not read the server's usage from Wings (an `error` entry, such as a
 * node that does not answer). A card with no reading yet shows that instead of loading forever.
 * Only the batch writes it; reading it never sends a request.
 */
export const serverResourceUsageUnreachableQueryOptions = (uuid: string) =>
    queryOptions({
        queryKey: ['account', 'servers', uuid, 'resources-unreachable'] as const,
        queryFn: () => false,
        enabled: false,
        staleTime: Infinity,
    });

/**
 * One request for the usage of up to 50 servers, polled every 30 seconds and paused in a hidden tab.
 * Each server's reading is also written to its own resources query, so a card, its row and the
 * server page read the same cache entry.
 */
export const serverResourceUsageBatchQueryOptions = (queryClient: QueryClient, uuids: readonly string[]) => {
    const input = { query: { servers: uuids.join(',') } };

    return queryOptions({
        queryKey: clientListServerResourcesQueryKey(input),
        queryFn: async ({ signal }) => {
            const { data } = await clientListServerResources({ ...input, signal, throwOnError: true });

            for (const uuid of uuids) {
                const entry = data.data[uuid];

                // A server Wings could not report on keeps its last reading, as after a failed refetch.
                if (isServerStatsEntry(entry)) {
                    queryClient.setQueryData<ClientGetServerResourcesResponse>(
                        serverResourceUsageQueryOptions(uuid).queryKey,
                        entry
                    );
                }

                queryClient.setQueryData(
                    serverResourceUsageUnreachableQueryOptions(uuid).queryKey,
                    entry?.object === 'error'
                );
            }

            return data;
        },
        retry: false,
        refetchInterval: 30_000,
        // TanStack's default, stated: a hidden tab does not poll.
        refetchIntervalInBackground: false,
    });
};

/** Every batched resources query that asks for the server, to refresh its usage after a power action. */
export const serverResourceUsageBatchFilters = (uuid: string): QueryFilters => ({
    predicate: ({ queryKey }) => batchedUuids(queryKey).includes(uuid),
});

const chunked = (key: string): string[][] => {
    const uuids = key === '' ? [] : key.split(',');
    const chunks: string[][] = [];

    for (let index = 0; index < uuids.length; index += serverResourceUsageBatchLimit) {
        chunks.push(uuids.slice(index, index + serverResourceUsageBatchLimit));
    }

    return chunks;
};

/**
 * Polls the usage of a page of servers with one request per 50 servers instead of one per server.
 * Read each server's usage with useServerResourceUsage, which sends nothing of its own while a
 * batch covers it.
 */
export const useServerResourceUsageBatch = (uuids: readonly string[]): void => {
    const queryClient = useQueryClient();
    const key = uuids.join(',');
    const chunks = useMemo(() => chunked(key), [key]);

    useQueries({ queries: chunks.map((part) => serverResourceUsageBatchQueryOptions(queryClient, part)) });
};

/**
 * A batch in use covers its servers. One built during this render and not fetched yet counts too:
 * the list renders before its cards, so a card never starts a request of its own first.
 */
const coversServer = (query: Query, uuid: string): boolean =>
    (query.getObserversCount() > 0 ||
        (query.state.dataUpdatedAt === 0 && query.state.errorUpdatedAt === 0 && query.state.fetchStatus === 'idle')) &&
    batchedUuids(query.queryKey).includes(uuid);

const useCoveredByBatch = (uuid: string): boolean => {
    const cache = useQueryClient().getQueryCache();
    const subscribe = useCallback((notify: () => void) => cache.subscribe(notify), [cache]);
    const covered = useCallback(() => cache.getAll().some((query) => coversServer(query, uuid)), [cache, uuid]);

    return useSyncExternalStore(subscribe, covered, covered);
};

/**
 * A server's usage, polled every 30 seconds. While a batch from useServerResourceUsageBatch covers
 * the server, as on the dashboard, this only reads what the batch writes and sends no request.
 */
export const useServerResourceUsage = (uuid: string, enabled = true) => {
    const batched = useCoveredByBatch(uuid);

    return useQuery({
        ...serverResourceUsageQueryOptions(uuid),
        enabled: enabled && uuid.length > 0 && !batched,
        refetchInterval: serverResourceUsageRefetchInterval,
    });
};
