/** @vitest-environment jsdom */
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { PropsWithChildren } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type * as Http from '@/api/http';
import type { AssistantProviderList, AssistantSettings } from '@/api/assistant';
import SettingsTab from './SettingsTab';

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }));

vi.mock('@/api/http', async (importOriginal) => ({ ...(await importOriginal<typeof Http>()), default: http }));

afterEach(() => {
    cleanup();
    vi.resetAllMocks();
});

const settings: AssistantSettings = {
    enabled: true,
    server_enabled: true,
    admin_enabled: true,
    crash_doctor_enabled: true,
    default_provider_id: null,
    ratio: 1000,
    free_tokens_monthly: 50000,
    warn_percent: 80,
    max_tool_iterations: 8,
    context_tokens: 24000,
    max_output_tokens: 2048,
    allow_everything_enabled: true,
    web_search_enabled: false,
    web_search_provider: 'native',
    web_search_key_set: true,
};

const providers: AssistantProviderList = { data: [], meta: { default_provider_id: null, presets: [] } };

const wrapper = ({ children }: PropsWithChildren) => (
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        {children}
    </QueryClientProvider>
);

describe('SettingsTab', () => {
    it('saves the approval and web search settings without ever showing the stored key', async () => {
        http.get.mockImplementation((url: string) =>
            Promise.resolve({ data: url.endsWith('/settings') ? settings : providers })
        );
        http.put.mockResolvedValue({ data: settings });

        render(<SettingsTab />, { wrapper });

        const key = await screen.findByLabelText('Search API key');

        expect(key).toHaveValue('');
        expect(key).toHaveAttribute('type', 'password');
        expect(screen.getByText(/A key is stored/)).toBeInTheDocument();

        await userEvent.click(screen.getByRole('switch', { name: /Web search/ }));
        await userEvent.click(screen.getByRole('switch', { name: /Allow "Allow everything"/ }));
        await userEvent.type(key, 'tvly-new-key');
        await userEvent.click(screen.getByRole('button', { name: 'Save Settings' }));

        await waitFor(() => expect(http.put).toHaveBeenCalled());
        const [url, body] = http.put.mock.calls[0] ?? [];

        expect(url).toBe('/api/admin/assistant/settings');
        expect(body).toMatchObject({
            allow_everything_enabled: false,
            web_search_enabled: true,
            web_search_provider: 'native',
            web_search_key: 'tvly-new-key',
            clear_web_search_key: false,
        });
        expect(body).not.toHaveProperty('web_search_key_set');
        await waitFor(() => expect(key).toHaveValue(''));
    });
});
