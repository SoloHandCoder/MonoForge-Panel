import { AxiosError, AxiosHeaders } from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type * as Http from '@/api/http';
import {
    adminAssistantScope,
    ASSISTANT_POLL_MS,
    conversationPollInterval,
    createAssistantConversation,
    decideAssistantToolCall,
    deleteAssistantConversation,
    describeAssistantError,
    getAssistantStatus,
    getAssistantUsage,
    listAssistantConversations,
    sendAssistantMessage,
    serverAssistantScope,
    testAssistantProvider,
} from './assistant';

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }));

vi.mock('@/api/http', async (importOriginal) => ({ ...(await importOriginal<typeof Http>()), default: http }));

afterEach(() => vi.resetAllMocks());

describe('assistant API', () => {
    it('calls the client endpoints of a server', async () => {
        http.get.mockResolvedValue({ data: { data: [] } });
        http.post.mockResolvedValue({ data: {} });
        http.delete.mockResolvedValue({ data: null });
        const scope = serverAssistantScope('1a2b3c4d');
        const base = '/api/client/servers/1a2b3c4d/assistant/conversations';

        await getAssistantStatus();
        await listAssistantConversations(scope);
        await createAssistantConversation(scope, { message: 'hi' });
        await sendAssistantMessage(scope, 'c1', { message: 'more' });
        await decideAssistantToolCall(scope, 'c1', 9, 'approve');
        await decideAssistantToolCall(scope, 'c1', 9, 'deny');
        await deleteAssistantConversation(scope, 'c1');

        expect(http.get.mock.calls.map(([url]) => url)).toEqual(['/api/client/assistant', base]);
        expect(http.post.mock.calls).toEqual([
            [base, { message: 'hi' }],
            [`${base}/c1/messages`, { message: 'more' }],
            [`${base}/c1/tool-calls/9/approve`],
            [`${base}/c1/tool-calls/9/deny`],
        ]);
        expect(http.delete).toHaveBeenCalledWith(`${base}/c1`);
    });

    it('calls the admin endpoints', async () => {
        http.get.mockResolvedValue({ data: {} });
        http.post.mockResolvedValue({ data: { ok: true, latency_ms: 420, error: null, reply: 'OK' } });

        await listAssistantConversations(adminAssistantScope);
        await getAssistantUsage('2026-11');

        expect(await testAssistantProvider(2)).toEqual({ ok: true, latency_ms: 420, error: null, reply: 'OK' });
        expect(http.get.mock.calls.map(([url, config]) => [url, config.params])).toEqual([
            ['/api/admin/assistant/conversations', undefined],
            ['/api/admin/assistant/usage', { period: '2026-11' }],
        ]);
        expect(http.post).toHaveBeenCalledWith('/api/admin/assistant/providers/2/test');
    });

    it('polls a conversation only while it runs', () => {
        expect(conversationPollInterval({ status: 'running' })).toBe(ASSISTANT_POLL_MS);
        expect(conversationPollInterval({ status: 'awaiting_approval' })).toBe(false);
        expect(conversationPollInterval({ status: 'idle' })).toBe(false);
        expect(conversationPollInterval({ status: 'error' })).toBe(false);
        expect(conversationPollInterval(undefined)).toBe(false);
    });

    it('explains the refusals in words a user can act on', () => {
        const refusal = (status: number, detail: string) => {
            const config = { headers: new AxiosHeaders() };

            return new AxiosError('Request failed', 'ERR_BAD_REQUEST', config, null, {
                status,
                statusText: '',
                headers: {},
                config,
                data: { errors: [{ code: 'HttpException', status: String(status), detail }] },
            });
        };

        expect(describeAssistantError(refusal(402, 'no tokens'), 'x')).toMatchObject({
            title: 'Out of AI tokens',
            wallet: true,
        });
        expect(describeAssistantError(refusal(403, 'The AI assistant is turned off.'), 'x')).toMatchObject({
            title: 'The assistant is turned off',
            wallet: false,
        });
        expect(describeAssistantError(refusal(403, 'This action is unauthorized.'), 'x')).toMatchObject({
            title: 'You cannot use the assistant here',
            description: 'This action is unauthorized.',
        });
        expect(describeAssistantError(refusal(503, 'none'), 'x').title).toBe('The assistant is not set up yet');
        expect(describeAssistantError(refusal(409, 'busy'), 'x').title).toBe('The assistant is busy');
        expect(describeAssistantError(refusal(500, 'Boom.'), 'Unable to send')).toEqual({
            title: 'Unable to send',
            description: 'Boom.',
            wallet: false,
        });
    });
});
