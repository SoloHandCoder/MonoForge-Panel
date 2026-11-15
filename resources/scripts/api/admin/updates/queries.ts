import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';

import {
    adminApplyUpdateMutation,
    adminCheckForUpdatesMutation,
    adminGetUpdateStatusOptions,
    adminGetUpdateStatusQueryKey,
    adminGetVersionInformationQueryKey,
    adminUpdateUpdateSettingsMutation,
} from '@/api/generated/@tanstack/react-query.gen';
import type { AdminGetUpdateStatusResponse, AdminUpdateUpdateSettingsData, Options } from '@/api/generated';
import { notifyHttpError } from '@/plugins/notifications';

export type AdminUpdateStatus = AdminGetUpdateStatusResponse;
export type AdminUpdateMode = AdminUpdateStatus['mode'];
export type AdminUpdateState = AdminUpdateStatus['status']['state'];

/** States in which an update has been asked for but has not finished yet. */
export const isUpdateInProgress = (state: AdminUpdateState | undefined): boolean =>
    state === 'queued' || state === 'running';

export const updateAdminUpdateModeInput = (mode: AdminUpdateMode): Options<AdminUpdateUpdateSettingsData> => ({
    body: { mode },
});

type AdminUpdateStatusQueryOptions = Pick<
    ReturnType<typeof adminGetUpdateStatusOptions>,
    'enabled' | 'refetchInterval'
>;

export const useAdminUpdateStatus = (options?: AdminUpdateStatusQueryOptions) =>
    useQuery({ ...adminGetUpdateStatusOptions(), ...options });

export const useApplyAdminUpdate = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...adminApplyUpdateMutation(),
        onSuccess: (status) => {
            queryClient.setQueryData(adminGetUpdateStatusQueryKey(), status);
            toast.success('Update queued', {
                description: 'The panel will install the new build within a minute and briefly go into maintenance.',
            });
        },
        onError: (error) => notifyHttpError(error, 'Unable to start the update'),
    });
};

/** The toast shown for the result of a "Check now". */
export interface UpdateCheckToast {
    type: 'success' | 'info' | 'error';
    title: string;
}

/** What a "Check now" result means for the admin, as a toast. */
export const updateCheckToast = (status: AdminUpdateStatus): UpdateCheckToast => {
    if (status.check === 'off') {
        return { type: 'info', title: 'Update checks are turned off' };
    }

    if (status.update_available && status.latest) {
        return { type: 'info', title: `Update available: ${status.latest.version}` };
    }

    if (status.check === 'unknown' || !status.latest) {
        return { type: 'error', title: 'Could not check GitHub for updates' };
    }

    return { type: 'success', title: 'Up to date' };
};

/** Asks GitHub for the latest build right away instead of using the cached result. */
export const useCheckAdminUpdates = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...adminCheckForUpdatesMutation(),
        onSuccess: async (status) => {
            queryClient.setQueryData(adminGetUpdateStatusQueryKey(), status);
            await queryClient.invalidateQueries({ queryKey: adminGetVersionInformationQueryKey() });
            const result = updateCheckToast(status);

            toast[result.type](result.title);
        },
        onError: (error) => notifyHttpError(error, 'Unable to check for updates'),
    });
};

export const useUpdateAdminUpdateMode = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...adminUpdateUpdateSettingsMutation(),
        onSuccess: async () => {
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: adminGetUpdateStatusQueryKey() }),
                queryClient.invalidateQueries({ queryKey: adminGetVersionInformationQueryKey() }),
            ]);
            toast.success('Update settings saved');
        },
        onError: (error) => notifyHttpError(error, 'Unable to save the update settings'),
    });
};
