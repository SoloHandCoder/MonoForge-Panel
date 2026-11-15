import { keepPreviousData, queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import http from '@/api/http';
import type { TokenLedgerPage, TokenTransaction } from '@/api/account/wallet/queries';
import { notifyHttpError } from '@/plugins/notifications';

// Not in the generated client yet (GET and POST /api/admin/users/{user_id}/tokens); regenerate it
// to replace these hand-written calls.

export interface AdjustTokensValues {
    /** Signed whole tokens: positive adds, negative removes. */
    amount: number;
    reason: string;
    allow_negative?: boolean;
}

interface AdjustTokensResponse {
    object: 'token_transaction';
    attributes: TokenTransaction;
    meta: { balance: number };
}

export const ADMIN_TOKENS_PER_PAGE = 10;

const adminUserTokensKey = (userId: number) => ['admin', 'users', userId, 'tokens'] as const;

export const adminUserTokensQueryOptions = (userId: number, page = 1) =>
    queryOptions({
        queryKey: [...adminUserTokensKey(userId), page],
        queryFn: async ({ signal }): Promise<TokenLedgerPage> => {
            // oxlint-disable-next-line no-restricted-properties
            const response = await http.get<TokenLedgerPage>(`/api/admin/users/${userId}/tokens`, {
                params: { page, per_page: ADMIN_TOKENS_PER_PAGE },
                signal,
            });

            return response.data;
        },
        placeholderData: keepPreviousData,
    });

export const useAdminUserTokens = (userId: number, page = 1) => useQuery(adminUserTokensQueryOptions(userId, page));

export const useAdjustUserTokens = (userId: number) => {
    const queryClient = useQueryClient();

    return useMutation({
        // oxlint-disable-next-line rules/generated-api-mutations -- the endpoint is not in the generated client yet
        mutationFn: async (values: AdjustTokensValues): Promise<AdjustTokensResponse> => {
            // oxlint-disable-next-line no-restricted-properties
            const response = await http.post<AdjustTokensResponse>(`/api/admin/users/${userId}/tokens`, values);

            return response.data;
        },
        onSuccess: (response) => {
            toast.success(`Balance is now ${response.meta.balance} tokens.`);
        },
        onError: (error) => notifyHttpError(error, 'Could not adjust the tokens'),
        onSettled: () => queryClient.invalidateQueries({ queryKey: adminUserTokensKey(userId) }),
    });
};
