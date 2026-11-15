import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { invalidateGeneratedOperations } from '@/api/mutationUtils';
import {
    adminCreateGlobalPermissionTemplateMutation,
    adminDeleteGlobalPermissionTemplateMutation,
    adminListGlobalPermissionTemplatesOptions,
    adminUpdateGlobalPermissionTemplateMutation,
} from '@/api/generated/@tanstack/react-query.gen';
import type { AdminPermissionTemplateAttributes } from '@/api/generated';
import { notifyHttpError } from '@/plugins/notifications';

export type AdminPermissionTemplate = AdminPermissionTemplateAttributes;

/** The panel keeps up to this many global templates. */
export const GLOBAL_TEMPLATE_LIMIT = 50;

export const adminPermissionTemplatesQueryOptions = () => adminListGlobalPermissionTemplatesOptions();

/** The global subuser permission templates, offered to every user next to the built-in ones. */
export const useAdminPermissionTemplates = () =>
    useQuery({
        ...adminPermissionTemplatesQueryOptions(),
        select: (response): AdminPermissionTemplate[] => response.data.map((item) => item.attributes),
    });

// The user-facing template lists include the global templates, so they refresh too.
const refresh = (queryClient: ReturnType<typeof useQueryClient>) =>
    invalidateGeneratedOperations(queryClient, ['adminListGlobalPermissionTemplates', 'clientListPermissionTemplates']);

export const useCreateAdminPermissionTemplate = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...adminCreateGlobalPermissionTemplateMutation(),
        onSuccess: () => {
            toast.success('Template created', { description: 'Every user can now pick it when inviting a subuser.' });
        },
        onSettled: () => refresh(queryClient),
        onError: (error) => notifyHttpError(error, 'Unable to create the template'),
    });
};

export const useUpdateAdminPermissionTemplate = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...adminUpdateGlobalPermissionTemplateMutation(),
        onSuccess: () => {
            toast.success('Template saved', { description: 'Subusers invited with it keep their permissions.' });
        },
        onSettled: () => refresh(queryClient),
        onError: (error) => notifyHttpError(error, 'Unable to save the template'),
    });
};

export const useDeleteAdminPermissionTemplate = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...adminDeleteGlobalPermissionTemplateMutation(),
        onSuccess: () => {
            toast.success('Template deleted');
        },
        onSettled: () => refresh(queryClient),
        onError: (error) => notifyHttpError(error, 'Unable to delete the template'),
    });
};
