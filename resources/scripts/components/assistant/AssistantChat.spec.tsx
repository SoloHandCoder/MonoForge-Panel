/** @vitest-environment jsdom */
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { PropsWithChildren } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type * as Http from '@/api/http';
import {
    ASSISTANT_POLL_MS,
    serverAssistantScope,
    type AssistantConversation,
    type AssistantConversationList,
} from '@/api/assistant';
import AssistantChat from './AssistantChat';

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }));

vi.mock('@/api/http', async (importOriginal) => ({ ...(await importOriginal<typeof Http>()), default: http }));

const base = '/api/client/servers/srv-uuid/assistant/conversations';

const list: AssistantConversationList = {
    data: [
        {
            uuid: 'c1',
            title: 'Why did it crash?',
            mode: 'chat',
            status: 'running',
            updated_at: '2026-11-15T12:00:00+00:00',
        },
    ],
};

const conversation = (status: AssistantConversation['status']): AssistantConversation => ({
    uuid: 'c1',
    title: 'Why did it crash?',
    mode: 'chat',
    approval_mode: 'ask',
    allow_until_next_message: false,
    allow_everything_available: true,
    status,
    error: null,
    usage: { free_used: 100, free_limit: 50000, charged_this_month: 0 },
    messages: [
        {
            id: 1,
            role: 'user',
            content: 'Why did it crash?',
            notice: null,
            created_at: '2026-11-15T12:00:00+00:00',
            tool_calls: [],
        },
        {
            id: 2,
            role: 'assistant',
            content: 'Let me clean up the old **logs**.',
            notice: null,
            created_at: '2026-11-15T12:00:01+00:00',
            tool_calls: [
                {
                    id: 7,
                    name: 'delete_files',
                    label: 'Delete /logs/old.log',
                    arguments: { root: '/', files: ['logs/old.log'] },
                    details: [{ label: 'Path', value: '/logs/old.log' }],
                    needs_confirmation: true,
                    always_ask: false,
                    auto_approved: null,
                    status: status === 'awaiting_approval' ? 'pending' : 'done',
                    result_preview: null,
                    display: null,
                },
            ],
        },
    ],
});

const wrapper = ({ children }: PropsWithChildren) => (
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        {children}
    </QueryClientProvider>
);

const statusUrl = '/api/client/assistant';

const conversationFetches = () => http.get.mock.calls.filter(([url]) => url === `${base}/c1`).length;

afterEach(() => {
    cleanup();
    vi.useRealTimers();
});
beforeEach(() => vi.resetAllMocks());

