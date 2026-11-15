import { keepPreviousData, queryOptions, useQuery } from '@tanstack/react-query';
import {
    clientGetWallet,
    type ClientTokenTransactionAttributes,
    type ClientTokenTransactionPaginatedResponse,
} from '@/api/generated';

export type TokenTransactionType =
    | 'purchase'
    | 'refund'
    | 'chargeback'
    | 'server_charge'
    | 'addon_charge'
    | 'ai_usage'
    | 'admin_adjust'
    | 'correction';

/** One row of a user's token ledger. */
export type TokenTransaction = ClientTokenTransactionAttributes;

/** A page of a token ledger with the balance in `meta.balance`. */
export type TokenLedgerPage = ClientTokenTransactionPaginatedResponse;

export const WALLET_PER_PAGE = 25;

export const tokenTransactionLabels = {
    purchase: 'Purchase',
    refund: 'Refund',
    chargeback: 'Chargeback',
    server_charge: 'Server',
    addon_charge: 'Add-on',
    ai_usage: 'AI usage',
    admin_adjust: 'Adjustment',
    correction: 'Correction',
} satisfies Record<TokenTransactionType, string>;

/** Whole tokens with thousands separators, e.g. "1,500". */
export const formatTokens = (tokens: number): string => new Intl.NumberFormat('en-US').format(tokens);

export const walletQueryKey = ['account', 'wallet'] as const;

export const walletQueryOptions = (page = 1) =>
    queryOptions({
        queryKey: [...walletQueryKey, page],
        queryFn: async ({ signal }): Promise<TokenLedgerPage> => {
            const { data } = await clientGetWallet({ query: { page, per_page: WALLET_PER_PAGE }, signal });

            return data;
        },
        placeholderData: keepPreviousData,
    });

export const useWallet = (page = 1) => useQuery(walletQueryOptions(page));
