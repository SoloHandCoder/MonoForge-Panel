/** @vitest-environment jsdom */

import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type * as ReferralsApi from '@/api/account/referrals';
import { mapReferralOverview, referralLink, type Referral, type ReferralOverview } from '@/api/account/referrals';
import ReferralsContainer, { referralNote } from './ReferralsContainer';

const mocks = vi.hoisted(() => ({ useReferrals: vi.fn() }));

vi.mock('@/api/account/referrals', async (importOriginal) => ({
    ...(await importOriginal<typeof ReferralsApi>()),
    useReferrals: mocks.useReferrals,
}));
vi.mock('@/components/elements/PageContentBlock', () => ({
    default: ({ children }: { children: React.ReactNode }) => <main>{children}</main>,
}));

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});

const referral = (overrides: Partial<Referral> = {}): Referral => ({
    id: 1,
    username: 'al•••',
    status: 'pending',
    rewardTokens: null,
    createdAt: '2026-11-15T12:00:00+00:00',
    ...overrides,
});

const overview: ReferralOverview = mapReferralOverview({
    enabled: true,
    code: 'K7Q2M9XA',
    path: '/r/K7Q2M9XA',
    reward_tokens: 200,
    hold_days: 30,
    stats: { signed_up: 2, pending: 1, paid: 1, tokens_earned: 200 },
    referrals: [
        {
            id: 2,
            username: 'sa•••',
            status: 'paid',
            reward_tokens: 200,
            created_at: '2026-11-14T12:00:00+00:00',
        },
        {
            id: 1,
            username: 'al•••',
            status: 'pending',
            reward_tokens: null,
            created_at: '2026-11-15T12:00:00+00:00',
        },
    ],
});

describe('referrals page', () => {
    it('builds the link on the panel address', () => {
        expect(referralLink('/r/K7Q2M9XA', 'https://panel.example.com/')).toBe('https://panel.example.com/r/K7Q2M9XA');
    });

    it('explains each referral in words', () => {
        expect(referralNote(referral())).toMatch(/^Pending/);
        expect(referralNote(referral({ status: 'paid', rewardTokens: 200 }))).toBe('You got 200 tokens.');
        expect(referralNote(referral({ status: 'not_eligible' }))).toBe('Not eligible for a reward.');
        // No review details and no dates reach the referrer.
        expect(referralNote(referral())).not.toMatch(/review|20\d\d/i);
    });

    it('shows the link, the stats and the referrals', () => {
        mocks.useReferrals.mockReturnValue({ data: overview, error: null, refetch: vi.fn() });
        render(<ReferralsContainer />);

        expect(screen.getByLabelText('Referral link')).toHaveValue(`${window.location.origin}/r/K7Q2M9XA`);
        expect(screen.getByRole('button', { name: 'Copy link' })).toBeVisible();
        expect(screen.getByText('Tokens earned').nextSibling).toHaveTextContent('200');
        expect(screen.queryByText(/under review/i)).toBeNull();
        expect(screen.getAllByText('Pending')).toHaveLength(2);
        expect(screen.getAllByText('Paid')).toHaveLength(2);
    });

    it('says when the programme is paused and shows no link', () => {
        mocks.useReferrals.mockReturnValue({
            data: { ...overview, enabled: false, code: null, path: null },
            error: null,
            refetch: vi.fn(),
        });
        render(<ReferralsContainer />);

        expect(screen.getByText('Referrals are paused')).toBeVisible();
        expect(screen.queryByLabelText('Referral link')).toBeNull();
    });
});