describe('AssistantChat', () => {
    it('opens the initial conversation, such as a crash diagnosis, and labels it', async () => {
        http.get.mockImplementation((url: string) =>
            Promise.resolve({
                data:
                    url === base
                        ? { data: [{ ...list.data[0], mode: 'crash_doctor', status: 'idle' }] }
                        : { ...conversation('idle'), mode: 'crash_doctor' },
            })
        );

        render(<AssistantChat scope={serverAssistantScope('srv-uuid')} initialConversation='c1' />, { wrapper });

        expect(await screen.findByText(/Crash doctor ·/)).toBeInTheDocument();
        await waitFor(() => expect(conversationFetches()).toBe(1));
    });

    it('polls a running conversation and stops once it is idle', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        const replies = [conversation('running'), conversation('idle')];

        http.get.mockImplementation((url: string) => {
            if (url === statusUrl) {
                return Promise.resolve({ data: { paused: false } });
            }

            return Promise.resolve({ data: url === base ? list : (replies.shift() ?? conversation('idle')) });
        });
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        render(<AssistantChat scope={serverAssistantScope('srv-uuid')} />, { wrapper });

        await user.click(await screen.findByRole('button', { name: /Why did it crash\?/ }));
        expect(await screen.findByRole('status', { name: 'The assistant is working' })).toBeInTheDocument();
        expect(conversationFetches()).toBe(1);

        await act(() => vi.advanceTimersByTimeAsync(ASSISTANT_POLL_MS + 50));
        await waitFor(() => expect(conversationFetches()).toBe(2));
        await waitFor(() =>
            expect(screen.queryByRole('status', { name: 'The assistant is working' })).not.toBeInTheDocument()
        );

        await act(() => vi.advanceTimersByTimeAsync(ASSISTANT_POLL_MS * 4));
        expect(conversationFetches()).toBe(2);
    });

    it('allows a pending action from its red card and posts the approval', async () => {
        http.get.mockImplementation((url: string) =>
            Promise.resolve({ data: url === base ? list : conversation('awaiting_approval') })
        );
        http.post.mockResolvedValue({ data: conversation('running') });

        render(<AssistantChat scope={serverAssistantScope('srv-uuid')} />, { wrapper });

        await userEvent.click(await screen.findByRole('button', { name: /Why did it crash\?/ }));

        const card = await screen.findByTestId('tool-call');

        expect(card).toHaveAttribute('data-tone', 'danger');
        expect(card).toHaveTextContent('permanently deletes files');
        expect(screen.getByRole('textbox', { name: 'Message the assistant' })).toBeDisabled();

        await userEvent.click(screen.getByRole('button', { name: 'Allow: Delete /logs/old.log' }));

        expect(http.post).toHaveBeenCalledWith(`${base}/c1/tool-calls/7/approve`);
        // The approve response is the conversation, now running again, so the composer stays off.
        await waitFor(() => expect(screen.queryByRole('button', { name: /^Allow:/ })).not.toBeInTheDocument());
    });

    it('says the assistant was busy when a turn was handed back idle with a note', async () => {
        const busy = { ...conversation('idle'), error: 'The assistant was still finishing an earlier step.' };

        http.get.mockImplementation((url: string) => Promise.resolve({ data: url === base ? list : busy }));

        render(<AssistantChat scope={serverAssistantScope('srv-uuid')} />, { wrapper });

        await userEvent.click(await screen.findByRole('button', { name: /Why did it crash\?/ }));

        expect(await screen.findByText('The assistant was busy')).toBeInTheDocument();
        expect(screen.getByText('The assistant was still finishing an earlier step.')).toBeInTheDocument();
    });

    it('offers Allow only on the latest step', async () => {
        const waiting = conversation('awaiting_approval');
        const newer = {
            id: 3,
            role: 'assistant' as const,
            content: null,
            notice: null,
            created_at: '2026-11-15T12:00:02+00:00',
            tool_calls: [{ ...waiting.messages[1]!.tool_calls[0]!, id: 8, label: 'Delete /logs/new.log' }],
        };

        http.get.mockImplementation((url: string) =>
            Promise.resolve({ data: url === base ? list : { ...waiting, messages: [...waiting.messages, newer] } })
        );

        render(<AssistantChat scope={serverAssistantScope('srv-uuid')} />, { wrapper });

        await userEvent.click(await screen.findByRole('button', { name: /Why did it crash\?/ }));

        expect(await screen.findByRole('button', { name: 'Allow: Delete /logs/new.log' })).toBeEnabled();
        expect(screen.queryByRole('button', { name: 'Allow: Delete /logs/old.log' })).not.toBeInTheDocument();
        expect(screen.getByText('Expired')).toBeInTheDocument();
    });

    it('sends with Enter, keeps Shift+Enter as a new line, and starts a conversation', async () => {
        http.get.mockImplementation((url: string) =>
            Promise.resolve({ data: url === base ? { data: [] } : conversation('running') })
        );
        http.post.mockResolvedValue({ data: conversation('running') });

        render(<AssistantChat scope={serverAssistantScope('srv-uuid')} />, { wrapper });

        const composer = screen.getByRole('textbox', { name: 'Message the assistant' });

        await userEvent.type(composer, 'Hello{Shift>}{Enter}{/Shift}there');
        expect(composer).toHaveValue('Hello\nthere');
        expect(http.post).not.toHaveBeenCalled();

        await userEvent.type(composer, '{Enter}');

        expect(http.post).toHaveBeenCalledWith(base, { message: 'Hello\nthere' });
        expect(await screen.findByTestId('assistant-thread')).toBeInTheDocument();
    });

    it('keeps the composer off while the user cannot chat', () => {
        http.get.mockResolvedValue({ data: { data: [] } });

        render(
            <AssistantChat scope={serverAssistantScope('srv-uuid')} blockedReason='Buy tokens to keep chatting.' />,
            {
                wrapper,
            }
        );

        expect(screen.getByRole('textbox', { name: 'Message the assistant' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Send' })).toBeDisabled();
    });

    it('turns Send into Stop while the assistant works, and stops on a click or Esc', async () => {
        http.get.mockImplementation((url: string) =>
            Promise.resolve({ data: url === base ? list : conversation('running') })
        );
        const stopped: AssistantConversation = {
            ...conversation('idle'),
            messages: [
                ...conversation('idle').messages,
                {
                    id: 3,
                    role: 'assistant',
                    content: 'Stopped by you.',
                    notice: 'stopped',
                    created_at: null,
                    tool_calls: [],
                },
            ],
        };

        http.post.mockResolvedValue({ data: stopped });

        render(<AssistantChat scope={serverAssistantScope('srv-uuid')} />, { wrapper });

        await userEvent.click(await screen.findByRole('button', { name: /Why did it crash\?/ }));

        const stop = await screen.findByRole('button', { name: 'Stop the assistant' });

        expect(screen.queryByRole('button', { name: 'Send' })).not.toBeInTheDocument();
        expect(stop).toHaveAttribute('aria-keyshortcuts', 'Escape');

        await userEvent.keyboard('{Escape}');

        expect(http.post).toHaveBeenCalledWith(`${base}/c1/stop`);
        expect(await screen.findByTestId('message-stopped')).toHaveTextContent('Stopped by you');
        expect(screen.getByRole('button', { name: 'Send' })).toBeInTheDocument();
    });

    it('stops a turn that waits for approval with the X button', async () => {
        http.get.mockImplementation((url: string) =>
            Promise.resolve({ data: url === base ? list : conversation('awaiting_approval') })
        );
        http.post.mockResolvedValue({ data: conversation('idle') });

        render(<AssistantChat scope={serverAssistantScope('srv-uuid')} />, { wrapper });

        await userEvent.click(await screen.findByRole('button', { name: /Why did it crash\?/ }));
        await userEvent.click(await screen.findByRole('button', { name: 'Stop the assistant' }));

        expect(http.post).toHaveBeenCalledWith(`${base}/c1/stop`);
    });

    it('allows the rest of the turn from a card', async () => {
        http.get.mockImplementation((url: string) =>
            Promise.resolve({ data: url === base ? list : conversation('awaiting_approval') })
        );
        http.post.mockResolvedValue({ data: conversation('running') });

        render(<AssistantChat scope={serverAssistantScope('srv-uuid')} />, { wrapper });

        await userEvent.click(await screen.findByRole('button', { name: /Why did it crash\?/ }));
        await userEvent.click(
            await screen.findByRole('button', { name: 'Allow until my next message: Delete /logs/old.log' })
        );

        expect(http.post).toHaveBeenCalledWith(`${base}/c1/tool-calls/7/approve`, { until_next_message: true });
    });

    it('asks for confirmation before Allow everything, and not before switching back', async () => {
        http.get.mockImplementation((url: string) =>
            Promise.resolve({ data: url === base ? list : conversation('idle') })
        );
        http.put.mockImplementation((_url: string, body: { mode: AssistantConversation['approval_mode'] }) =>
            Promise.resolve({ data: { ...conversation('idle'), approval_mode: body.mode } })
        );

        render(<AssistantChat scope={serverAssistantScope('srv-uuid')} />, { wrapper });

        await userEvent.click(await screen.findByRole('button', { name: /Why did it crash\?/ }));

        const ask = await screen.findByRole('button', { name: 'Ask before changes' });
        const everything = screen.getByRole('button', { name: 'Allow everything' });

        expect(ask).toHaveAttribute('aria-pressed', 'true');

        await userEvent.click(everything);

        const dialog = await screen.findByRole('dialog');

        expect(dialog).toHaveTextContent('edit and delete files');
        expect(http.put).not.toHaveBeenCalled();

        await userEvent.click(within(dialog).getByRole('button', { name: 'Allow everything' }));

        expect(http.put).toHaveBeenCalledWith(`${base}/c1/approval-mode`, {
            mode: 'allow_everything',
            confirmed: true,
        });
        await waitFor(() =>
            expect(screen.getByRole('button', { name: 'Allow everything' })).toHaveAttribute('aria-pressed', 'true')
        );

        await userEvent.click(screen.getByRole('button', { name: 'Ask before changes' }));

        expect(http.put).toHaveBeenLastCalledWith(`${base}/c1/approval-mode`, { mode: 'ask', confirmed: false });
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('offers no Allow everything when an administrator turned it off', async () => {
        http.get.mockImplementation((url: string) =>
            Promise.resolve({
                data: url === base ? list : { ...conversation('idle'), allow_everything_available: false },
            })
        );

        render(<AssistantChat scope={serverAssistantScope('srv-uuid')} />, { wrapper });

        await userEvent.click(await screen.findByRole('button', { name: /Why did it crash\?/ }));

        expect(await screen.findByRole('button', { name: 'Allow everything' })).toBeDisabled();
    });

    it('shows the pause banner, keeps the composer off and resumes in one click', async () => {
        http.get.mockImplementation((url: string) =>
            Promise.resolve({ data: url === statusUrl ? { paused: true } : { data: [] } })
        );
        http.put.mockResolvedValue({ data: { paused: false } });

        render(<AssistantChat scope={serverAssistantScope('srv-uuid')} />, { wrapper });

        const banner = await screen.findByTestId('assistant-paused');

        expect(screen.getByRole('textbox', { name: 'Message the assistant' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Resume the assistant' })).toHaveAttribute('aria-pressed', 'true');

        await userEvent.click(within(banner).getByRole('button', { name: 'Resume' }));

        expect(http.put).toHaveBeenCalledWith('/api/client/assistant/pause', { paused: false });
        await waitFor(() => expect(screen.queryByTestId('assistant-paused')).not.toBeInTheDocument());
    });

    it('pauses from the header switch', async () => {
        let paused = false;

        http.get.mockImplementation((url: string) =>
            Promise.resolve({ data: url === statusUrl ? { paused } : { data: [] } })
        );
        http.put.mockImplementation(() => {
            paused = true;

            return Promise.resolve({ data: { paused } });
        });

        render(<AssistantChat scope={serverAssistantScope('srv-uuid')} />, { wrapper });

        await userEvent.click(await screen.findByRole('button', { name: 'Pause the assistant' }));

        expect(http.put).toHaveBeenCalledWith('/api/client/assistant/pause', { paused: true });
        expect(await screen.findByTestId('assistant-paused')).toBeInTheDocument();
    });
});
