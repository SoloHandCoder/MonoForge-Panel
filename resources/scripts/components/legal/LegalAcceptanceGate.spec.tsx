/** @vitest-environment jsdom */

import { cleanup, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { LegalDocumentId } from '@/api/settings/types';
import LegalAcceptanceGate, { isLegalGateExempt } from './LegalAcceptanceGate';

const state = vi.hoisted(() => ({
    pending: [] as LegalDocumentId[],
    accept: vi.fn(),
    logout: vi.fn(),
}));

const summary = (title: string, url: string, version: number) => ({
    title,
    url,
    external: false,
    version,
    updatedAt: '2026-11-20T12:00:00+00:00',
});

vi.mock('@/api/legal', () => ({
    usePendingLegalDocuments: () => state.pending,
    useLegalDocuments: () => ({
        terms: summary('Terms of Service', '/legal/terms', 3),
        privacy: summary('Privacy Policy', '/legal/privacy', 2),
        refunds: summary('Refund Policy', '/legal/refunds', 1),
    }),
    useAcceptLegalDocuments: () => ({ mutate: state.accept, isPending: false }),
}));

vi.mock('@/api/auth/queries', () => ({
    useLogout: () => ({ mutate: state.logout, isPending: false }),
}));

afterEach(cleanup);
beforeEach(() => {
    vi.clearAllMocks();
    state.pending = [];
});

describe('updated terms prompt', () => {
    it('blocks the panel until the user accepts the current versions', async () => {
        state.pending = ['terms'];

        render(<LegalAcceptanceGate pathname='/server/abc' />);

        const dialog = screen.getByRole('dialog');

        expect(within(dialog).getByText('We updated our Terms')).toBeVisible();
        // No way to dismiss it other than accepting or signing out.
        expect(within(dialog).queryByRole('button', { name: /close/i })).toBeNull();

        const link = within(dialog).getByRole('link', { name: 'Terms of Service' });

        expect(link).toHaveAttribute('href', '/legal/terms');
        expect(link).toHaveAttribute('target', '_blank');
        expect(within(dialog).getByText('Updated 20 November 2026')).toBeVisible();

        await userEvent.click(within(dialog).getByRole('button', { name: 'Accept' }));

        expect(state.accept).toHaveBeenCalledWith({ body: { terms_version: 3, privacy_version: 2 } });
    });

    it('names both documents when both changed, and lets the user sign out instead', async () => {
        state.pending = ['terms', 'privacy'];

        render(<LegalAcceptanceGate pathname='/' />);

        const dialog = screen.getByRole('dialog');

        expect(within(dialog).getByText('We updated our Terms and Privacy Policy')).toBeVisible();
        expect(within(dialog).getByRole('link', { name: 'Privacy Policy' })).toHaveAttribute('href', '/legal/privacy');

        await userEvent.click(within(dialog).getByRole('button', { name: 'Sign out' }));

        expect(state.logout).toHaveBeenCalled();
        expect(state.accept).not.toHaveBeenCalled();
    });

    it('stays away when nothing changed, and on the sign-in, legal, help center and Admin Legal settings pages', () => {
        render(<LegalAcceptanceGate pathname='/' />);
        expect(screen.queryByRole('dialog')).toBeNull();

        state.pending = ['privacy'];
        for (const pathname of [
            '/panel/settings/legal',
            '/legal/privacy',
            '/auth/login',
            '/help',
            '/help/the-console',
        ]) {
            cleanup();
            render(<LegalAcceptanceGate pathname={pathname} />);

            expect(screen.queryByRole('dialog')).toBeNull();
        }

        expect(isLegalGateExempt('/panel/settings')).toBe(false);
        expect(isLegalGateExempt('/account')).toBe(false);
        // Only the help center itself, not a page that merely starts with the same letters.
        expect(isLegalGateExempt('/helpdesk')).toBe(false);
    });
});
