import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { adminGetFreeTrials } from '@/api/generated';
import { adminUpdateFreeTrialSettingsMutation } from '@/api/generated/@tanstack/react-query.gen';
import type { UiTone } from '@/themes/primitives';
import { notifyHttpError } from '@/plugins/notifications';

/** The trial lengths the panel accepts, in hours. */
export const trialHourOptions = [24, 48, 72] as const;

export type TrialHours = (typeof trialHourOptions)[number];

/** One of the accepted trial lengths; anything else is the default of 48 hours. */
export const trialHours = (value: number | string | null): TrialHours =>
    trialHourOptions.find((hours) => hours === Number(value)) ?? 48;

export interface ShopTrialSettings {
    enabled: boolean;
    plan_ids: number[];
    hours: TrialHours;
    max_concurrent: number;
    require_verified: boolean;
}

export interface ShopTrialStats {
    started: number;
    running: number;
    converted: number;
    lapsed: number;
    /** Percent of the trials that are over that were kept. */
    conversion_rate: number;
}

export type ShopTrialState = 'running' | 'expired' | 'converted' | 'deleted';

export interface ShopTrialRow {
    id: number;
    state: ShopTrialState;
    user: { id: number; username: string } | null;
    server: { id: number; identifier: string; name: string } | null;
    plan: { id: number; name: string } | null;
    hours: number;
    started_at: string | null;
    ends_at: string;
    converted_at: string | null;
    expired_at: string | null;
}

export interface ShopTrials {
    settings: ShopTrialSettings;
    stats: ShopTrialStats;
    trials: ShopTrialRow[];
}

const trialsKey = ['admin', 'shop', 'trials'] as const;

// The generated response types mark every field optional (they come from examples), so the payload is
// read through the exact shapes above.
export const adminShopTrialsQueryOptions = () =>
    queryOptions({
        queryKey: trialsKey,
        queryFn: async ({ signal }): Promise<ShopTrials> => (await adminGetFreeTrials({ signal })).data as ShopTrials,
    });

export const useAdminShopTrials = () => useQuery(adminShopTrialsQueryOptions());

export const useUpdateShopTrials = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...adminUpdateFreeTrialSettingsMutation(),
        onSuccess: async (_settings, variables) => {
            await queryClient.invalidateQueries({ queryKey: trialsKey });
            toast.success('Trial settings updated', {
                description: variables.body.enabled ? 'Players can start a free trial.' : 'Free trials are off.',
            });
        },
        onError: (error) => notifyHttpError(error, 'Unable to update the trial settings'),
    });
};

export interface TrialStateBadge {
    label: string;
    tone: UiTone;
}

const trialStateBadges = {
    running: { label: 'Running', tone: 'accent' },
    converted: { label: 'Kept', tone: 'success' },
    expired: { label: 'Ended', tone: 'warning' },
    deleted: { label: 'Deleted', tone: 'neutral' },
} as const satisfies Record<ShopTrialState, TrialStateBadge>;

/** The badge of a trial's state. */
export const trialStateBadge = (state: ShopTrialState): TrialStateBadge => trialStateBadges[state];
