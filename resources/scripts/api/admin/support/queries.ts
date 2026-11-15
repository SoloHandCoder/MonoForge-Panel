import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { invalidateGeneratedOperations, resourceMutationMessages } from '@/api/mutationUtils';
import {
    adminCreateACannedReplyMutation,
    adminCreateASupportCategoryMutation,
    adminDeleteACannedReplyMutation,
    adminDeleteASupportCategoryMutation,
    adminGetASupportTicketOptions,
    adminGetSupportOptionsOptions,
    adminGetTheSupportSummaryOptions,
    adminListCannedRepliesOptions,
    adminListSupportCategoriesOptions,
    adminListSupportTicketsOptions,
    adminReplyToASupportTicketMutation,
    adminUpdateACannedReplyMutation,
    adminUpdateASupportCategoryMutation,
    adminUpdateASupportTicketMutation,
} from '@/api/generated/@tanstack/react-query.gen';
import type {
    AdminCreateACannedReplyData,
    AdminCreateASupportCategoryData,
    AdminDeleteACannedReplyData,
    AdminDeleteASupportCategoryData,
    AdminGetASupportTicketResponseBody,
    AdminGetSupportOptionsResponseBody,
    AdminListCannedRepliesResponseBody,
    AdminListSupportCategoriesResponseBody,
    AdminListSupportTicketsData,
    AdminListSupportTicketsResponseBody,
    AdminReplyToASupportTicketData,
    AdminUpdateACannedReplyData,
    AdminUpdateASupportCategoryData,
    AdminUpdateASupportTicketData,
    Options,
} from '@/api/generated';
import { adminGetASupportAttachment } from '@/api/generated';
import { appendSupportImages, type SupportAttachment } from '@/api/support/queries';
import { notifyHttpError } from '@/plugins/notifications';

export type AdminSupportTicketPage = AdminListSupportTicketsResponseBody;
export type AdminSupportTicket = AdminListSupportTicketsResponseBody['data'][number];
export type AdminSupportThread = AdminGetASupportTicketResponseBody['data'];
export type AdminSupportMessage = AdminSupportThread['messages'][number];
export type AdminSupportOptions = AdminGetSupportOptionsResponseBody['data'];
export type CannedReply = AdminListCannedRepliesResponseBody['data'][number];
export type AdminSupportCategory = AdminListSupportCategoriesResponseBody['data'][number];
export type AdminSupportFilters = NonNullable<AdminListSupportTicketsData['query']>;
export type AdminSupportStatusFilter = NonNullable<AdminSupportFilters['status']>;

/** The sidebar badge polls this; a minute is plenty for a support queue. */
export const adminSupportSummaryQueryOptions = (): ReturnType<typeof adminGetTheSupportSummaryOptions> => ({
    ...adminGetTheSupportSummaryOptions(),
    staleTime: 30_000,
    refetchInterval: 60_000,
});

export const useAdminSupportSummary = (enabled = true) => useQuery({ ...adminSupportSummaryQueryOptions(), enabled });

export const adminSupportOptionsQueryOptions = (): ReturnType<typeof adminGetSupportOptionsOptions> => ({
    ...adminGetSupportOptionsOptions(),
    staleTime: 60_000,
});

export const useAdminSupportOptions = () =>
    useQuery({ ...adminSupportOptionsQueryOptions(), select: (response): AdminSupportOptions => response.data });

export const adminSupportTicketsQueryOptions = (filters: AdminSupportFilters) =>
    adminListSupportTicketsOptions({ query: { per_page: 25, ...filters } });

export const useAdminSupportTickets = (filters: AdminSupportFilters) =>
    useQuery({
        ...adminSupportTicketsQueryOptions(filters),
        placeholderData: keepPreviousData,
        refetchInterval: 60_000,
    });

export const adminSupportTicketQueryOptions = (ticket: number) => adminGetASupportTicketOptions({ path: { ticket } });

export const useAdminSupportTicket = (ticket: number) =>
    useQuery({ ...adminSupportTicketQueryOptions(ticket), refetchInterval: 30_000 });

export const adminCannedRepliesQueryOptions = () => adminListCannedRepliesOptions();

export const useCannedReplies = () => useQuery(adminCannedRepliesQueryOptions());

export const adminSupportCategoriesQueryOptions = () => adminListSupportCategoriesOptions();

export const useAdminSupportCategories = () => useQuery(adminSupportCategoriesQueryOptions());

export const staffReplyInput = (
    ticket: number,
    message: string,
    internal: boolean,
    attachments: File[]
): Options<AdminReplyToASupportTicketData> => {
    const body = { body: message.trim(), internal, attachments };

    return {
        path: { ticket },
        body,
        bodySerializer: () => {
            const data = new FormData();

            data.append('body', body.body);
            data.append('internal', body.internal ? '1' : '0');

            return appendSupportImages(data, body.attachments);
        },
    };
};

export const updateSupportTicketInput = (
    ticket: number,
    body: AdminUpdateASupportTicketData['body']
): Options<AdminUpdateASupportTicketData> => ({ path: { ticket }, body });

export const createCannedReplyInput = (title: string, body: string): Options<AdminCreateACannedReplyData> => ({
    body: { title: title.trim(), body: body.trim() },
});

