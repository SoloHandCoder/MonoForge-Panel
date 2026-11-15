/** @vitest-environment jsdom */
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useImperativeHandle, type Ref } from 'react';
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
import type * as OAuthApi from '@/api/account/oauth';
import { queryClient } from '@/api/queryClient';
import { siteSettingsQueryKey } from '@/api/settings/queries';
import type { SiteSettings } from '@/api/settings/types';
import { renderView } from '@/sdk/testing';
import { View } from '../View';

const mocks = vi.hoisted(() => ({
    login: vi.fn(),
    checkpoint: vi.fn(),
    requestReset: vi.fn(),
    reset: vi.fn(),
    register: vi.fn(),
    startOAuth: vi.fn(),
    execute: vi.fn<() => Promise<string | null>>(),
}));

vi.mock('@/api/auth/queries', () => ({
    useLogin: () => ({ mutateAsync: mocks.login, isPending: false }),
    useLoginCheckpoint: () => ({ mutateAsync: mocks.checkpoint, isPending: false }),
    useRequestPasswordResetEmail: () => ({ mutateAsync: mocks.requestReset, isPending: false }),
    usePerformPasswordReset: () => ({ mutateAsync: mocks.reset, isPending: false }),
    useRegister: () => ({ mutateAsync: mocks.register, isPending: false }),
}));

vi.mock('@/api/account/oauth', async (importOriginal) => ({
    ...(await importOriginal<typeof OAuthApi>()),
    useStartOAuthLogin: () => ({ mutate: mocks.startOAuth, isPending: false, variables: undefined }),
}));

vi.mock('@/components/elements/InvisibleRecaptcha', () => ({
    default: function InvisibleRecaptcha({ ref }: { ref: Ref<{ execute: () => Promise<string | null> }> }) {
        useImperativeHandle(ref, () => ({ execute: mocks.execute }));

        return <div data-testid='recaptcha' />;
    },
}));

vi.mock('@/components/elements/Turnstile', () => ({
    default: function Turnstile({
        ref,
        action,
    }: {
        ref: Ref<{ execute: () => Promise<string | null> }>;
        action?: string;
    }) {
        useImperativeHandle(ref, () => ({ execute: mocks.execute }));

        return <div data-testid='turnstile' data-action={action} />;
    },
}));

const views = {
    '/auth/login': 'page.auth.login',
    '/auth/login/checkpoint': 'page.auth.checkpoint',
    '/auth/password': 'page.auth.password',
    '/auth/password/reset/$token': 'page.auth.passwordReset',
    '/auth/register': 'page.auth.register',
} as const;

let router: ReturnType<typeof createAuthRouter> | undefined;

function createAuthRouter(path: string) {
    const root = createRootRoute({ component: Outlet });

    return createRouter({
        routeTree: root.addChildren(
            Object.entries(views).map(([route, name]) =>
                createRoute({ getParentRoute: () => root, path: route, component: () => <View name={name} /> })
            )
        ),
        history: createMemoryHistory({ initialEntries: [path] }),
    });
}

async function renderAuth(path: string, recaptcha = false, extra: Partial<SiteSettings> = {}) {
    queryClient.setQueryData<SiteSettings>(siteSettingsQueryKey, {
        name: 'Panel',
        locale: 'en',
        recaptcha: { enabled: recaptcha, siteKey: recaptcha ? 'site-key' : '' },
        ...extra,
    });
    router = createAuthRouter(path);

    await act(async () => {
        render(
            <QueryClientProvider client={queryClient}>
                <RouterProvider router={router!} />
            </QueryClientProvider>
        );
    });
}

beforeEach(() => {
    vi.clearAllMocks();
});

afterEach(() => {
    cleanup();
    queryClient.clear();
    router = undefined;
});

describe('auth views from fixtures', () => {
    it('shows field errors and the disabled state a fixture describes', () => {
        const { container, unmount } = renderView('page.auth.login', { fixture: 'submitting' });

        expect(container.querySelector('input[name="username"]')).toBeDisabled();
        expect(container.querySelector('button[type="submit"]')).toHaveAttribute('aria-busy', 'true');
        unmount();

        const invalid = renderView('page.auth.login', { fixture: 'invalid' });

        expect(invalid.container).toHaveTextContent('A username or email must be provided.');
        expect(invalid.container.querySelector('input[name="password"]')).toHaveAttribute('aria-invalid', 'true');
        invalid.unmount();
    });

    it('mounts the extensions of the login form slot with the route', () => {
        const { container, unmount } = renderView('page.auth.login', {
            slots: { 'auth.login.form.after': ({ data }) => <p>Signing in at {data.pathname}</p> },
        });

        expect(container).toHaveTextContent('Signing in at /auth/login');
        unmount();
    });
});

