import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';

import {
    adminApplyUpdateMutation,
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