export const updateCannedReplyInput = (
    id: number,
    title: string,
    body: string
): Options<AdminUpdateACannedReplyData> => ({
    path: { canned_reply: id },
    body: { title: title.trim(), body: body.trim() },
});

export const deleteCannedReplyInput = (id: number, title: string): Options<AdminDeleteACannedReplyData> => ({
    path: { canned_reply: id },
    meta: { name: title },
});

export const createSupportCategoryInput = (name: string, sort: number): Options<AdminCreateASupportCategoryData> => ({
    body: { name: name.trim(), sort },
});

export const updateSupportCategoryInput = (
    id: number,
    name: string,
    sort: number
): Options<AdminUpdateASupportCategoryData> => ({ path: { support_category: id }, body: { name: name.trim(), sort } });

export const deleteSupportCategoryInput = (id: number, name: string): Options<AdminDeleteASupportCategoryData> => ({
    path: { support_category: id },
    meta: { name },
});

const ticketOperations = [
    'adminListSupportTickets',
    'adminGetASupportTicket',
    'adminGetTheSupportSummary',
    'clientListNotices',
];

export const useStaffReply = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...adminReplyToASupportTicketMutation(),
        onSuccess: async (_data, variables) => {
            await invalidateGeneratedOperations(queryClient, ticketOperations);
            toast.success(variables.body.internal ? 'Note added' : 'Reply sent', {
                description: variables.body.internal ? 'Only staff can see it.' : 'The player is notified.',
            });
        },
        onError: (error) => notifyHttpError(error, 'Could not send the reply'),
    });
};

export const useUpdateSupportTicket = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...adminUpdateASupportTicketMutation(),
        onSuccess: async () => {
            await invalidateGeneratedOperations(queryClient, ticketOperations);
        },
        onError: (error) => notifyHttpError(error, 'Could not update the ticket'),
    });
};

const cannedOperations = ['adminListCannedReplies'];
const categoryOperations = ['adminListSupportCategories', 'adminGetSupportOptions', 'clientGetSupportOptions'];

export const useCreateCannedReply = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...adminCreateACannedReplyMutation(),
        onSuccess: async (reply) => {
            await invalidateGeneratedOperations(queryClient, cannedOperations);
            const messages = resourceMutationMessages('canned reply', 'create', reply.data.title);

            toast.success(messages.success.title, { description: messages.success.description });
        },
        onError: (error) => notifyHttpError(error, resourceMutationMessages('canned reply', 'create').errorTitle),
    });
};

export const useUpdateCannedReply = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...adminUpdateACannedReplyMutation(),
        onSuccess: async (reply) => {
            await invalidateGeneratedOperations(queryClient, cannedOperations);
            const messages = resourceMutationMessages('canned reply', 'update', reply.data.title);

            toast.success(messages.success.title, { description: messages.success.description });
        },
        onError: (error) => notifyHttpError(error, resourceMutationMessages('canned reply', 'update').errorTitle),
    });
};

export const useDeleteCannedReply = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...adminDeleteACannedReplyMutation(),
        onSuccess: async (_data, variables) => {
            await invalidateGeneratedOperations(queryClient, cannedOperations);
            const messages = resourceMutationMessages('canned reply', 'delete', variables.meta?.name as string);

            toast.success(messages.success.title, { description: messages.success.description });
        },
        onError: (error) => notifyHttpError(error, resourceMutationMessages('canned reply', 'delete').errorTitle),
    });
};

export const useCreateSupportCategory = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...adminCreateASupportCategoryMutation(),
        onSuccess: async (category) => {
            await invalidateGeneratedOperations(queryClient, categoryOperations);
            const messages = resourceMutationMessages('category', 'create', category.data.name);

            toast.success(messages.success.title, { description: messages.success.description });
        },
        onError: (error) => notifyHttpError(error, resourceMutationMessages('category', 'create').errorTitle),
    });
};

export const useUpdateSupportCategory = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...adminUpdateASupportCategoryMutation(),
        onSuccess: async (category) => {
            await invalidateGeneratedOperations(queryClient, categoryOperations);
            const messages = resourceMutationMessages('category', 'update', category.data.name);

            toast.success(messages.success.title, { description: messages.success.description });
        },
        onError: (error) => notifyHttpError(error, resourceMutationMessages('category', 'update').errorTitle),
    });
};

export const useDeleteSupportCategory = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...adminDeleteASupportCategoryMutation(),
        onSuccess: async (_data, variables) => {
            await invalidateGeneratedOperations(queryClient, categoryOperations);
            const messages = resourceMutationMessages('category', 'delete', variables.meta?.name as string);

            toast.success(messages.success.title, { description: messages.success.description });
        },
        onError: (error) => notifyHttpError(error, resourceMutationMessages('category', 'delete').errorTitle),
    });
};

/** An image on a ticket, internal notes included, as a Blob. */
export const loadAdminAttachment = async (ticket: number, attachment: SupportAttachment): Promise<Blob> => {
    const response = await adminGetASupportAttachment({
        path: { ticket, attachment: attachment.id },
        responseType: 'blob',
    });

    return new Blob([response.data], { type: attachment.mime });
};
