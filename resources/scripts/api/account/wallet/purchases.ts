// The return from a Tebex token checkout (phase 5 Task T): what Tebex confirmed for one of the
// user's own checkouts, through the generated API client.
import { queryOptions, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect } from 'react';
import { clientGetTokenPurchase, type ClientGetTokenPurchaseResponseBody } from '@/api/generated';
import { walletQueryKey } from '@/api/account/wallet/queries';

export type TokenPurchase = ClientGetTokenPurchaseResponseBody['data'];

export type TokenPurchaseStatus = TokenPurchase['status'];

/** How often the return page asks while the payment is unconfirmed, and how long it keeps asking. */
export const PURCHASE_POLL_MS = 2_000;
export const PURCHASE_SLOW_POLL_MS = 10_000;
export const PURCHASE_SLOW_AFTER_MS = 60_000;
export const PURCHASE_GIVE_UP_MS = 30 * 60_000;

/** States after which Tebex sends nothing the page waits for. */
export const isSettled = (status: TokenPurchaseStatus): boolean => status !== 'pending';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/** Only a UUID is ever sent to the server as a checkout reference. */
export const isCheckoutReference = (value: string | null): value is string => value !== null && UUID.test(value);

const STORAGE_KEY = 'monoforge:tebex-checkout';

/** Remembers the checkout before leaving for Tebex, in case Tebex drops the return URL's query. */
export const rememberCheckout = (reference: string): void => {
    try {
        window.sessionStorage.setItem(STORAGE_KEY, reference);
    } catch {
        // Storage can be blocked; the return URL still carries the reference.
    }
};

export const forgetCheckout = (): void => {
    try {
        window.sessionStorage.removeItem(STORAGE_KEY);
    } catch {
        // Nothing to forget.
    }
};

export type CheckoutReturn = { outcome: 'complete' | 'cancelled'; reference: string | null };

/**
 * What Tebex sent the player back with: `?tebex=complete|cancelled&checkout=<uuid>`. The
 * reference only picks which of the user's own checkouts to ask the server about; nothing on the
 * page comes from the query.
 */
export const readCheckoutReturn = (search: string): CheckoutReturn | null => {
    const params = new URLSearchParams(search);
    const outcome = params.get('tebex');

    if (outcome !== 'complete' && outcome !== 'cancelled') {
        return null;
    }

    let reference: string | null = params.get('checkout');

    if (!isCheckoutReference(reference)) {
        try {
            reference = window.sessionStorage.getItem(STORAGE_KEY);
        } catch {
            reference = null;
        }
    }

    return { outcome, reference: isCheckoutReference(reference) ? reference : null };
};

export const tokenPurchaseQueryKey = (reference: string) => ['account', 'wallet-purchases', reference] as const;

/**
 * The checkout's state, asked every 2 s while it is pending, every 10 s after the first minute,
 * and no more once it settles or after half an hour.
 */
export const tokenPurchaseQueryOptions = (reference: string, startedAt: number, poll = true) =>
    queryOptions({
        queryKey: tokenPurchaseQueryKey(reference),
        queryFn: async ({ signal }): Promise<TokenPurchase> =>
            (await clientGetTokenPurchase({ path: { checkout: reference }, signal })).data.data,
        retry: false,
        refetchOnWindowFocus: false,
        refetchIntervalInBackground: true,
        refetchInterval: (query) => {
            const data = query.state.data;

            if (!poll || query.state.status === 'error' || (data && isSettled(data.status))) {
                return false;
            }

            const elapsed = Date.now() - startedAt;

            if (elapsed >= PURCHASE_GIVE_UP_MS) {
                return false;
            }

            return elapsed >= PURCHASE_SLOW_AFTER_MS ? PURCHASE_SLOW_POLL_MS : PURCHASE_POLL_MS;
        },
    });

/** Follows one checkout and refreshes the wallet once the payment settles. */
export const useTokenPurchase = (reference: string, startedAt: number, poll = true) => {
    const queryClient = useQueryClient();
    const query = useQuery(tokenPurchaseQueryOptions(reference, startedAt, poll));
    const status = query.data?.status;

    useEffect(() => {
        if (status && isSettled(status)) {
            void queryClient.invalidateQueries({ queryKey: walletQueryKey, exact: false });
        }
    }, [queryClient, status]);

    return query;
};
