/** @vitest-environment jsdom */

import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type * as LegalQueries from '@/api/admin/settings/legal';
import type { AdminLegalDocument } from '@/api/admin/settings/legal';
import { LegalDocumentForm } from './LegalSettingsForm';

const update = vi.hoisted(() => vi.fn());

vi.mock('@/api/admin/settings/legal', async (importOriginal) => ({
    ...(await importOriginal<typeof LegalQueries>()),
    useUpdateAdminLegalDocument: () => ({ mutateAsync: update }),
}));

const terms: AdminLegalDocument = {
    document: 'terms',
    title: 'Terms of Service',
    content: '# Terms of Service\n\nBe nice to **everyone**.',
    isDefault: true,
    externalUrl: '',
    publicUrl: '/legal/terms',
    version: 2,
    updatedAt: '2026-11-20T12:00:00+00:00',
};

afterEach(cleanup);
beforeEach(() => vi.clearAllMocks());

describe('legal settings', () => {
    it('flags the starter text and previews the markdown', async () => {
        render(<LegalDocumentForm document={terms} />);

        expect(screen.getByText(/This is the starter text shipped with the panel/)).toHaveTextContent(
            '[Your name / business]'
        );
        expect(screen.getByText(/Version 2/)).toHaveTextContent('Last updated 20 November 2026');

        await userEvent.click(screen.getByRole('tab', { name: 'Preview' }));

        expect(screen.getByRole('heading', { name: 'Terms of Service' })).toBeVisible();
        expect(screen.getByText('everyone').tagName).toBe('STRONG');
    });

    it('saves the document and asks players to accept it again only when ticked', async () => {
        const user = userEvent.setup();

        render(<LegalDocumentForm document={terms} />);

        fireEvent.change(screen.getByLabelText('Markdown'), { target: { value: '# New terms' } });
        await user.click(screen.getByRole('checkbox', { name: /Ask players to accept again/ }));
        await user.click(screen.getByRole('button', { name: 'Save Terms of Service' }));

        expect(update).toHaveBeenCalledWith({
            path: { document: 'terms' },
            body: { content: '# New terms', external_url: null, reaccept: true },
        });
        // The box is cleared after saving, so the next save does not raise the version again.
        expect(screen.getByRole('checkbox', { name: /Ask players to accept again/ })).not.toBeChecked();
    });

    it('only accepts an https external URL', async () => {
        const user = userEvent.setup();

        render(<LegalDocumentForm document={{ ...terms, document: 'refunds', title: 'Refund Policy' }} />);

        const url = screen.getByLabelText('External URL');

        await user.type(url, 'http://example.com');
        expect(screen.getByText('Use a full https:// address, or leave it blank.')).toBeVisible();

        await user.clear(url);
        await user.type(url, 'https://example.com/refunds');
        await user.click(screen.getByRole('button', { name: 'Save Refund Policy' }));

        expect(update).toHaveBeenCalledWith({
            path: { document: 'refunds' },
            body: { content: terms.content, external_url: 'https://example.com/refunds', reaccept: false },
        });
        expect(screen.getByRole('checkbox', { name: /Save as a new version/ })).toBeVisible();
    });
});
