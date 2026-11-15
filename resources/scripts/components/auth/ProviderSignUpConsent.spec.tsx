/** @vitest-environment jsdom */

import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { OAuthRegistration } from '@/api/settings/types';
import ProviderSignUpConsent from './ProviderSignUpConsent';

const state = vi.hoisted(() => ({
    pending: null as OAuthRegistration | null,
    complete: vi.fn(),
    cancel: vi.fn(),
}));

const doc = (title: string, id: string, version: number) => ({
    title,
    url: `/legal/${id}`,
    external: false,
    version,
    updatedAt: '',
});

vi.mock('@/api/settings/queries', () => ({
    useSiteSettings: () => ({
        oauth: { providers: {}, result: null, registration: state.pending },
        legal: {
            pending: [],
            documents: {
                terms: doc('Terms of Service', 'terms', 4),
                privacy: doc('Privacy Policy', 'privacy', 2),
                refunds: doc('Refund Policy', 'refunds', 1),
            },
        },
    }),
}));
vi.mock('@/api/account/oauth', () => ({
    useCompleteOAuthSignUp: () => ({ mutate: state.complete, isPending: false }),
    useCancelOAuthSignUp: () => ({ mutate: state.cancel, isPending: false }),
}));
vi.mock('@/sdk/navigation', () => ({
    PanelLink: ({ children }: { children: string }) => <a href='/auth/login'>{children}</a>,
}));

afterEach(cleanup);
beforeEach(() => {
    vi.clearAllMocks();
    state.pending = {
        provider: 'discord',
        label: 'Discord',
        email: 'nelly@example.com',
        username: 'Nelly',
        nonce: 'n0nce',
    };
});

describe('provider sign-up consent', () => {
    it('creates the shown sign-up with the shown document versions once the visitor agrees', async () => {
        render(<ProviderSignUpConsent />);

        expect(screen.getByText(/You are signing up with Discord/)).toHaveTextContent('nelly@example.com');
        expect(screen.getByRole('button', { name: 'Create Account' })).toBeDisabled();

        await userEvent.click(
            screen.getByRole('checkbox', { name: /I agree to the Terms of Service and Privacy Policy/ })
        );
        await userEvent.click(screen.getByRole('button', { name: 'Create Account' }));

        expect(state.complete).toHaveBeenCalledWith({
            terms: true,
            nonce: 'n0nce',
            termsVersion: 4,
            privacyVersion: 2,
        });
    });

    it('cancels the waiting sign-up', async () => {
        render(<ProviderSignUpConsent />);

        await userEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(state.cancel).toHaveBeenCalled();
        expect(state.complete).not.toHaveBeenCalled();
    });

    it('explains an expired sign-up', () => {
        state.pending = null;

        render(<ProviderSignUpConsent />);

        expect(screen.getByText(/This sign up expired or was already finished/)).toBeVisible();
        expect(screen.queryByRole('button', { name: 'Create Account' })).toBeNull();
    });
});
