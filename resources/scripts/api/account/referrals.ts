import { useQuery } from '@tanstack/react-query';
import { clientGetReferralsOptions } from '@/api/generated/@tanstack/react-query.gen';

export type ReferralStatus = 'registered' | 'held' | 'paid' | 'reversed' | 'rejected' | 'capped';

export interface Referral {
    id: number;
    /** The start of the referred username, masked. */
    username: string;
    status: ReferralStatus;
    underReview: boolean;
    rewardTokens: number | null;
    payoutAfter: string | null;
    paidAt: string | null;
    createdAt: string;
}

export interface ReferralOverview {
    enabled: boolean;
    code: string | null;
    /** The link path on the panel, such as `/r/K7Q2M9XA`. */
    path: string | null;
    rewardTokens: number;
    holdDays: number;
    stats: { signedUp: number; pending: number; paid: number; tokensEarned: number };
    referrals: Referral[];
}

/** The exact payload; the generated types mark every field optional (they come from the example). */
interface RawReferralOverview {
    enabled: boolean;
    code: string | null;
    path: string | null;
    reward_tokens: number;
    hold_days: number;
    stats: { signed_up: number; pending: number; paid: number; tokens_earned: number };
    referrals: {
        id: number;
        username: string;
        status: ReferralStatus;
        under_review: boolean;
        reward_tokens: number | null;
        payout_after: string | null;
        paid_at: string | null;
        created_at: string;
    }[];
}

export const mapReferralOverview = (raw: RawReferralOverview): ReferralOverview => ({
    enabled: raw.enabled,
    code: raw.code,
    path: raw.path,
    rewardTokens: raw.reward_tokens,
    holdDays: raw.hold_days,
    stats: {
        signedUp: raw.stats.signed_up,
        pending: raw.stats.pending,
        paid: raw.stats.paid,
        tokensEarned: raw.stats.tokens_earned,
    },
    referrals: raw.referrals.map((referral) => ({
        id: referral.id,
        username: referral.username,
        status: referral.status,
        underReview: referral.under_review,
        rewardTokens: referral.reward_tokens,
        payoutAfter: referral.payout_after,
        paidAt: referral.paid_at,
        createdAt: referral.created_at,
    })),
});

/** The full referral link, on whatever address the panel is opened at. */
export const referralLink = (path: string, origin: string = globalThis.location.origin): string =>
    `${origin.replace(/\/+$/, '')}${path}`;

export const referralStatusLabels = {
    registered: 'Signed up',
    held: 'Pending',
    paid: 'Paid',
    reversed: 'Cancelled',
    rejected: 'Not eligible',
    capped: 'Over the monthly limit',
} satisfies Record<ReferralStatus, string>;

export const referralsQueryOptions = () => clientGetReferralsOptions();

export const useReferrals = () =>
    useQuery({
        ...referralsQueryOptions(),
        select: (data): ReferralOverview => mapReferralOverview(data as RawReferralOverview),
    });