describe('sign-in look', () => {
    it('renders an aria-hidden blocks layer and the routed children in the auth layout', () => {
        const { container, unmount } = renderView('layout.auth', { slots: {} });

        expect(container.querySelector('[aria-hidden="true"]')).not.toBeNull();
        unmount();
    });

    it('shows the MonoForge mark and title on the card', () => {
        const { container, unmount } = renderView('page.auth.login');

        expect(container.querySelector('[aria-label="MonoForge"]')).not.toBeNull();
        expect(screen.getAllByText('MonoForge').length).toBeGreaterThan(0);
        expect(screen.getByRole('heading', { name: 'Login to Continue' })).toBeInTheDocument();
        unmount();
    });

    it('links to the Discord invite in a new tab from the card footer', () => {
        const { unmount } = renderView('page.auth.login');
        const discord = screen.getByRole('link', { name: 'Join our Discord' });

        expect(discord).toHaveAttribute('href', 'https://discord.gg/B3fd45pgvC');
        expect(discord).toHaveAttribute('target', '_blank');
        expect(discord).toHaveAttribute('rel', 'noopener noreferrer');
        unmount();
    });
});

describe('page.auth.login', () => {
    it('gives password managers the attributes they read', async () => {
        await renderAuth('/auth/login');

        const username = screen.getByLabelText('Username or Email');
        const password = screen.getByLabelText('Password');

        expect(username).toHaveAttribute('name', 'username');
        expect(username).toHaveAttribute('autocomplete', 'username');
        expect(password).toHaveAttribute('type', 'password');
        expect(password).toHaveAttribute('autocomplete', 'current-password');
        expect(screen.getByRole('link', { name: 'Forgot password?' })).toHaveAttribute('href', '/auth/password');
    });

    it('hands a two-factor login to the checkpoint with its token and destination', async () => {
        mocks.login.mockResolvedValue({ data: { complete: false, confirmation_token: 'confirm-token' } });
        await renderAuth('/auth/login?redirect=%2Fpanel%2Fnodes');

        fireEvent.change(screen.getByLabelText('Username or Email'), { target: { value: 'alex' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'correct horse' } });
        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Login' }));
        });

        await waitFor(() => expect(router!.state.location.pathname).toBe('/auth/login/checkpoint'));
        expect(mocks.login).toHaveBeenCalledWith({
            body: { user: 'alex', password: 'correct horse', 'g-recaptcha-response': '' },
        });
        expect(router!.state.location.search).toEqual({ redirect: '/panel/nodes' });
        expect(router!.state.location.state).toMatchObject({ token: 'confirm-token' });
    });

    it('validates before submitting', async () => {
        await renderAuth('/auth/login');

        fireEvent.change(screen.getByLabelText('Username or Email'), { target: { value: 'alex' } });
        fireEvent.change(screen.getByLabelText('Username or Email'), { target: { value: '' } });

        expect(await screen.findByText('A username or email must be provided.')).toBeVisible();
        expect(screen.getByRole('button', { name: 'Login' })).toBeDisabled();
        expect(mocks.login).not.toHaveBeenCalled();
    });

    it('sends the reCAPTCHA token, and stops when verification fails', async () => {
        const error = vi.spyOn(toast, 'error').mockImplementation(() => '');

        mocks.login.mockReturnValue(new Promise(() => {}));
        await renderAuth('/auth/login', true);

        expect(screen.getByTestId('recaptcha')).toBeInTheDocument();
        fireEvent.change(screen.getByLabelText('Username or Email'), { target: { value: 'alex' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'secret' } });

        mocks.execute.mockResolvedValueOnce(null);
        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Login' }));
        });

        expect(error).toHaveBeenCalledWith('Captcha verification failed, please try again.');
        expect(mocks.login).not.toHaveBeenCalled();

        mocks.execute.mockResolvedValueOnce('captcha-token');
        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Login' }));
        });

        expect(mocks.login).toHaveBeenCalledWith({
            body: { user: 'alex', password: 'secret', 'g-recaptcha-response': 'captcha-token' },
        });
    });
    it('sends the login Turnstile token when continuing with Discord', async () => {
        mocks.execute.mockResolvedValue('turnstile-token');
        await renderAuth('/auth/login', false, {
            captcha: { provider: 'turnstile', siteKey: 'site-key' },
            oauth: { providers: { discord: true }, result: null },
        });

        // The start endpoint verifies with the "login" action, like the password form.
        expect(screen.getByTestId('turnstile')).toHaveAttribute('data-action', 'login');
        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Continue with Discord' }));
        });

        expect(mocks.startOAuth).toHaveBeenCalledWith({ provider: 'discord', captcha: 'turnstile-token' });
    });
});

