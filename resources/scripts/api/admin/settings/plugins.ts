import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';

import {
    adminGetPluginSettingsOptions,
    adminGetPluginSettingsQueryKey,
    adminUpdatePluginSettingsMutation,
} from '@/api/generated/@tanstack/react-query.gen';
import type { AdminGetPluginSettingsResponse } from '@/api/generated';
import { notifyHttpError } from '@/plugins/notifications';

export type AdminPluginSettings = AdminGetPluginSettingsResponse;

export const adminPluginSettingsQueryOptions = () => adminGetPluginSettingsOptions();

export const useAdminPluginSettings = () => useQuery(adminPluginSettingsQueryOptions());

/** Sets or clears the CurseForge API key; a blank key keeps the stored one. */
export const useUpdateAdminPluginSettings = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...adminUpdatePluginSettingsMutation(),
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: adminGetPluginSettingsQueryKey() });
            toast.success('Plugin settings updated');
        },
        onError: (error) => notifyHttpError(error, 'Unable to update plugin settings'),
    });
};
