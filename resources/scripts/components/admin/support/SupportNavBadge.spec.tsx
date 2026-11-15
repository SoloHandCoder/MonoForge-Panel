/** @vitest-environment jsdom */

import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type * as SupportQueries from '@/api/admin/support/queries';
import SupportNavBadge from './SupportNavBadge';

const summary = vi.hoisted(() => ({ unread: 0 }));

vi.mock('@/api/admin/support/queries', async (importOriginal) => ({
    ...(await importOriginal<typeof SupportQueries>()),
    useAdminSupportSummary: () => ({ data: { data: { unread: summary.unread } } }),
}));

afterEach(cleanup);

describe('support nav badge', () => {
    it('shows how many tickets wait, capped at 99+', () => {
        summary.unread = 4;
        const { rerender } = render(<SupportNavBadge />);

        expect(screen.getByLabelText('4 tickets wait on you').textContent).toBe('4');

        summary.unread = 120;
        rerender(<SupportNavBadge />);
        expect(screen.getByLabelText('120 tickets wait on you').textContent).toBe('99+');
    });

    it('hides at zero', () => {
        summary.unread = 0;
        const { container } = render(<SupportNavBadge />);

        expect(container.textContent).toBe('');
    });
});
