import { keepPreviousData, queryOptions, useQuery } from '@tanstack/react-query';
import http from '@/api/http';
import type { PaginationMeta } from '@/api/generated';

// The wallet endpoints are not in the generated client yet; regenerate it to replace these
// hand-written calls with the generated ones (GET /api/client/account/wallet).

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
export interface TokenTransaction {
    id: number;
    /** Signed: positive credits, negative debits. */
    amount: number;
    balance_after: number;
    type: TokenTransactionType;
    reference_type: string | null;
    reference_id: string | null;
    description: string;
    created_at: string;
}

/** A page of a token ledger with the balance in `meta.balance`. */
export interface TokenLedgerPage {
    object: 'list';
    data: { object: 'token_transaction'; attributes: TokenTransaction }[];
    meta: { balance: number; pagination: PaginationMeta };
}

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
            // oxlint-disable-next-line no-restricted-properties
            const response = await http.get<TokenLedgerPage>('/api/client/account/wallet', {
                params: { page, per_page: WALLET_PER_PAGE },
                signal,
            });

            return response.data;
        },
        placeholderData: keepPreviousData,
    });

export const useWallet = (page = 1) => useQuery(walletQueryOptions(page));
