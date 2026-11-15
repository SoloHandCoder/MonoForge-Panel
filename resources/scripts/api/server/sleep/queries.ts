import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { invalidateGeneratedOperations } from '@/api/mutationUtils';
import { clientGetServerSleepStateOptions, clientWakeServerMutation } from '@/api/generated/@tanstack/react-query.gen';
import type { ClientServerSleepAttributes } from '@/api/generated';
import { notifyHttpError } from '@/plugins/notifications';

export type ServerSleepState = ClientServerSleepAttributes;

/** How often the server page re-reads the sleep state, so a sleep or a wake shows up. */
export const SLEEP_POLL_INTERVAL = 60_000;

/** Whether auto-sleep stopped the server (pay-as-you-play), for everyone with access to it. */
export const serverSleepQueryOptions = (uuid: string) =>
    queryOptions({
        ...clientGetServerSleepStateOptions({ path: { server_uuid: uuid } }),
        refetchInterval: SLEEP_POLL_INTERVAL,
        staleTime: SLEEP_POLL_INTERVAL / 2,
        select: (resource) => resource.attributes,
    });

export const useServerSleep = (uuid: string): ServerSleepState | null =>
    useQuery(serverSleepQueryOptions(uuid)).data ?? null;

export const wakeServerInput = (uuid: string) => ({ path: { server_uuid: uuid } });

/** "Wake server": starts a sleeping server. */
export const useWakeServer = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...clientWakeServerMutation(),
        onSuccess: async () => {
            await invalidateGeneratedOperations(queryClient, [
                'clientGetServerSleepState',
                'clientListServerSubscriptions',
            ]);
            toast.success('Waking the server. It is starting now.');
        },
        onError: (error) => notifyHttpError(error, 'Unable to wake the server'),
    });
};
