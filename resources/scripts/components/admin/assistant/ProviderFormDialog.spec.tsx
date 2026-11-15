/** @vitest-environment jsdom */
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { PropsWithChildren } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type * as Http from '@/api/http';
import type { AssistantProvider } from '@/api/assistant';
import ProviderFormDialog, { parseHeaders, providerFormValues, providerRequestBody } from './ProviderFormDialog';
import { assistantTabFromPath, shiftPeriod } from './assistantTabs';

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }));

vi.mock('@/api/http', async (importOriginal) => ({ ...(await importOriginal<typeof Http>()), default: http }));

afterEach(() => {
    cleanup();
    vi.resetAllMocks();
});

const provider: AssistantProvider = {
    id: 4,
    name: 'ClinePass',
    preset: 'clinepass',
    base_url: 'https://api.cline.bot/api/v1',
    model: 'cline-pass/deepseek-v4.1-flash',
    extra_headers: [{ name: 'X-Title', has_value: true }],
    context_window: 64000,
    supports_tools: true,
    enabled: true,
    has_key: true,
    created_at: '2026-11-15T12:00:00+00:00',
    updated_at: '2026-11-15T12:00:00+00:00',
};

const wrapper = ({ children }: PropsWithChildren) => (
    <QueryClientProvider client={new QueryClient()}>{children}</QueryClientProvider>
);

describe('ProviderFormDialog', () => {
    it('never shows a stored key and keeps it when the field stays blank', async () => {
        http.put.mockResolvedValue({ data: provider });
        const onClose = vi.fn();

        render(<ProviderFormDialog open provider={provider} onClose={onClose} />, { wrapper });

        const key = screen.getByTestId('provider-api-key');

        expect(key).toHaveValue('');
        expect(key).toHaveAttribute('type', 'password');
        expect(screen.getByText(/A key is stored/)).toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));

        await waitFor(() => expect(http.put).toHaveBeenCalled());
        const [url, body] = http.put.mock.calls[0] ?? [];

        expect(url).toBe('/api/admin/assistant/providers/4');
        expect(body).not.toHaveProperty('api_key');
        expect(body).toMatchObject({
            preset: 'clinepass',
            context_window: 64000,
            // The stored value is never shown, and an untouched header keeps it.
            extra_headers: { 'X-Title': null },
        });
        await waitFor(() => expect(onClose).toHaveBeenCalled());
    });

    it('sends a newly typed key', () => {
        expect(providerRequestBody({ ...providerFormValues(provider), apiKey: ' sk-new ' })).toMatchObject({
            api_key: 'sk-new',
        });
    });
});

describe('provider helpers', () => {
    it('parses header lines, a blank value keeping the stored one', () => {
        expect(parseHeaders('X-A: 1\nbad line\n X-B :two \nX-C:')).toEqual({ 'X-A': '1', 'X-B': 'two', 'X-C': null });
        expect(parseHeaders('')).toBeNull();
    });

    it('prefills stored headers by name only', () => {
        expect(providerFormValues(provider).extraHeaders).toBe('X-Title:');
    });

    it('picks the sub-tab from the path and steps months', () => {
        expect(assistantTabFromPath('/panel/assistant')).toBe('chat');
        expect(assistantTabFromPath('/panel/assistant/providers/')).toBe('providers');
        expect(assistantTabFromPath('/panel/assistant/nope')).toBe('chat');
        expect(shiftPeriod('2026-01', -1)).toBe('2025-12');
        expect(shiftPeriod('2026-12', 1)).toBe('2027-01');
    });
});
