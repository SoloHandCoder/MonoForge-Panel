import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import {
    adminGetStatusPageSettingsOptions,
    adminUpdateStatusPageSettingsMutation,
    adminGetStatusPageSettingsQueryKey,
} from '@/api/generated/@tanstack/react-query.gen';
import type { AdminGetStatusPageSettingsResponse, AdminUpdateStatusPageSettingsData, Options } from '@/api/generated';
import { notifyHttpError } from '@/plugins/notifications';

/** A node as the status page settings show it: the admin sees its real name, visitors only the display name. */
export interface StatusPageNode {
    id: number;
    name: string;
    location: string;
    visible: boolean;
    /** The name the status page shows: the custom one, else the default. */
    displayName: string;
    /** Blank when the default name is used. */
    customName: string;
    defaultName: string;
}

export interface AdminStatusPageSettings {
    enabled: boolean;
    publicUrl: string;
    nodes: StatusPageNode[];
}

export interface StatusPageSettingsValues {
    enabled: boolean;
    nodes: { id: number; visible: boolean; customName: string }[];
}

/** The longest display name the panel accepts. */
export const STATUS_NAME_MAX_LENGTH = 40;

export const toAdminStatusPageSettings = (response: AdminGetStatusPageSettingsResponse): AdminStatusPageSettings => ({
    enabled: response.enabled ?? true,
    publicUrl: response.public_url ?? '/status',
    nodes: (response.nodes ?? []).map((node) => ({
        id: node.id ?? 0,
        name: node.name ?? '',
        location: node.location ?? '',
        visible: node.visible ?? false,
        displayName: node.display_name ?? '',
        customName: node.custom_name ?? '',
        defaultName: node.default_name ?? '',
    })),
});

export const updateAdminStatusPageSettingsInput = (
    values: StatusPageSettingsValues
): Options<AdminUpdateStatusPageSettingsData> => ({
    body: {
        enabled: values.enabled,
        nodes: values.nodes.map((node) => ({
            id: node.id,
            visible: node.visible,
            display_name: node.customName.trim() === '' ? null : node.customName.trim(),
        })),
    },
});

export const adminStatusPageSettingsQueryOptions = () => adminGetStatusPageSettingsOptions();

export const useAdminStatusPageSettings = () =>
    useQuery({ ...adminStatusPageSettingsQueryOptions(), select: toAdminStatusPageSettings });

export const useUpdateAdminStatusPageSettings = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...adminUpdateStatusPageSettingsMutation(),
        onSuccess: (response) => {
            queryClient.setQueryData(adminGetStatusPageSettingsQueryKey(), response);
            toast.success('Status page updated', {
                description: 'The public status page shows your changes within a minute.',
            });
        },
        onError: (error) => notifyHttpError(error, 'Unable to update the status page'),
    });
};
