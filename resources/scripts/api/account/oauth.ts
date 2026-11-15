// Discord and Google login and linking (phase 2 Task G). These endpoints are written by hand until the
// generated API client is regenerated with them; the sign-in start endpoint sits outside the documented API.
/* oxlint-disable no-restricted-properties, rules/generated-api-mutations */
import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import http from '@/api/http';
import { authGetCsrfCookie } from '@/api/generated/sdk.gen';
import type { OAuthResult } from '@/api/settings/types';
import { getBootstrapSiteSettings } from '@/bootstrap';
import { notifyHttpError } from '@/plugins/notifications';

export type OAuthProviderId = 'discord' | 'google';

export const OAUTH_PROVIDER_LABELS = { discord: 'Discord', google: 'Google' } as const satisfies Record<
    OAuthProviderId,
    string
>;

export interface ConnectedAccount {
    provider: OAuthProviderId;
    label: string;
    enabled: boolean;
    linked: boolean;
    username: string | null;
    email: string | null;
    avatar: string | null;
    linked_at: string | null;
}

export interface ConnectedAccounts {
    has_password: boolean;
    providers: ConnectedAccount[];
}

const connectedAccountsQueryKey = ['account', 'oauth'] as const;

export const connectedAccountsQueryOptions = () =>
    queryOptions({
        queryKey: connectedAccountsQueryKey,
        queryFn: async ({ signal }): Promise<ConnectedAccounts> =>
            (await http.get<ConnectedAccounts>('/api/client/account/oauth', { signal })).data,
    });

export const useConnectedAccounts = () => useQuery(connectedAccountsQueryOptions());

/** Only ever follow provider URLs: the panel's own start endpoints never return anything else. */
const isProviderUrl = (url: string) =>
    url.startsWith('https://discord.com/') || url.startsWith('https://accounts.google.com/');

const followProvider = ({ url }: { url: string }) => {
    if (isProviderUrl(url)) {
        window.location.assign(url);
    }
};

/** Starts "Continue with Discord/Google" from the sign-in page. */
export const useStartOAuthLogin = () =>
    useMutation({
        mutationFn: async ({ provider, captcha }: { provider: OAuthProviderId; captcha: string }) => {
            await authGetCsrfCookie();

            return (await http.post<{ url: string }>(`/auth/modules/${provider}`, { 'g-recaptcha-response': captcha }))
                .data;
        },
        onSuccess: followProvider,
        onError: (error) => notifyHttpError(error, 'Unable to start signing in'),
    });

/**
 * Creates the account of a Discord/Google sign-up waiting on the consent screen, once the visitor
 * agrees to the Terms of Service and Privacy Policy, and sends the signed-in browser on.
 */
export const useCompleteOAuthSignUp = () =>
    useMutation({
        mutationFn: async (terms: boolean) => {
            await authGetCsrfCookie();

            return (
                await http.post<{ data: { complete: boolean; intended: string | null } }>('/auth/modules/register', {
                    terms,
                })
            ).data.data;
        },
        onSuccess: ({ intended }) => {
            window.location.assign(intended || '/');
        },
        onError: (error) => notifyHttpError(error, 'Unable to create your account'),
    });

/** Starts linking a provider to the signed-in account. */
export const useLinkOAuthAccount = () =>
    useMutation({
        mutationFn: async (provider: OAuthProviderId) =>
            (await http.post<{ url: string }>(`/api/client/account/oauth/${provider}/link`)).data,
        onSuccess: followProvider,
        onError: (error) => notifyHttpError(error, 'Unable to link the account'),
    });

export const useUnlinkOAuthAccount = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (provider: OAuthProviderId) => {
            await http.delete(`/api/client/account/oauth/${provider}`);
        },
        onSuccess: (_data, provider) => toast.success(`${OAUTH_PROVIDER_LABELS[provider]} account unlinked`),
        onError: (error) => notifyHttpError(error, 'Unable to unlink the account'),
        onSettled: () => queryClient.invalidateQueries({ queryKey: connectedAccountsQueryKey }),
    });
};

/** The first password of an account created through Discord or Google. */
export const useSetInitialPassword = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (body: { password: string; password_confirmation: string }) => {
            await http.post('/api/client/account/oauth/password', body);
        },
        onSuccess: () => toast.success('Password set'),
        onError: (error) => notifyHttpError(error, 'Unable to set the password'),
        onSettled: () => queryClient.invalidateQueries({ queryKey: connectedAccountsQueryKey }),
    });
};

let oauthResultTaken = false;

/**
 * The outcome the Discord/Google callback flashed for this page load, handed out once so it is shown once
 * even when the page that reads it renders again.
 */
export const takeOAuthResult = (): OAuthResult | null => {
    if (oauthResultTaken) {
        return null;
    }

    oauthResultTaken = true;

    return getBootstrapSiteSettings()?.oauth?.result ?? null;
};
