import { keepPreviousData, queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { clientPurchaseServerMutation } from '@/api/generated/@tanstack/react-query.gen';
import http from '@/api/http';
import { invalidateGeneratedOperations } from '@/api/mutationUtils';
import {
    mapCatalog,
    mapQuote,
    type Envelope,
    type RawStoreCatalog,
    type RawStoreQuoteResponse,
    type StoreCatalog,
    type StoreQuote,
    type StoreSelection,
} from '@/api/store/types';

export const storeQueryKey = ['store'] as const;

/** Where Task Q's Wallet page lives, linked when the balance is short. */
export const WALLET_PATH = '/wallet';

/** The selection as the quote and purchase endpoints take it. */
export const selectionBody = (selection: StoreSelection) => ({
    plan_id: selection.planId,
    period_id: selection.periodId,
    egg_id: selection.eggId,
    location_id: selection.locationId,
    node_id: selection.nodeId,
    addons: selection.addons,
});

// The catalog and quote endpoints belong to Task R and are not in the generated client on this
// branch; once the client is regenerated after the merge these two calls can use it.
export const storeCatalogQueryOptions = () =>
    queryOptions({
        queryKey: [...storeQueryKey, 'catalog'],
        queryFn: async ({ signal }): Promise<StoreCatalog> =>
            mapCatalog(
                // oxlint-disable-next-line no-restricted-properties
                (await http.get<Envelope<RawStoreCatalog>>('/api/client/store/catalog', { signal })).data
            ),
        staleTime: 30_000,
    });

export const useStoreCatalog = () => useQuery(storeCatalogQueryOptions());

async function fetchQuote(selection: StoreSelection, signal: AbortSignal): Promise<StoreQuote> {
    const body = selectionBody(selection);
    // oxlint-disable-next-line no-restricted-properties
    const response = await http.post<Envelope<RawStoreQuoteResponse>>('/api/client/store/quote', body, { signal });

    return mapQuote(response.data);
}

/** The server's quote for a selection; the previous quote stays on screen while the next one loads. */
export const useStoreQuote = (selection: StoreSelection | null) =>
    useQuery({
        queryKey: [...storeQueryKey, 'quote', selection],
        queryFn: async ({ signal }): Promise<StoreQuote | null> => (selection ? fetchQuote(selection, signal) : null),
        enabled: selection !== null,
        placeholderData: keepPreviousData,
        staleTime: 10_000,
        retry: false,
    });

export const usePurchaseServer = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...clientPurchaseServerMutation(),
        onSettled: async () => {
            // The balance, the capacity and the server list all changed.
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: storeQueryKey }),
                invalidateGeneratedOperations(queryClient, ['clientListClientServers']),
            ]);
        },
    });
};

/** A fresh key per checkout attempt, so a retried request never buys twice. */
export const newIdempotencyKey = (): string => globalThis.crypto.randomUUID();
