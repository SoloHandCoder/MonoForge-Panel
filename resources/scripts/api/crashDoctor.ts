// The AI crash doctor (phase 6): the crash notice of a server, a crash the console saw, and the
// diagnosis, which is an assistant conversation in crash doctor mode.
import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { assistantStatusQueryKey, notifyAssistantError } from '@/api/assistant';
import {
    clientDiagnoseWithTheCrashDoctorMutation,
    clientDismissACrashMutation,
    clientGetCrashDoctorStatusOptions,
    clientGetCrashDoctorStatusQueryKey,
} from '@/api/generated/@tanstack/react-query.gen';
import { clientReportACrashFromTheConsole } from '@/api/generated/sdk.gen';
import type { ClientGetCrashDoctorStatusResponse } from '@/api/generated';
import { invalidateGeneratedOperations } from '@/api/mutationUtils';

export type CrashDoctorStatus = ClientGetCrashDoctorStatusResponse;

export type ServerCrash = NonNullable<CrashDoctorStatus['crash']>;

export type ServerCrashKind = ServerCrash['kind'];

/** A crash shows within a minute or so without the console open. */
export const CRASH_DOCTOR_POLL_MS = 60_000;

const statusInput = (server: string) => ({ path: { server_uuid: server } });

export const crashDoctorStatusQueryOptions = (server: string) =>
    queryOptions({
        ...clientGetCrashDoctorStatusOptions(statusInput(server)),
        refetchInterval: CRASH_DOCTOR_POLL_MS,
        staleTime: CRASH_DOCTOR_POLL_MS / 2,
    });

export const useCrashDoctorStatus = (server: string, enabled: boolean) =>
    useQuery({ ...crashDoctorStatusQueryOptions(server), enabled });

/** Tells the panel the console saw the server go offline without stopping first; returns the new status. */
export const reportServerCrash = async (server: string, kind: ServerCrashKind): Promise<CrashDoctorStatus> =>
    (await clientReportACrashFromTheConsole({ ...statusInput(server), body: { kind } })).data;

/** Reports a crash and puts the answer in the cache, so the banner shows at once. */
export const useReportServerCrash = (server: string) => {
    const queryClient = useQueryClient();

    return async (kind: ServerCrashKind) => {
        try {
            const status = await reportServerCrash(server, kind);

            queryClient.setQueryData(clientGetCrashDoctorStatusQueryKey(statusInput(server)), status);
        } catch {
            // The scheduler notices the crash as well; a failed report only delays the banner.
        }
    };
};

export const diagnoseCrashInput = (server: string, crash: number | null) => ({
    ...statusInput(server),
    body: { crash_id: crash },
});

/** Starts the crash doctor; billing refusals (402, 403, 409) show as the assistant's usual toasts. */
export const useDiagnoseCrash = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...clientDiagnoseWithTheCrashDoctorMutation(),
        onSuccess: async () => {
            await Promise.all([
                invalidateGeneratedOperations(queryClient, ['clientGetCrashDoctorStatus']),
                queryClient.invalidateQueries({ queryKey: ['assistant'] }),
                queryClient.invalidateQueries({ queryKey: assistantStatusQueryKey }),
            ]);
        },
        onError: (error) => notifyAssistantError(error, 'Unable to start the crash doctor'),
    });
};

export const dismissCrashInput = (server: string, crash: number) => ({
    path: { server_uuid: server, crash_id: crash },
});

export const useDismissCrash = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...clientDismissACrashMutation(),
        onSuccess: async () => {
            await invalidateGeneratedOperations(queryClient, ['clientGetCrashDoctorStatus']);
            toast.success('Crash notice dismissed');
        },
        onError: (error) => notifyAssistantError(error, 'Unable to dismiss the crash notice'),
    });
};
