// Token packs sold through Tebex (phase 3 Task P), through the generated API client.
import { queryOptions, useMutation, useQuery } from '@tanstack/react-query';
import { clientListTokenPacks } from '@/api/generated';
import { clientBuyTokenPackMutation } from '@/api/generated/@tanstack/react-query.gen';
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
            (await clientListTokenPacks({ signal })).data as TokenPackList,
    });

export const useTokenPacks = () => useQuery(tokenPacksQueryOptions());

export interface TokenPackCheckout {
    /** The Tebex page to send the player to. */
    url: string;
    /** The panel's reference for the checkout, to follow the payment on return. */
    checkout: string;
}

/** Starts a Tebex checkout for one pack; resolves to the Tebex page and the checkout reference. */
export const useTokenPackCheckout = () => {
    const mutation = useMutation({
        ...clientBuyTokenPackMutation(),
        onError: (error) => notifyHttpError(error, 'Unable to start the checkout'),
    });

    return {
        ...mutation,
        mutateAsync: async (packId: number): Promise<TokenPackCheckout> => {
            const { url, checkout } = await mutation.mutateAsync({ body: { pack_id: packId } });

            return { url, checkout };
        },
    };
};

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
