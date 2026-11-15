import { useEffect, useRef, useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { invalidateGeneratedOperations } from '@/api/mutationUtils';
import {
    clientCloseASupportTicketMutation,
    clientGetASupportTicketOptions,
    clientGetSupportOptionsOptions,
    clientListSupportTicketsOptions,
    clientOpenASupportTicketMutation,
    clientReplyToASupportTicketMutation,
} from '@/api/generated/@tanstack/react-query.gen';
import type {
    ClientCloseASupportTicketData,
    ClientGetASupportTicketResponseBody,
    ClientGetSupportOptionsResponseBody,
    ClientListSupportTicketsResponseBody,
    ClientOpenASupportTicketData,
    ClientReplyToASupportTicketData,
    Options,
} from '@/api/generated';
import { clientGetASupportAttachment } from '@/api/generated';
import { notifyHttpError } from '@/plugins/notifications';

export type SupportTicketPage = ClientListSupportTicketsResponseBody;
export type SupportTicket = ClientListSupportTicketsResponseBody['data'][number];
export type SupportThread = ClientGetASupportTicketResponseBody['data'];
export type SupportMessage = SupportThread['messages'][number];
export type SupportAttachment = SupportMessage['attachments'][number];
export type SupportTicketStatus = SupportTicket['status'];
export type SupportOptions = ClientGetSupportOptionsResponseBody['data'];

/** What the panel accepts for an attachment; the server checks the bytes again. */
export const SUPPORT_IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/webp'] as const;
export const SUPPORT_MAX_IMAGE_BYTES = 5 * 1024 * 1024;
export const SUPPORT_MAX_IMAGES = 3;
export const SUPPORT_MAX_BODY = 10000;

/** Why a picked file cannot be attached, or null when it can. */
export const supportImageProblem = (file: File): string | null => {
    if (!(SUPPORT_IMAGE_TYPES as readonly string[]).includes(file.type)) {
        return `${file.name} is not a PNG, JPEG or WebP image.`;
    }

    return file.size > SUPPORT_MAX_IMAGE_BYTES ? `${file.name} is larger than 5 MB.` : null;
};

/**
 * Adds the images as `attachments[]`, which PHP reads as a list. The generated multipart
 * serializer repeats a bare `attachments` key, of which PHP keeps only the last, so the support
 * inputs build their form themselves.
 */
export const appendSupportImages = (data: FormData, files: readonly File[]): FormData => {
    for (const file of files) {
        data.append('attachments[]', file);
    }

    return data;
};

export interface NewSupportTicket {
    subject: string;
    categoryId: number;
    server: string | null;
    body: string;
    attachments: File[];
}

export const openSupportTicketInput = (ticket: NewSupportTicket): Options<ClientOpenASupportTicketData> => {
    const body = {
        subject: ticket.subject.trim(),
        category_id: ticket.categoryId,
        server: ticket.server ?? undefined,
        body: ticket.body.trim(),
        attachments: ticket.attachments,
    };

    return {
        body,
        bodySerializer: () => {
            const data = new FormData();

            data.append('subject', body.subject);
            data.append('category_id', String(body.category_id));
            if (body.server) {
                data.append('server', body.server);
            }

            data.append('body', body.body);

            return appendSupportImages(data, body.attachments);
        },
    };
};

export const replySupportTicketInput = (
    ticket: number,
    message: string,
    attachments: File[]
): Options<ClientReplyToASupportTicketData> => {
    const body = { body: message.trim(), attachments };

    return {
        path: { ticket },
        body,
        bodySerializer: () => {
            const data = new FormData();

            data.append('body', body.body);

            return appendSupportImages(data, body.attachments);
        },
    };
};

export const closeSupportTicketInput = (ticket: number): Options<ClientCloseASupportTicketData> => ({
    path: { ticket },
});

export const supportOptionsQueryOptions = (): ReturnType<typeof clientGetSupportOptionsOptions> => ({
    ...clientGetSupportOptionsOptions(),
    staleTime: 60_000,
});

export const supportTicketsQueryOptions = (page: number) =>
    clientListSupportTicketsOptions({ query: { page, per_page: 25 } });

export const supportTicketQueryOptions = (ticket: number) => clientGetASupportTicketOptions({ path: { ticket } });

export const useSupportOptions = () =>
    useQuery({ ...supportOptionsQueryOptions(), select: (response): SupportOptions => response.data });

export const useSupportTickets = (page: number) =>
    useQuery({ ...supportTicketsQueryOptions(page), placeholderData: keepPreviousData });

/** The thread, refreshed every 30 seconds while it is open so staff replies show up. */
export const useSupportTicket = (ticket: number) =>
    useQuery({ ...supportTicketQueryOptions(ticket), refetchInterval: 30_000 });

// A reply or a status change shows in the list, the thread and the notice feed.
const supportOperations = ['clientListSupportTickets', 'clientGetASupportTicket', 'clientListNotices'];

export const useOpenSupportTicket = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...clientOpenASupportTicketMutation(),
        onSuccess: async () => {
            await invalidateGeneratedOperations(queryClient, supportOperations);
            toast.success('Ticket opened', { description: 'Our team will reply here and by email.' });
        },
        onError: (error) => notifyHttpError(error, 'Could not open the ticket'),
    });
};

export const useReplySupportTicket = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...clientReplyToASupportTicketMutation(),
        onSuccess: async () => {
            await invalidateGeneratedOperations(queryClient, supportOperations);
        },
        onError: (error) => notifyHttpError(error, 'Could not send the reply'),
    });
};

export const useCloseSupportTicket = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...clientCloseASupportTicketMutation(),
        onSuccess: async () => {
            await invalidateGeneratedOperations(queryClient, supportOperations);
            toast.success('Ticket closed', { description: 'Reply within 7 days to reopen it.' });
        },
        onError: (error) => notifyHttpError(error, 'Could not close the ticket'),
    });
};

/**
 * An object URL for an attachment, fetched with the session like any API call (an `<img src>`
 * to the API would not carry it). The URL is revoked again when the image goes away.
 */
export function useAttachmentObjectUrl(
    key: string,
    load: () => Promise<Blob>
): { src: string | null; failed: boolean } {
    const [state, setState] = useState<{ key: string; src: string | null; failed: boolean }>({
        key,
        src: null,
        failed: false,
    });
    const loader = useRef(load);

    useEffect(() => {
        loader.current = load;
    }, [load]);

    useEffect(() => {
        let objectUrl: string | null = null;
        let cancelled = false;

        loader
            .current()
            .then((blob) => {
                if (cancelled) {
                    return;
                }

                objectUrl = URL.createObjectURL(blob);
                setState({ key, src: objectUrl, failed: false });
            })
            .catch(() => {
                if (!cancelled) {
                    setState({ key, src: null, failed: true });
                }
            });

        return () => {
            cancelled = true;

            if (objectUrl) {
                URL.revokeObjectURL(objectUrl);
            }
        };
    }, [key]);

    return state.key === key ? { src: state.src, failed: state.failed } : { src: null, failed: false };
}

/** An image on one of the player's tickets, as a Blob. */
export const loadClientAttachment = async (ticket: number, attachment: SupportAttachment): Promise<Blob> => {
    const response = await clientGetASupportAttachment({
        path: { ticket, attachment: attachment.id },
        responseType: 'blob',
    });

    return new Blob([response.data], { type: attachment.mime });
};
