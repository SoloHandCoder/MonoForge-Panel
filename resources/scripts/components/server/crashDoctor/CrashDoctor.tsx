import { useEffect, useRef } from 'react';
import { useNavigate } from '@tanstack/react-router';
import { Stethoscope } from 'lucide-react';
import { useAssistantStatus } from '@/api/assistant';
import {
    diagnoseCrashInput,
    dismissCrashInput,
    useCrashDoctorStatus,
    useDiagnoseCrash,
    useDismissCrash,
    useReportServerCrash,
    type ServerCrash,
    type ServerCrashKind,
} from '@/api/crashDoctor';
import { useCurrentServerUuid } from '@/api/server/queries';
import { Alert } from '@/components/elements/alert';
import Button from '@/components/elements/Button';
import Icon from '@/components/elements/Icon';
import { fromNow } from '@/lib/dates';
import { isString } from '@/lib/objects';
import { SOCKET_SENT } from '@/plugins/Websocket';
import { usePermissions } from '@/plugins/usePermissions';
import { useServerStatus, useSocketConnected, useSocketInstance, type ServerStatus } from '@/state/server';

/** A stop asked for in this tab this recently explains the server going offline. */
export const STOP_INTENT_MS = 60_000;

const STOP_COMMANDS = new Set(['stop', 'end', 'exit', 'quit', 'shutdown', 'save-quit']);

