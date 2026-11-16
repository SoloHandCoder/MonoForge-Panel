import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import {
    adminGetServerPublicPageDiscoveryOptions,
    adminGetServerPublicPageDiscoveryQueryKey,
    adminUpdateServerPublicPageDiscoveryMutation,
} from '@/api/generated/@tanstack/react-query.gen';
import type { AdminGetServerPublicPageDiscoveryResponse } from '@/api/generated';
import { notifyHttpError } from '@/plugins/notifications';

export type AdminServerPageDiscovery = AdminGetServerPublicPageDiscoveryResponse;

const pageInput = (id: number) => ({ path: { server_admin_identifier: String(id) } });

export const useAdminServerPageDiscovery = (id: number) =>
    useQuery(adminGetServerPublicPageDiscoveryOptions(pageInput(id)));

/** Hides the server's public page from the discovery page, or lists it again. */
export const useUpdateAdminServerPageDiscovery = (id: number) => {
    const queryClient = useQueryClient();

    return useMutation({
        ...adminUpdateServerPublicPageDiscoveryMutation(),
        onSuccess: (page) => {
            queryClient.setQueryData(adminGetServerPublicPageDiscoveryQueryKey(pageInput(id)), page);
            toast.success(
                page.hidden_from_discovery ? 'Hidden from the discovery page.' : 'Listed on the discovery page again.'
            );
        },
        onError: (error) => notifyHttpError(error, 'Unable to change the discovery listing'),
    });
};

export const updateAdminServerPageDiscoveryInput = (id: number, hidden: boolean) => ({
    ...pageInput(id),
    body: { hidden },
});
