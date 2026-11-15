import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { invalidateGeneratedOperations } from '@/api/mutationUtils';
import {
    clientCreatePermissionTemplateMutation,
    clientDeletePermissionTemplateMutation,
    clientListPermissionTemplatesOptions,
    clientUpdatePermissionTemplateMutation,
} from '@/api/generated/@tanstack/react-query.gen';
import type { ClientPermissionTemplateListAttributes } from '@/api/generated';
import { notifyHttpError } from '@/plugins/notifications';

export type PermissionTemplate = ClientPermissionTemplateListAttributes['templates'][number];

export const permissionTemplatesQueryOptions = () => clientListPermissionTemplatesOptions();

/** The built-in, global and own templates the user can pick when inviting a subuser. */
export const usePermissionTemplates = () => useQuery(permissionTemplatesQueryOptions());

const refresh = (queryClient: ReturnType<typeof useQueryClient>) =>
    invalidateGeneratedOperations(queryClient, ['clientListPermissionTemplates']);

export const useCreatePermissionTemplate = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...clientCreatePermissionTemplateMutation(),
        onSuccess: (template) => {
            toast.success('Template saved', { description: `"${template.attributes.name}" is in your templates.` });
        },
        onSettled: () => refresh(queryClient),
        onError: (error) => notifyHttpError(error, 'Unable to save the template'),
    });
};

export const useUpdatePermissionTemplate = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...clientUpdatePermissionTemplateMutation(),
        onSuccess: (template) => {
            toast.success('Template saved', {
                description: `Subusers invited with "${template.attributes.name}" keep their permissions.`,
            });
        },
        onSettled: () => refresh(queryClient),
        onError: (error) => notifyHttpError(error, 'Unable to save the template'),
    });
};

export const useDeletePermissionTemplate = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...clientDeletePermissionTemplateMutation(),
        onSuccess: () => {
            toast.success('Template deleted');
        },
        onSettled: () => refresh(queryClient),
        onError: (error) => notifyHttpError(error, 'Unable to delete the template'),
    });
};
