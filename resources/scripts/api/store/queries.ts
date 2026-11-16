import { keepPreviousData, queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { clientGetTheStoreCatalog, clientQuoteAServer } from '@/api/generated';
import { clientPurchaseServerMutation } from '@/api/generated/@tanstack/react-query.gen';
import { invalidateGeneratedOperations } from '@/api/mutationUtils';
import {
    mapCatalog,
    mapQuote,
    type RawStoreCatalog,
    type RawStoreQuoteResponse,
    type StoreCatalog,
    type StoreQuote,
    type StoreSelection,
} from '@/api/store/types';

export const storeQueryKey = ['store'] as const;

/** The selection as the quote and purchase endpoints take it. */
export const selectionBody = (selection: StoreSelection) => ({
    plan_id: selection.planId,
    period_id: selection.periodId,
    egg_id: selection.eggId,
    location_id: selection.locationId,
    node_id: selection.nodeId,
    addons: selection.addons,
    coupon: selection.coupon ?? null,
});

// The generated response types mark every field optional (they come from examples), so the payloads
// are read through the exact shapes in types.ts.
export const storeCatalogQueryOptions = () =>
    queryOptions({
        queryKey: [...storeQueryKey, 'catalog'],
        queryFn: async ({ signal }): Promise<StoreCatalog> =>
            mapCatalog((await clientGetTheStoreCatalog({ signal })).data as RawStoreCatalog),
        staleTime: 30_000,
    });

export const useStoreCatalog = () => useQuery(storeCatalogQueryOptions());

async function fetchQuote(selection: StoreSelection, signal: AbortSignal): Promise<StoreQuote> {
    const { data } = await clientQuoteAServer({ body: selectionBody(selection), signal });

    return mapQuote(data as RawStoreQuoteResponse);
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
