import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { invalidateGeneratedOperations } from '@/api/mutationUtils';
import {
    adminCancelServerSubscriptionMutation,
    adminExtendServerSubscriptionMutation,
    adminGetServerSubscriptionOptions,
    clientListServerSubscriptionsOptions,
    clientRenewServerSubscriptionMutation,
    clientUpdateServerSubscriptionMutation,
} from '@/api/generated/@tanstack/react-query.gen';
import type { AdminServerSubscriptionResource, ClientServerSubscriptionResource } from '@/api/generated';
import { serverQueryFilters } from '@/api/server/queries';
import { notifyHttpError } from '@/plugins/notifications';

export type ServerSubscription = ClientServerSubscriptionResource['attributes'];
export type AdminServerSubscription = AdminServerSubscriptionResource['attributes'];

/** How often the panel re-reads the billing, so a top-up shows without a reload. */
export const SUBSCRIPTION_POLL_INTERVAL = 60_000;

/** The signed-in user's shop servers and their billing, one request for every card and banner. */
export const serverSubscriptionsQueryOptions = () =>
    queryOptions({
        ...clientListServerSubscriptionsOptions(),
        refetchInterval: SUBSCRIPTION_POLL_INTERVAL,
        staleTime: SUBSCRIPTION_POLL_INTERVAL / 2,
        select: (list) => list.data.map((item) => item.attributes),
    });

/** The billing of one of the user's servers, or null when it was not bought in the shop. */
export const useServerSubscription = (identifier: string): ServerSubscription | null => {
    const { data } = useQuery(serverSubscriptionsQueryOptions());

    return data?.find((subscription) => subscription.server_identifier === identifier) ?? null;
};

/** The user's subscriptions keyed by server identifier, for the dashboard cards. */
export const useServerSubscriptionMap = (): ReadonlyMap<string, ServerSubscription> => {
    const { data } = useQuery(serverSubscriptionsQueryOptions());

    return new Map((data ?? []).map((subscription) => [subscription.server_identifier, subscription]));
};

const refreshAfterBilling = async (queryClient: ReturnType<typeof useQueryClient>, identifier: string) => {
    await Promise.all([
        invalidateGeneratedOperations(queryClient, ['clientListServerSubscriptions', 'clientListNotices']),
        // A renewal can unsuspend the server, which changes its status everywhere.
        queryClient.invalidateQueries(serverQueryFilters(identifier)),
        invalidateGeneratedOperations(queryClient, ['clientListServers']),
    ]);
};

/** "Renew now": pays a suspended server (and unsuspends it), or the next period ahead of time. */
export const useRenewServerSubscription = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...clientRenewServerSubscriptionMutation(),
        onSuccess: async (_data, variables) => {
            await refreshAfterBilling(queryClient, variables.path.server_uuid);
            toast.success('Server renewed.');
        },
        onError: (error) => notifyHttpError(error, 'Unable to renew the server'),
    });
};

export const renewServerSubscriptionInput = (identifier: string) => ({ path: { server_uuid: identifier } });

export const updateServerSubscriptionInput = (identifier: string, autoRenew: boolean) => ({
    path: { server_uuid: identifier },
    body: { auto_renew: autoRenew },
});

export const updateAutoSleepInput = (identifier: string, autoSleep: boolean) => ({
    path: { server_uuid: identifier },
    body: { auto_sleep: autoSleep },
});

type SubscriptionChange = { auto_renew?: boolean; auto_sleep?: boolean } | undefined;

/** The toast for a saved change: auto-sleep when it was sent, automatic renewal otherwise. */
export const subscriptionChangeMessage = (body: SubscriptionChange): string => {
    if (body?.auto_sleep !== undefined) {
        return body.auto_sleep ? 'Auto-sleep is on.' : 'Auto-sleep is off.';
    }

    return body?.auto_renew ? 'Automatic renewal is on.' : 'Automatic renewal is off.';
};

export const useUpdateServerSubscription = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...clientUpdateServerSubscriptionMutation(),
        onSuccess: async (_data, variables) => {
            await Promise.all([
                refreshAfterBilling(queryClient, variables.path.server_uuid),
                invalidateGeneratedOperations(queryClient, ['clientGetServerSleepState']),
            ]);
            toast.success(subscriptionChangeMessage(variables.body));
        },
        onError: (error, variables) =>
            notifyHttpError(
                error,
                variables.body?.auto_sleep === undefined
                    ? 'Unable to change automatic renewal'
                    : 'Unable to change auto-sleep'
            ),
    });
};

/** "4h 20m" (or "3d 2h") of sleep, from seconds. */
export const sleepDuration = (seconds: number): string => {
    const minutes = Math.floor(Math.max(0, seconds) / 60);
    const days = Math.floor(minutes / 1440);
    const hours = Math.floor((minutes % 1440) / 60);

    if (days > 0) {
        return `${days}d ${hours}h`;
    }

    return hours > 0 ? `${hours}h ${minutes % 60}m` : `${minutes}m`;
};

const adminInput = (id: number) => ({ path: { server_admin_identifier: String(id) } });

/** The admin view of a server's billing; null when the server was not bought in the shop. */
export const useAdminServerSubscription = (id: number) =>
    useQuery({
        ...adminGetServerSubscriptionOptions(adminInput(id)),
        // A server bought outside the shop answers 404, which is final.
        retry: (count, error) => error.response?.status !== 404 && count < 2,
        select: (resource) => resource.attributes,
    });

const useAdminSubscriptionMutationSettled = () => {
    const queryClient = useQueryClient();

    return () =>
        invalidateGeneratedOperations(queryClient, [
            'adminGetServerSubscription',
            'adminGetServer',
            'adminListServers',
        ]);
};

export const extendAdminServerSubscriptionInput = (id: number, days: number) => ({ ...adminInput(id), body: { days } });

export const cancelAdminServerSubscriptionInput = adminInput;

export const useExtendAdminServerSubscription = () => {
    const settled = useAdminSubscriptionMutationSettled();

    return useMutation({
        ...adminExtendServerSubscriptionMutation(),
        onSuccess: async (_data, variables) => {
            await settled();
            toast.success(`Subscription extended by ${variables.body.days} days.`);
        },
        onError: (error) => notifyHttpError(error, 'Unable to extend the subscription'),
    });
};

export const useCancelAdminServerSubscription = () => {
    const settled = useAdminSubscriptionMutationSettled();

    return useMutation({
        ...adminCancelServerSubscriptionMutation(),
        onSuccess: async () => {
            await settled();
            toast.success('Subscription cancelled. The server is no longer billed.');
        },
        onError: (error) => notifyHttpError(error, 'Unable to cancel the subscription'),
    });
};

/** "6d 23h" until the moment, "0m" once it has passed. */
export const timeUntil = (iso: string, now: number): string => {
    const seconds = Math.max(0, Math.floor((new Date(iso).getTime() - now) / 1000));
    const days = Math.floor(seconds / 86_400);
    const hours = Math.floor((seconds % 86_400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);

    if (days > 0) {
        return `${days}d ${hours}h`;
    }

    return hours > 0 ? `${hours}h ${minutes}m` : `${minutes}m`;
};

/** The dashboard card's line for a suspended shop server, or null when nothing is wrong. */
export const billingNotice = (subscription: ServerSubscription | null, now: number): string | null => {
    if (subscription?.status !== 'suspended') {
        return null;
    }

    const left = subscription.delete_at === null ? '0m' : timeUntil(subscription.delete_at, now);

    return left === '0m' ? 'Suspended — deleting now' : `Suspended — deletes in ${left}. Top up and renew`;
};
