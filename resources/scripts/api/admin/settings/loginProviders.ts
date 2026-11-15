// The admin "Login providers" settings (phase 2 Task G), written by hand until the generated API client is
// regenerated with GET/PUT /api/admin/settings/login-providers.
/* oxlint-disable no-restricted-properties, rules/generated-api-mutations */
import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import http from '@/api/http';
import type { OAuthProviderId } from '@/api/account/oauth';
import { notifyHttpError } from '@/plugins/notifications';

export interface LoginProviderSettings {
    enabled: boolean;
    client_id: string;
    client_secret_set: boolean;
    callback_url: string;
    /** Fields set in .env on a panel that reads its settings from the environment only; they cannot be changed here. */
    set_in_env: { enabled: boolean; client_id: boolean; client_secret: boolean };
}

export type LoginProvidersSettings = Record<OAuthProviderId, LoginProviderSettings>;

export interface LoginProviderInput {
    enabled: boolean;
    client_id: string;
    /** Blank keeps the stored secret. */
    client_secret: string;
}

/** The update body: every provider, with a blank secret keeping the stored one. */
export interface LoginProvidersInput {
    discord: LoginProviderInput;
    google: LoginProviderInput;
}

const loginProvidersQueryKey = ['admin', 'settings', 'login-providers'] as const;

export const adminLoginProvidersQueryOptions = () =>
    queryOptions({
        queryKey: loginProvidersQueryKey,
        queryFn: async ({ signal }): Promise<LoginProvidersSettings> =>
            (await http.get<LoginProvidersSettings>('/api/admin/settings/login-providers', { signal })).data,
    });

export const useAdminLoginProviders = () => useQuery(adminLoginProvidersQueryOptions());

export const useUpdateAdminLoginProviders = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (body: LoginProvidersInput) => {
            await http.put('/api/admin/settings/login-providers', body);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: loginProvidersQueryKey });
            toast.success('Login provider settings updated', {
                description: 'Discord and Google sign in settings have been updated.',
            });
        },
        onError: (error) => notifyHttpError(error, 'Unable to update login providers'),
    });
};
