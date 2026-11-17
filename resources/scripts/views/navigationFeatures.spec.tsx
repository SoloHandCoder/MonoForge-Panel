/** @vitest-environment jsdom */
import { act, cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeAll, expect, it, vi } from 'vitest';
import type * as Http from '@/api/http';
import { assistantStatusQueryKey, type AssistantStatus } from '@/api/assistant';
import { queryClient } from '@/api/queryClient';
import { publishAreaNav } from '@/router/nav';
import { createExtensionTestHost, createTestServer, type ExtensionTestHost } from '@/sdk/testing';
import { useNavigation } from './navigation';

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }));

vi.mock('@/api/http', async (importOriginal) => ({ ...(await importOriginal<typeof Http>()), default: http }));

let host: ExtensionTestHost | undefined;

beforeAll(() => {
    publishAreaNav('server', [
        { segment: '', label: 'Console', exact: true },
        { segment: 'assistant', label: 'Assistant', permission: 'assistant.use', feature: 'assistant' },
    ]);
});

afterEach(() => {
    cleanup();
    host?.dispose();
    host = undefined;
    vi.resetAllMocks();
});

const status = (overrides: Partial<AssistantStatus>): AssistantStatus => ({
    enabled: true,
    server_enabled: true,
    crash_doctor_enabled: true,
    paused: false,
    free_limit: 50000,
    free_used: 0,
    ratio: 1000,
    balance: 0,
    warning: null,
    ...overrides,
});

function Labels() {
    return (
        <p>
            {useNavigation('server')
                .items.map((item) => item.label)
                .join(',')}
        </p>
    );
}

async function renderWith(assistant: AssistantStatus, permissions: string[]) {
    host = createExtensionTestHost({
        server: createTestServer({ identifier: 'abc', owner: false, permissions }),
        path: '/server/abc',
    });
    queryClient.setQueryData(assistantStatusQueryKey, assistant);
    http.get.mockResolvedValue({ data: assistant });

    await act(async () => {
        render(<Labels />, { wrapper: host!.Wrapper });
    });
}

it('lists the assistant only while it is on and the subuser may use it', async () => {
    await renderWith(status({}), ['assistant.use']);
    expect(screen.getByText('Console,Assistant')).toBeInTheDocument();
    cleanup();
    host?.dispose();

    await renderWith(status({ server_enabled: false }), ['assistant.use']);
    expect(screen.getByText('Console')).toBeInTheDocument();
    cleanup();
    host?.dispose();

    await renderWith(status({ enabled: false }), ['assistant.use']);
    expect(screen.getByText('Console')).toBeInTheDocument();
    cleanup();
    host?.dispose();

    await renderWith(status({}), ['file.read']);
    expect(screen.getByText('Console')).toBeInTheDocument();
});
