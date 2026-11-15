/** @vitest-environment jsdom */
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type * as CrashDoctorApi from '@/api/crashDoctor';
import type { CrashDoctorStatus } from '@/api/crashDoctor';
import { Websocket } from '@/plugins/Websocket';
import CrashDoctor, { crashFromTransition, isStopRequest } from './CrashDoctor';

const mocks = vi.hoisted(() => ({
    navigate: vi.fn(),
    diagnose: vi.fn(),
    dismiss: vi.fn(),
    report: vi.fn(),
    permission: true,
    assistantEnabled: true,
    status: undefined as CrashDoctorStatus | undefined,
    serverStatus: null as 'offline' | 'starting' | 'stopping' | 'running' | null,
    connected: true,
    socket: null as Websocket | null,
}));

vi.mock('@tanstack/react-router', () => ({ useNavigate: () => mocks.navigate }));
vi.mock('@/plugins/usePermissions', () => ({ usePermissions: () => [mocks.permission] }));
vi.mock('@/api/server/queries', () => ({ useCurrentServerUuid: () => 'srv-uuid' }));
vi.mock('@/api/assistant', () => ({
    useAssistantStatus: () => ({ data: { crash_doctor_enabled: mocks.assistantEnabled } }),
}));
vi.mock('@/state/server', () => ({
    useServerStatus: () => mocks.serverStatus,
    useSocketConnected: () => mocks.connected,
    useSocketInstance: () => mocks.socket,
}));
vi.mock('@/api/crashDoctor', async (importOriginal) => ({
    ...(await importOriginal<typeof CrashDoctorApi>()),
    useCrashDoctorStatus: (_server: string, enabled: boolean) => ({ data: enabled ? mocks.status : undefined }),
    useDiagnoseCrash: () => ({ mutate: mocks.diagnose, isPending: false }),
    useDismissCrash: () => ({ mutate: mocks.dismiss, isPending: false }),
    useReportServerCrash: () => mocks.report,
}));

const crash = (overrides: Partial<NonNullable<CrashDoctorStatus['crash']>> = {}): CrashDoctorStatus => ({
    enabled: true,
    crash: {
        id: 3,
        kind: 'crashed',
        status: 'open',
        detected_at: new Date().toISOString(),
        conversation_uuid: null,
        ...overrides,
    },
});

beforeEach(() => {
    mocks.permission = true;
    mocks.assistantEnabled = true;
    mocks.status = { enabled: true, crash: null };
    mocks.serverStatus = null;
    mocks.connected = true;
    mocks.socket = null;
});

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});

describe('crash detection rules', () => {
    it('takes running or starting to offline for a crash, and nothing else', () => {
        expect(crashFromTransition('running', 'offline')).toBe('crashed');
        expect(crashFromTransition('starting', 'offline')).toBe('start_failed');
        expect(crashFromTransition('stopping', 'offline')).toBeNull();
        expect(crashFromTransition(null, 'offline')).toBeNull();
        expect(crashFromTransition('running', 'stopping')).toBeNull();
    });

    it('knows the requests that stop the server', () => {
        expect(isStopRequest('set state', ['stop'])).toBe(true);
        expect(isStopRequest('set state', ['kill'])).toBe(true);
        expect(isStopRequest('set state', ['restart'])).toBe(true);
        expect(isStopRequest('set state', ['start'])).toBe(false);
        expect(isStopRequest('send command', [' /STOP '])).toBe(true);
        expect(isStopRequest('send command', ['say stop'])).toBe(false);
        expect(isStopRequest('auth', ['token'])).toBe(false);
    });
});

