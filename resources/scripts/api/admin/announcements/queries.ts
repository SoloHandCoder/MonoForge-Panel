import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { invalidateGeneratedOperations, resourceMutationMessages } from '@/api/mutationUtils';
import { MAX_PER_PAGE } from '@/api/pagination';
import {
    adminCreateAnnouncementMutation,
    adminDeleteAnnouncementMutation,
    adminListAnnouncementsOptions,
    adminUpdateAnnouncementMutation,
} from '@/api/generated/@tanstack/react-query.gen';
import type {
    AdminAnnouncementResource,
    AdminCreateAnnouncementData,
    AdminDeleteAnnouncementData,
    AdminUpdateAnnouncementData,
    Options,
} from '@/api/generated';
import { notifyHttpError } from '@/plugins/notifications';

export type AdminAnnouncement = AdminAnnouncementResource;
export type AdminAnnouncementValues = AdminCreateAnnouncementData['body'];
export type AnnouncementLevel = AdminAnnouncementValues['level'];

export const announcementLevels: readonly AnnouncementLevel[] = ['info', 'success', 'warning', 'danger'];

export const createAdminAnnouncementInput = (
    values: AdminAnnouncementValues
): Options<AdminCreateAnnouncementData> => ({ body: values });

export const updateAdminAnnouncementInput = (
    id: number,
    values: AdminAnnouncementValues
): Options<AdminUpdateAnnouncementData> => ({ path: { announcement_id: id }, body: values });

export const deleteAdminAnnouncementInput = (id: number, title?: string): Options<AdminDeleteAnnouncementData> => ({
    path: { announcement_id: id },
    meta: title ? { name: title } : undefined,
});

/** Announcements are few, so the page lists the newest hundred on one page. */
export const adminAnnouncementsQueryOptions = () =>
    adminListAnnouncementsOptions({ query: { per_page: MAX_PER_PAGE } });

export const useAdminAnnouncements = () => useQuery(adminAnnouncementsQueryOptions());

// The panel's own notice feed shows announcements too, so a change refreshes it.
const announcementOperations = ['adminListAnnouncements', 'clientListNotices'];

export const useCreateAdminAnnouncement = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...adminCreateAnnouncementMutation(),
        onSuccess: async (announcement) => {
            await invalidateGeneratedOperations(queryClient, announcementOperations);
            const messages = resourceMutationMessages('announcement', 'create', announcement.attributes.title);

            toast.success(messages.success.title, { description: messages.success.description });
        },
        onError: (error) => notifyHttpError(error, resourceMutationMessages('announcement', 'create').errorTitle),
    });
};

export const useUpdateAdminAnnouncement = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...adminUpdateAnnouncementMutation(),
        onSuccess: async (announcement) => {
            await invalidateGeneratedOperations(queryClient, announcementOperations);
            const messages = resourceMutationMessages('announcement', 'update', announcement.attributes.title);

            toast.success(messages.success.title, { description: messages.success.description });
        },
        onError: (error) => notifyHttpError(error, resourceMutationMessages('announcement', 'update').errorTitle),
    });
};

export const useDeleteAdminAnnouncement = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...adminDeleteAnnouncementMutation(),
        onSuccess: async (_data, variables) => {
            await invalidateGeneratedOperations(queryClient, announcementOperations);
            const messages = resourceMutationMessages('announcement', 'delete', variables.meta?.name);

            toast.success(messages.success.title, { description: messages.success.description });
        },
        onError: (error) => notifyHttpError(error, resourceMutationMessages('announcement', 'delete').errorTitle),
    });
};
