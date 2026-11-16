// The admin Shop → Coupons and Shop → Referrals tabs (phase 7 Task C), through the generated client.
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import {
    adminApproveReferralMutation,
    adminCreateCouponMutation,
    adminDeleteCouponMutation,
    adminGetReferralSettingsOptions,
    adminListCouponRedemptionsOptions,
    adminListCouponsOptions,
    adminListReferralsOptions,
    adminRejectReferralMutation,
    adminUpdateCouponMutation,
    adminUpdateReferralSettingsMutation,
} from '@/api/generated/@tanstack/react-query.gen';
import type { AdminCreateCouponRequest } from '@/api/generated';
import { invalidateGeneratedOperations } from '@/api/mutationUtils';
import { notifyHttpError } from '@/plugins/notifications';

export type CouponType = 'percent' | 'fixed' | 'tokens';

export interface AdminCoupon {
    id: number;
    code: string;
    type: CouponType;
    value: number;
    description: string | null;
    /** Null is no limit. */
    max_redemptions: number | null;
    redemptions_count: number;
    valid_from: string | null;
    valid_until: string | null;
    /** Empty (with no categories) allows every plan. */
    plan_ids: number[];
    category_ids: number[];
    new_customers_only: boolean;
    enabled: boolean;
    created_at: string | null;
}

export type AdminCouponInput = AdminCreateCouponRequest;

export interface CouponRedemption {
    id: number;
    coupon: { id: number; code: string; type: CouponType };
    user: { id: number; username: string; email: string } | null;
    tokens: number;
    server: { id: number; uuid: string; name: string } | null;
    created_at: string;
}

export interface Page<T> {
    data: T[];
    meta: { current_page: number; last_page: number; total: number };
}

export type ReferralStatus = 'registered' | 'held' | 'paid' | 'reversed' | 'rejected' | 'capped';

export interface AdminReferral {
    id: number;
    referrer: { id: number; username: string; email: string } | null;
    referred: { id: number; username: string; email: string } | null;
    status: ReferralStatus;
    flagged: boolean;
    flag_reason: string | null;
    awaiting_review: boolean;
    reviewed_by: { id: number; username: string; email: string } | null;
    reviewed_at: string | null;
    tebex_transaction_id: string | null;
    reward_tokens: number | null;
    qualified_at: string | null;
    payout_after: string | null;
    paid_at: string | null;
    reversed_at: string | null;
    created_at: string | null;
}

export interface ReferralSettings {
    enabled: boolean;
    reward_tokens: number;
    /** 0 is no cap. */
    monthly_cap: number;
    hold_days: number;
}

export const couponTypeLabels = {
    percent: '% off the first period',
    fixed: 'Tokens off the first period',
    tokens: 'Free tokens (wallet)',
} satisfies Record<CouponType, string>;

/** What a coupon gives, in a few words: "20% off", "100 tokens off", "500 free tokens". */
export const couponValueLabel = (type: CouponType, value: number): string => {
    const amount = new Intl.NumberFormat('en-US').format(value);

    switch (type) {
        case 'percent':
            return `${value}% off`;
        case 'fixed':
            return `${amount} tokens off`;
        case 'tokens':
            return `${amount} free tokens`;
    }
};

// The generated response types mark every field optional (they come from the examples), so the
// payloads are read through the exact shapes above.
export const useAdminCoupons = (page: number, search: string) =>
    useQuery({
        ...adminListCouponsOptions({ query: { page, search: search.trim() || undefined } }),
        placeholderData: keepPreviousData,
        select: (data): Page<AdminCoupon> => data as Page<AdminCoupon>,
    });

export const useCouponRedemptions = (page: number, couponId: number | null) =>
    useQuery({
        ...adminListCouponRedemptionsOptions({ query: { page, coupon_id: couponId ?? undefined } }),
        placeholderData: keepPreviousData,
        select: (data): Page<CouponRedemption> => data as Page<CouponRedemption>,
    });

const refreshCoupons = (queryClient: ReturnType<typeof useQueryClient>) =>
    invalidateGeneratedOperations(queryClient, ['adminListCoupons', 'adminListCouponRedemptions']);

export const useCreateCoupon = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...adminCreateCouponMutation(),
        onSuccess: () => {
            toast.success('Coupon created');
        },
        onSettled: () => refreshCoupons(queryClient),
        onError: (error) => notifyHttpError(error, 'Unable to create the coupon'),
    });
};

export const useUpdateCoupon = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...adminUpdateCouponMutation(),
        onSuccess: () => {
            toast.success('Coupon saved');
        },
        onSettled: () => refreshCoupons(queryClient),
        onError: (error) => notifyHttpError(error, 'Unable to save the coupon'),
    });
};

export const useDeleteCoupon = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...adminDeleteCouponMutation(),
        onSuccess: () => {
            toast.success('Coupon deleted');
        },
        onSettled: () => refreshCoupons(queryClient),
        onError: (error) => notifyHttpError(error, 'Unable to delete the coupon'),
    });
};

export const useReferralSettings = () =>
    useQuery({
        ...adminGetReferralSettingsOptions(),
        select: (data): ReferralSettings => data as ReferralSettings,
    });

export const useSaveReferralSettings = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...adminUpdateReferralSettingsMutation(),
        onSuccess: () => {
            toast.success('Referral settings saved');
        },
        onSettled: () => invalidateGeneratedOperations(queryClient, ['adminGetReferralSettings']),
        onError: (error) => notifyHttpError(error, 'Unable to save the referral settings'),
    });
};

export const useAdminReferrals = (page: number, flagged: boolean) =>
    useQuery({
        ...adminListReferralsOptions({ query: { page, flagged: flagged || undefined } }),
        placeholderData: keepPreviousData,
        select: (data): Page<AdminReferral> => data as Page<AdminReferral>,
    });

const useReviewReferral = (mutation: typeof adminApproveReferralMutation, done: string, failed: string) => {
    const queryClient = useQueryClient();

    return useMutation({
        ...mutation(),
        onSuccess: () => {
            toast.success(done);
        },
        onSettled: () => invalidateGeneratedOperations(queryClient, ['adminListReferrals']),
        onError: (error) => notifyHttpError(error, failed),
    });
};

export const useApproveReferral = () =>
    useReviewReferral(adminApproveReferralMutation, 'Referral approved', 'Unable to approve the referral');

export const useRejectReferral = () =>
    useReviewReferral(adminRejectReferralMutation, 'Referral rejected', 'Unable to reject the referral');