describe('CrashDoctor', () => {
    it('offers the doctor for an open crash and opens the diagnosis', () => {
        mocks.status = crash();
        render(<CrashDoctor id='a1b2c3d4' console={false} />);

        expect(screen.getByText('Your server crashed. Let the AI doctor check it?')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /Diagnose/ }));

        expect(mocks.diagnose).toHaveBeenCalledWith(
            { path: { server_uuid: 'srv-uuid' }, body: { crash_id: 3 } },
            expect.anything()
        );
        const [, options] = mocks.diagnose.mock.calls[0] as [unknown, { onSuccess: (data: { uuid: string }) => void }];

        options.onSuccess({ uuid: 'conv-1' });
        expect(mocks.navigate).toHaveBeenCalledWith({
            to: '/server/$id/assistant',
            params: { id: 'a1b2c3d4' },
            search: { conversation: 'conv-1' },
        });
    });

    it('names a failed start and dismisses the notice', () => {
        mocks.status = crash({ kind: 'start_failed' });
        render(<CrashDoctor id='a1b2c3d4' console />);

        expect(screen.getByText('Your server failed to start. Let the AI doctor check it?')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Dismiss' }));
        expect(mocks.dismiss).toHaveBeenCalledWith({ path: { server_uuid: 'srv-uuid', crash_id: 3 } });
    });

    it('links the diagnosis once the doctor ran', () => {
        mocks.status = crash({ status: 'diagnosing', conversation_uuid: 'conv-9' });
        render(<CrashDoctor id='a1b2c3d4' console={false} />);

        fireEvent.click(screen.getByRole('button', { name: 'Open the diagnosis' }));
        expect(mocks.navigate).toHaveBeenCalledWith({
            to: '/server/$id/assistant',
            params: { id: 'a1b2c3d4' },
            search: { conversation: 'conv-9' },
        });
    });

    it('offers a manual diagnosis on the console only', () => {
        const { rerender } = render(<CrashDoctor id='a1b2c3d4' console />);

        fireEvent.click(screen.getByRole('button', { name: /Diagnose with the AI doctor/ }));
        expect(mocks.diagnose).toHaveBeenCalledWith(
            { path: { server_uuid: 'srv-uuid' }, body: { crash_id: null } },
            expect.anything()
        );

        rerender(<CrashDoctor id='a1b2c3d4' console={false} />);
        expect(screen.queryByRole('button')).toBeNull();
    });

    it.each([
        ['without assistant.use', () => (mocks.permission = false)],
        ['while the doctor is off', () => (mocks.assistantEnabled = false)],
        ['when the panel says it is off', () => (mocks.status = { enabled: false, crash: null })],
    ])('shows nothing %s', (_label, arrange) => {
        mocks.status = crash();
        arrange();
        const { container } = render(<CrashDoctor id='a1b2c3d4' console />);

        expect(container).toBeEmptyDOMElement();
    });
});

describe('the console listener', () => {
    const renderAt = (status: typeof mocks.serverStatus) => {
        mocks.serverStatus = status;

        return <CrashDoctor id='a1b2c3d4' console />;
    };

    it('reports running to offline at once', () => {
        const { rerender } = render(renderAt('running'));

        rerender(renderAt('offline'));

        expect(mocks.report).toHaveBeenCalledWith('crashed');
    });

    it('reports a start that went offline', () => {
        const { rerender } = render(renderAt('starting'));

        rerender(renderAt('offline'));

        expect(mocks.report).toHaveBeenCalledWith('start_failed');
    });

    it('ignores a normal stop', () => {
        const { rerender } = render(renderAt('running'));

        rerender(renderAt('stopping'));
        rerender(renderAt('offline'));

        expect(mocks.report).not.toHaveBeenCalled();
    });

    it('ignores going offline right after this tab asked for a stop', () => {
        const socket = new Websocket();

        mocks.socket = socket;
        const { rerender } = render(renderAt('running'));

        socket.emit('SOCKET_SENT', 'send command', 'stop');
        rerender(renderAt('offline'));

        expect(mocks.report).not.toHaveBeenCalled();
    });

    it('does not compare across a lost connection', () => {
        const { rerender } = render(renderAt('running'));

        mocks.connected = false;
        rerender(renderAt('running'));
        mocks.connected = true;
        rerender(renderAt('offline'));

        expect(mocks.report).not.toHaveBeenCalled();
    });

    it('does not report while the doctor is off', () => {
        mocks.permission = false;
        const { rerender } = render(renderAt('running'));

        rerender(renderAt('offline'));

        expect(mocks.report).not.toHaveBeenCalled();
    });
});
