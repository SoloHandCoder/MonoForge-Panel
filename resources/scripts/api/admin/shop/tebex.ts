// The admin "Token packs & Tebex" shop tab (phase 3 Task P), written by hand until the generated API client is
// regenerated with the /api/admin/shop/tebex and /api/admin/shop/token-packs endpoints.
/* oxlint-disable no-restricted-properties, rules/generated-api-mutations */
import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import http from '@/api/http';
import { notifyHttpError } from '@/plugins/notifications';

export interface TebexSettings {
    public_token: string;
    private_key_set: boolean;
    webhook_secret_set: boolean;
    webhook_ips: string[];
    default_webhook_ips: string[];
    webhook_url: string;
    checkout_enabled: boolean;
}

export interface TebexSettingsInput {
    public_token: string;
    /** Blank keeps the stored key. */
    private_key: string;
    /** Blank keeps the stored secret. */
    webhook_secret: string;
    /** Empty uses the addresses Tebex documents. */
    webhook_ips: string[];
}

export interface AdminTokenPack {
    id: number;
    tebex_package_id: string;
    name: string;
    tokens: number;
    bonus_tokens: number;
    visible: boolean;
    sort: number;
}

export type AdminTokenPackInput = Omit<AdminTokenPack, 'id'>;

export type TebexPaymentStatus =
    | 'pending'
    | 'completed'
    | 'refunded'
    | 'chargeback'
    | 'disputed'
    | 'declined'
    | 'unmatched'
    | 'underpaid';

export interface TebexPayment {
    id: number;
    transaction_id: string;
    user: { id: number; username: string; email: string } | null;
    status: TebexPaymentStatus;
    tokens: number;
    tokens_reversed: number;
    amount: string;
    currency: string;
    events: string[];
    flagged: boolean;
    processed_at: string | null;
    created_at: string | null;
}

export interface TebexPaymentPage {
    data: TebexPayment[];
    meta: { current_page: number; last_page: number; total: number };
}

const settingsKey = ['admin', 'shop', 'tebex'] as const;
const packsKey = ['admin', 'shop', 'token-packs'] as const;
const paymentsKey = ['admin', 'shop', 'tebex', 'payments'] as const;

export const adminTebexSettingsQueryOptions = () =>
    queryOptions({
        queryKey: settingsKey,
        queryFn: async ({ signal }): Promise<TebexSettings> =>
            (await http.get<TebexSettings>('/api/admin/shop/tebex', { signal })).data,
    });

export const adminTokenPacksQueryOptions = () =>
    queryOptions({
        queryKey: packsKey,
        queryFn: async ({ signal }): Promise<AdminTokenPack[]> =>
            (await http.get<{ data: AdminTokenPack[] }>('/api/admin/shop/token-packs', { signal })).data.data,
    });

export const adminTebexPaymentsQueryOptions = (page: number, flagged: boolean) =>
    queryOptions({
        queryKey: [...paymentsKey, { page, flagged }] as const,
        queryFn: async ({ signal }): Promise<TebexPaymentPage> =>
            (
                await http.get<TebexPaymentPage>('/api/admin/shop/tebex/payments', {
                    params: { page, per_page: 25, flagged: flagged ? 1 : 0 },
                    signal,
                })
            ).data,
    });

export const useAdminTebexSettings = () => useQuery(adminTebexSettingsQueryOptions());

export const useAdminTokenPacks = () => useQuery(adminTokenPacksQueryOptions());

export const useAdminTebexPayments = (page: number, flagged: boolean) =>
    useQuery(adminTebexPaymentsQueryOptions(page, flagged));

export const useUpdateAdminTebexSettings = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (body: TebexSettingsInput): Promise<TebexSettings> =>
            (await http.put<TebexSettings>('/api/admin/shop/tebex', body)).data,
        onSuccess: (settings) => {
            queryClient.setQueryData(settingsKey, settings);
            toast.success('Tebex settings updated', { description: 'The token store uses the new settings.' });
        },
        onError: (error) => notifyHttpError(error, 'Unable to update the Tebex settings'),
    });
};

const usePackMutation = <TVariables>(
    request: (variables: TVariables) => Promise<unknown>,
    success: string,
    errorTitle: string
) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: request,
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: packsKey });
            toast.success(success);
        },
        onError: (error) => notifyHttpError(error, errorTitle),
    });
};

export const useCreateAdminTokenPack = () =>
    usePackMutation(
        (body: AdminTokenPackInput) => http.post('/api/admin/shop/token-packs', body),
        'Token pack created',
        'Unable to create the token pack'
    );

export const useUpdateAdminTokenPack = () =>
    usePackMutation(
        ({ id, body }: { id: number; body: AdminTokenPackInput }) =>
            http.put(`/api/admin/shop/token-packs/${id}`, body),
        'Token pack updated',
        'Unable to update the token pack'
    );

export const useDeleteAdminTokenPack = () =>
    usePackMutation(
        (id: number) => http.delete(`/api/admin/shop/token-packs/${id}`),
        'Token pack deleted',
        'Unable to delete the token pack'
    );
