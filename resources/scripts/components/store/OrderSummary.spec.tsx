/** @vitest-environment jsdom */

import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ConfirmPurchaseDialog, type OrderPrice } from './OrderSummary';

vi.mock('@/api/settings/queries', () => ({
    useSiteSettings: () => ({
        legal: {
            pending: [],
            documents: {
                terms: { title: 'Terms of Service', url: '/legal/terms', external: false, version: 2, updatedAt: '' },
                privacy: {
                    title: 'Privacy Policy',
                    url: '/legal/privacy',
                    external: false,
                    version: 1,
                    updatedAt: '',
                },
                refunds: {
                    title: 'Refund Policy',
                    url: 'https://example.com/refunds',
                    external: true,
                    version: 1,
                    updatedAt: '',
                },
            },
        },
    }),
}));

afterEach(cleanup);

const price: OrderPrice = {
    total: 500,
    days: 30,
    lines: null,
    resources: { memory: 2048, disk: 10240, cpu: 100, databases: 0, backups: 1, allocations: 1 },
    updating: false,
    balance: 1000,
    short: false,
    soldOut: false,
};

describe('confirm purchase dialog', () => {
    it('says buying accepts the Terms of Service and Refund Policy, linking both', () => {
        render(
            <ConfirmPurchaseDialog
                open
                title='Survival'
                subtitle='Paper'
                price={price}
                onClose={() => {}}
                onConfirm={() => {}}
            />
        );

        const dialog = screen.getByRole('dialog');

        expect(within(dialog).getByText(/By purchasing you agree to the/)).toBeVisible();
        expect(within(dialog).getByRole('link', { name: 'Terms of Service' })).toHaveAttribute('href', '/legal/terms');
        // An external refund policy is linked where it lives.
        const refunds = within(dialog).getByRole('link', { name: 'Refund Policy' });

        expect(refunds).toHaveAttribute('href', 'https://example.com/refunds');
        expect(refunds).toHaveAttribute('target', '_blank');
        expect(refunds).toHaveAttribute('rel', 'noopener noreferrer');
    });
});