describe('page.auth.checkpoint', () => {
    it('switches to a recovery code and sends it with the confirmation token', async () => {
        mocks.checkpoint.mockResolvedValue({ data: { complete: false } });
        await renderAuth('/auth/login');
        await act(() => router!.navigate({ to: '/auth/login/checkpoint', state: { token: 'confirm-token' } }));

        const code = screen.getByTitle('Authentication Code');

        expect(code).toHaveAttribute('autocomplete', 'one-time-code');
        fireEvent.click(screen.getByRole('button', { name: "I've Lost My Device" }));

        const recovery = screen.getByTitle('Recovery Code');

        expect(recovery).toHaveAttribute('name', 'recoveryCode');
        fireEvent.change(recovery, { target: { value: 'abcd-efgh' } });
        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        });

        expect(mocks.checkpoint).toHaveBeenCalledWith({
            body: { confirmation_token: 'confirm-token', authentication_code: '', recovery_token: 'abcd-efgh' },
        });
    });
});

describe('password reset forms', () => {
    it('requests a reset email and clears the form', async () => {
        mocks.requestReset.mockResolvedValue({});
        await renderAuth('/auth/password');

        const email = screen.getByLabelText('Email');

        expect(email).toHaveAttribute('autocomplete', 'email');
        fireEvent.change(email, { target: { value: 'alex@example.test' } });
        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Send Email' }));
        });

        expect(mocks.requestReset).toHaveBeenCalledWith({
            body: { email: 'alex@example.test', 'g-recaptcha-response': '' },
        });
        expect(email).toHaveValue('');
    });

    it('resets with the token and email from the link once the passwords match', async () => {
        mocks.reset.mockReturnValue(new Promise(() => {}));
        await renderAuth('/auth/password/reset/reset-token?email=alex%40example.test');

        expect(screen.getByLabelText('Email')).toHaveValue('alex@example.test');
        fireEvent.change(screen.getByLabelText('New Password'), { target: { value: 'correct horse' } });
        fireEvent.change(screen.getByLabelText('Confirm New Password'), { target: { value: 'correct' } });

        expect(await screen.findByText('Your new password does not match.')).toBeVisible();
        expect(screen.getByLabelText('New Password')).toHaveAttribute('autocomplete', 'new-password');

        fireEvent.change(screen.getByLabelText('Confirm New Password'), { target: { value: 'correct horse' } });
        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Reset Password' }));
        });

        expect(mocks.reset).toHaveBeenCalledWith({
            body: {
                email: 'alex@example.test',
                token: 'reset-token',
                password: 'correct horse',
                password_confirmation: 'correct horse',
                'g-recaptcha-response': '',
            },
        });
    });
});

describe('sign-up', () => {
    it('links to sign-up from the login page only while the panel allows it', async () => {
        await renderAuth('/auth/login');

        expect(screen.queryByRole('link', { name: 'Create an account' })).toBeNull();
        cleanup();

        await renderAuth('/auth/login', false, { registration: { enabled: true } });

        expect(screen.getByRole('link', { name: 'Create an account' })).toHaveAttribute('href', '/auth/register');
    });

    it('creates the account with a Turnstile token once the Terms of Service and Privacy Policy are accepted', async () => {
        mocks.register.mockReturnValue(new Promise(() => {}));
        mocks.execute.mockResolvedValue('turnstile-token');
        await renderAuth('/auth/register', false, {
            captcha: { provider: 'turnstile', siteKey: 'site-key' },
            registration: { enabled: true },
        });

        // The widget names its form, which the panel checks when verifying the token.
        expect(screen.getByTestId('turnstile')).toHaveAttribute('data-action', 'register');
        expect(screen.getByRole('link', { name: 'Already have an account? Sign in' })).toHaveAttribute(
            'href',
            '/auth/login'
        );

        fireEvent.change(screen.getByLabelText('Username'), { target: { value: 'alex' } });
        fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'alex@example.test' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'correct horse' } });
        fireEvent.change(screen.getByLabelText('Confirm Password'), { target: { value: 'correct horse' } });

        expect(screen.getByRole('button', { name: 'Create Account' })).toBeDisabled();

        // The documents open in a new tab so the half-filled form stays.
        for (const [name, href] of [
            ['Terms of Service', '/legal/terms'],
            ['Privacy Policy', '/legal/privacy'],
        ]) {
            const link = screen.getByRole('link', { name });

            expect(link).toHaveAttribute('href', href);
            expect(link).toHaveAttribute('target', '_blank');
            expect(link).toHaveAttribute('rel', 'noopener noreferrer');
        }

        // The card footer links every document.
        expect(screen.getByRole('navigation', { name: 'Legal' })).toHaveTextContent('Terms · Privacy · Refunds');

        fireEvent.click(screen.getByRole('checkbox', { name: 'I agree to the Terms of Service and Privacy Policy' }));
        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Create Account' }));
        });

        expect(mocks.register).toHaveBeenCalledWith({
            body: {
                username: 'alex',
                email: 'alex@example.test',
                password: 'correct horse',
                password_confirmation: 'correct horse',
                terms: true,
                'g-recaptcha-response': 'turnstile-token',
            },
        });
    });
});
