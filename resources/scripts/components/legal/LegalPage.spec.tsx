/** @vitest-environment jsdom */

import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import LegalPage from './LegalPage';

const route = vi.hoisted(() => ({ document: 'terms' }));

vi.mock('@tanstack/react-router', () => ({ useParams: () => ({ document: route.document }) }));
vi.mock('@/api/settings/queries', () => ({ useSiteSettings: () => null }));
vi.mock('@/components/elements/ScreenBlock', () => ({ NotFound: () => <p>Not found</p> }));

const page = window as Window & { LegalPage?: object };

afterEach(cleanup);
beforeEach(() => {
    route.document = 'terms';
    page.LegalPage = {
        document: 'terms',
        title: 'Terms of Service',
        content: '# Terms of Service\n\nPlay fair. <script>alert(1)</script>',
        version: 3,
        updatedAt: '2026-11-20T12:00:00+00:00',
    };
});

describe('legal page', () => {
    it('shows the document the server rendered with its last update', () => {
        render(<LegalPage />);

        expect(screen.getByRole('heading', { level: 1, name: 'Terms of Service' })).toBeVisible();
        expect(screen.getByText(/Last updated 20 November 2026/)).toHaveTextContent('Version 3');
        expect(screen.getByText(/Play fair\./)).toHaveTextContent('<script>alert(1)</script>');
        expect(document.querySelector('script')).toBeNull();
        expect(screen.getByRole('link', { name: 'Terms' })).toHaveAttribute('aria-current', 'page');
        expect(screen.getByRole('link', { name: 'Refunds' })).toHaveAttribute('href', '/legal/refunds');
    });

    it('does not show another document than the one in the address', () => {
        route.document = 'privacy';

        render(<LegalPage />);

        expect(screen.queryByText(/Play fair/)).toBeNull();
        expect(screen.getByRole('link', { name: 'Open the document' })).toHaveAttribute('href', '/legal/privacy');
    });

    it('knows only the three documents', () => {
        route.document = 'cookies';

        render(<LegalPage />);

        expect(screen.getByText('Not found')).toBeVisible();
    });
});
