// Token packs sold through Tebex (phase 3 Task P), written by hand until the generated API client is
// regenerated with GET /api/client/store/tokens/packs and POST /api/client/store/tokens/checkout.
/* oxlint-disable no-restricted-properties, rules/generated-api-mutations */
import { queryOptions, useMutation, useQuery } from '@tanstack/react-query';
import http from '@/api/http';
import { notifyHttpError } from '@/plugins/notifications';

export interface TokenPack {
    id: number;
    name: string;
    tokens: number;
    bonus_tokens: number;
}

export interface TokenPackList {
    /** False until an administrator connects a Tebex store. */
    enabled: boolean;
    data: TokenPack[];
}

export const tokenPacksQueryOptions = () =>
    queryOptions({
        queryKey: ['store', 'tokens', 'packs'] as const,
        queryFn: async ({ signal }): Promise<TokenPackList> =>
            (await http.get<TokenPackList>('/api/client/store/tokens/packs', { signal })).data,
    });

export const useTokenPacks = () => useQuery(tokenPacksQueryOptions());

/** Starts a Tebex checkout for one pack; resolves to the Tebex page to send the player to. */
export const useTokenPackCheckout = () =>
    useMutation({
        mutationFn: async (packId: number): Promise<string> =>
            (await http.post<{ url: string }>('/api/client/store/tokens/checkout', { pack_id: packId })).data.url,
        onError: (error) => notifyHttpError(error, 'Unable to start the checkout'),
    });

/** Only ever leave the panel for an https page on Tebex's own domain. */
export const isTebexCheckoutUrl = (url: string): boolean => {
    try {
        const parsed = new URL(url);
        const host = parsed.hostname.toLowerCase();

        return parsed.protocol === 'https:' && (host === 'tebex.io' || host.endsWith('.tebex.io'));
    } catch {
        return false;
    }
};
