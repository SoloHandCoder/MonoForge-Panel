/** @vitest-environment jsdom */

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { PropsWithChildren } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type * as PluginSettings from '@/api/admin/settings/plugins';
import { PluginSettingsFields } from './PluginSettingsForm';

const mutateAsync = vi.hoisted(() => vi.fn(() => Promise.resolve()));
const mutate = vi.hoisted(() => vi.fn());

vi.mock('@/api/admin/settings/plugins', async (importOriginal) => ({
    ...(await importOriginal<typeof PluginSettings>()),
    useUpdateAdminPluginSettings: () => ({ mutateAsync, mutate, isPending: false }),
}));

const wrapper = ({ children }: PropsWithChildren) => (
    <QueryClientProvider client={new QueryClient()}>{children}</QueryClientProvider>
);

afterEach(cleanup);
beforeEach(() => vi.clearAllMocks());

describe('plugin settings', () => {
    it('saves a new CurseForge key and never shows the stored one', async () => {
        const user = userEvent.setup();

        render(<PluginSettingsFields settings={{ curseforge_key_set: true, set_in_env: false }} />, { wrapper });

        const input = screen.getByLabelText('CurseForge API key');

        expect(input).toHaveValue('');
        expect(screen.getByText('A key is stored. Leave blank to keep it.')).toBeInTheDocument();

        await user.type(input, ' new-key ');
        await user.click(screen.getByRole('button', { name: 'Save Changes' }));
        expect(mutateAsync).toHaveBeenCalledWith({ body: { curseforge_key: 'new-key' } });

        await user.click(screen.getByRole('button', { name: 'Remove key' }));
        expect(mutate).toHaveBeenCalledWith({ body: { clear_curseforge_key: true } });
    });

    it('locks the key when .env sets it', () => {
        render(<PluginSettingsFields settings={{ curseforge_key_set: true, set_in_env: true }} />, { wrapper });

        expect(screen.getByLabelText('CurseForge API key')).toBeDisabled();
        expect(screen.getByText(/CURSEFORGE_API_KEY/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Remove key' })).toBeNull();
    });
});