/** Whether a websocket message from this tab stops the server: a stop, restart or kill, or a stop command. */
export const isStopRequest = (event: string, args: readonly unknown[]): boolean => {
    const [value] = args;

    if (event === 'set state') {
        return value === 'stop' || value === 'restart' || value === 'kill';
    }

    return (
        event === 'send command' && isString(value) && STOP_COMMANDS.has(value.trim().replace(/^\//, '').toLowerCase())
    );
};

/**
 * What the console's state change means: a crash when the server went from running or starting
 * to offline without stopping first (Wings always reports "stopping" for a stop, restart or kill).
 */
export const crashFromTransition = (previous: ServerStatus, next: ServerStatus): ServerCrashKind | null => {
    if (next !== 'offline') {
        return null;
    }

    if (previous === 'running') {
        return 'crashed';
    }

    return previous === 'starting' ? 'start_failed' : null;
};

/**
 * Watches the console's state events and reports a crash at once, so the banner does not wait
 * for the scheduler. Only state changes seen while the socket stayed connected count.
 */
function useCrashListener(server: string, enabled: boolean) {
    const status = useServerStatus();
    const connected = useSocketConnected();
    const instance = useSocketInstance();
    const report = useReportServerCrash(server);
    const previous = useRef<ServerStatus>(null);
    const lastStop = useRef(0);

    useEffect(() => {
        if (!instance) {
            return;
        }

        const listener = (event: string, ...args: unknown[]) => {
            if (isStopRequest(event, args)) {
                lastStop.current = Date.now();
            }
        };

        instance.on(SOCKET_SENT, listener);

        return () => {
            instance.removeListener(SOCKET_SENT, listener);
        };
    }, [instance]);

    useEffect(() => {
        if (!connected) {
            previous.current = null;

            return;
        }

        const kind = crashFromTransition(previous.current, status);

        previous.current = status;

        if (kind && enabled && Date.now() - lastStop.current > STOP_INTENT_MS) {
            void report(kind);
        }
        // `report` is recreated each render; only a change of state matters.
        // oxlint-disable-next-line react/exhaustive-deps
    }, [status, connected, enabled]);
}

const titles = {
    crashed: 'Your server crashed. Let the AI doctor check it?',
    start_failed: 'Your server failed to start. Let the AI doctor check it?',
} satisfies Record<ServerCrashKind, string>;

interface CrashDoctorProps {
    /** The server identifier in the URL. */
    id: string;
    /** The console shows, so the manual "Diagnose" button belongs here. */
    console: boolean;
}

function CrashBanner({ server, id, crash }: { server: string; id: string; crash: ServerCrash }) {
    const navigate = useNavigate();
    const diagnose = useDiagnoseCrash();
    const dismiss = useDismissCrash();
    const busy = diagnose.isPending || dismiss.isPending;

    if (crash.status === 'diagnosing' && crash.conversation_uuid) {
        const conversation = crash.conversation_uuid;

        return (
            <Alert type='info' title='The AI doctor checked the crash'>
                <p>Its diagnosis and the fixes it suggests are in the assistant.</p>
                <div className='mt-3 flex flex-wrap items-center gap-2'>
                    <Button
                        size='small'
                        onClick={() =>
                            void navigate({ to: '/server/$id/assistant', params: { id }, search: { conversation } })
                        }
                    >
                        Open the diagnosis
                    </Button>
                    <Button.Text
                        size='small'
                        disabled={busy}
                        onClick={() => dismiss.mutate(dismissCrashInput(server, crash.id))}
                    >
                        Dismiss
                    </Button.Text>
                </div>
            </Alert>
        );
    }

    return (
        <Alert type='warning' title={titles[crash.kind]}>
            <p>
                Noticed {fromNow(crash.detected_at)}. The doctor reads the console and the latest logs, explains what
                went wrong and suggests fixes that only run when you allow them. It uses your AI tokens.
            </p>
            <div className='mt-3 flex flex-wrap items-center gap-2'>
                <Button
                    size='small'
                    isLoading={diagnose.isPending}
                    disabled={busy}
                    onClick={() =>
                        diagnose.mutate(diagnoseCrashInput(server, crash.id), {
                            onSuccess: (conversation) => {
                                if (conversation.uuid) {
                                    void navigate({
                                        to: '/server/$id/assistant',
                                        params: { id },
                                        search: { conversation: conversation.uuid },
                                    });
                                }
                            },
                        })
                    }
                >
                    <Icon icon={Stethoscope} className='size-4' aria-hidden />
                    Diagnose
                </Button>
                <Button.Text
                    size='small'
                    disabled={busy}
                    onClick={() => dismiss.mutate(dismissCrashInput(server, crash.id))}
                >
                    Dismiss
                </Button.Text>
            </div>
        </Alert>
    );
}

/** "Diagnose with AI" on the console, for a server that misbehaves without a crash notice. */
function ManualDiagnose({ server, id }: { server: string; id: string }) {
    const navigate = useNavigate();
    const diagnose = useDiagnoseCrash();

    return (
        <div className='flex justify-end'>
            <Button.Text
                size='xsmall'
                isLoading={diagnose.isPending}
                disabled={diagnose.isPending}
                title='The AI crash doctor reads the console and the latest logs and suggests fixes. It uses your AI tokens.'
                onClick={() =>
                    diagnose.mutate(diagnoseCrashInput(server, null), {
                        onSuccess: (conversation) => {
                            if (conversation.uuid) {
                                void navigate({
                                    to: '/server/$id/assistant',
                                    params: { id },
                                    search: { conversation: conversation.uuid },
                                });
                            }
                        },
                    })
                }
            >
                <Icon icon={Stethoscope} className='size-4' aria-hidden />
                Diagnose with the AI doctor
            </Button.Text>
        </div>
    );
}

/**
 * The AI crash doctor on a server's pages: the crash notice with "Diagnose", and on the console
 * a manual "Diagnose" button and the listener that reports crashes the console sees. Nothing
 * shows without the `assistant.use` permission or while the assistant or the doctor is off.
 */
export default function CrashDoctor({ id, console: onConsole }: CrashDoctorProps) {
    const server = useCurrentServerUuid() ?? '';
    const [canUse] = usePermissions(['assistant.use']);
    const assistant = useAssistantStatus(canUse);
    const available = canUse && server !== '' && assistant.data?.crash_doctor_enabled === true;
    const status = useCrashDoctorStatus(server, available);
    const enabled = available && status.data?.enabled === true;

    useCrashListener(server, enabled);

    if (!enabled) {
        return null;
    }

    const crash = status.data?.crash ?? null;

    if (crash) {
        return <CrashBanner server={server} id={id} crash={crash} />;
    }

    return onConsole ? <ManualDiagnose server={server} id={id} /> : null;
}
