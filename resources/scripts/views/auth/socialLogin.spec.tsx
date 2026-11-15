/** @vitest-environment jsdom */
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClientProvider } from '@tanstack/react-query';
import {
    createMemoryHistory,
    createRootRoute,
    createRoute,
    createRouter,
    Outlet,
    RouterProvider,
} from '@tanstack/react-router';
import { toast } from 'sonner';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { OAuthResult } from '@/api/settings/types';
import { queryClient } from '@/api/queryClient';
import { siteSettingsQueryKey } from '@/api/settings/queries';
import { renderView } from '@/sdk/testing';
import { View } from '../View';

const mocks = vi.hoisted(() => ({
    start: vi.fn(),
    result: vi.fn<() => OAuthResult | null>(() => null),
}));

vi.mock('@/api/account/oauth', () => ({
    OAUTH_PROVIDER_LABELS: { discord: 'Discord', google: 'Google' },
    takeOAuthResult: mocks.result,
    useStartOAuthLogin: () => ({ mutate: mocks.start, isPending: false, variables: undefined }),
}));

async function renderLogin(providers: { discord?: boolean; google?: boolean }) {
    queryClient.setQueryData(siteSettingsQueryKey, {
        name: 'Panel',
        locale: 'en',
        recaptcha: { enabled: false, siteKey: '' },
        oauth: { providers, result: null },
    });
    const root = createRootRoute({ component: Outlet });
    const router = createRouter({
        routeTree: root.addChildren([
            createRoute({
                getParentRoute: () => root,
                path: '/auth/login',
                component: () => <View name='page.auth.login' />,
            }),
            createRoute({
                getParentRoute: () => root,
                path: '/auth/login/checkpoint',
                component: () => <View name='page.auth.checkpoint' />,
            }),
        ]),
        history: createMemoryHistory({ initialEntries: ['/auth/login'] }),
    });

    await act(async () => {
        render(
            <QueryClientProvider client={queryClient}>
                <RouterProvider router={router} />
            </QueryClientProvider>
        );
    });

    return router;
}

beforeEach(() => {
    vi.clearAllMocks();
    mocks.result.mockReturnValue(null);
});

afterEach(() => {
    cleanup();
    queryClient.clear();
});

describe('Discord and Google sign in', () => {
    it('shows a button for each enabled provider and starts it', async () => {
        await renderLogin({ discord: true, google: false });

        const discord = await screen.findByRole('button', { name: 'Continue with Discord' });

        expect(screen.queryByRole('button', { name: 'Continue with Google' })).toBeNull();

        fireEvent.click(discord);
        await waitFor(() => expect(mocks.start).toHaveBeenCalledWith({ provider: 'discord', captcha: '' }));
    });

    it('shows no buttons when no provider is enabled', async () => {
        await renderLogin({});

        await screen.findByRole('heading', { name: 'Login to Continue' });
        expect(screen.queryByText(/Continue with/)).toBeNull();
    });

    it('toasts the error a provider callback reported', async () => {
        const error = vi.spyOn(toast, 'error');

        mocks.result.mockReturnValue({ status: 'error', message: 'No MonoForge account is linked.', token: null });

        await renderLogin({ discord: true });

        await waitFor(() => expect(error).toHaveBeenCalledWith('No MonoForge account is linked.'));
    });

    it('continues to the two-factor checkpoint after a provider sign in', async () => {
        mocks.result.mockReturnValue({ status: 'checkpoint', message: '', token: 'confirmation-token' });

        const router = await renderLogin({ discord: true });

        await waitFor(() => expect(router.state.location.pathname).toBe('/auth/login/checkpoint'));
    });

    it('renders the social login fixture', () => {
        const { unmount } = renderView('page.auth.login', { fixture: 'socialLogin' });

        expect(screen.getByRole('button', { name: 'Continue with Google' })).toBeInTheDocument();
        unmount();
    });
});
