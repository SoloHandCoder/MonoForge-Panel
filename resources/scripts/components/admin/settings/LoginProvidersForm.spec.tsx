/** @vitest-environment jsdom */
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen } from '@testing-library/react';
import type { PropsWithChildren } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type * as Http from '@/api/http';
import type { LoginProvidersSettings } from '@/api/admin/settings/loginProviders';
import LoginProvidersForm from './LoginProvidersForm';

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }));

vi.mock('@/api/http', async (importOriginal) => ({ ...(await importOriginal<typeof Http>()), default: http }));

afterEach(() => {
    cleanup();
    vi.resetAllMocks();
});

const unlocked = { enabled: false, client_id: false, client_secret: false };

const settings: LoginProvidersSettings = {
    discord: {
        enabled: false,
        client_id: '1234',
        client_secret_set: true,
        callback_url: 'https://panel.test/auth/modules/discord/authenticate',
        set_in_env: { enabled: true, client_id: false, client_secret: true },
    },
    google: {
        enabled: false,
        client_id: '',
        client_secret_set: false,
        callback_url: 'https://panel.test/auth/modules/google/authenticate',
        set_in_env: unlocked,
    },
};

const wrapper = ({ children }: PropsWithChildren) => (
    <QueryClientProvider client={new QueryClient()}>{children}</QueryClientProvider>
);

describe('LoginProvidersForm', () => {
    it('locks exactly the fields that .env sets and keeps the rest editable', async () => {
        http.get.mockResolvedValue({ data: settings });

        render(<LoginProvidersForm />, { wrapper });

        const discordId = await screen.findByLabelText('Client ID', { selector: '#discord_client_id' });

        expect(discordId).toBeEnabled();
        expect(screen.getByLabelText('Client Secret', { selector: '#discord_client_secret' })).toBeDisabled();
        expect(screen.getByLabelText('Client Secret', { selector: '#google_client_secret' })).toBeEnabled();
        expect(screen.getByText(/Set in \.env \(DISCORD_LOGIN_ENABLED\)/)).toBeInTheDocument();
        expect(screen.getByText(/Set in \.env \(DISCORD_CLIENT_SECRET\)/)).toBeInTheDocument();
        expect(screen.queryByText(/GOOGLE_/)).toBeNull();
        expect(screen.getByRole('button', { name: 'Save Changes' })).toBeEnabled();
    });
});
