/** @vitest-environment jsdom */

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { ServerStartupVariable } from '@/api/server/startup/queries';
import WorldSettingsPage from './WorldSettingsPage';
import { worldFieldError, worldFields } from './worldSettings';

const state = vi.hoisted(() => ({ permissions: new Set(['startup.read', 'startup.update']) }));
const update = vi.hoisted(() => vi.fn(async () => ({})));

const variable = (
    env: string,
    value: string | null,
    change: Partial<ServerStartupVariable['attributes']> = {}
): ServerStartupVariable =>
    ({
        object: 'egg_variable',
        attributes: {
            name: env,
            description: '',
            env_variable: env,
            default_value: '1',
            server_value: value,
            is_editable: true,
            rules: 'required|string',
            ...change,
        },
    }) as ServerStartupVariable;

const variables = vi.hoisted(() => ({ list: [] as ServerStartupVariable[] }));

vi.mock('@/api/server/startup/queries', async (importOriginal) => ({
    ...(await importOriginal<object>()),
    useServerStartup: () => ({ isPending: false, error: null, data: { data: variables.list } }),
    useUpdateStartupVariable: () => ({ mutateAsync: update, isPending: false }),
}));

vi.mock('@/api/server/queries', async (importOriginal) => ({
    ...(await importOriginal<object>()),
    useCurrentServerUuid: () => 'uuid',
    useCurrentServerName: () => 'Terraria',
}));

vi.mock('@/sdk/core', async (importOriginal) => ({
    ...(await importOriginal<object>()),
    useServerPermission: (permission: string) => state.permissions.has(permission),
}));

const renderPage = () =>
    render(
        <QueryClientProvider client={new QueryClient()}>
            <WorldSettingsPage />
        </QueryClientProvider>
    );

beforeEach(() => {
    state.permissions = new Set(['startup.read', 'startup.update']);
    variables.list = [
        variable('WORLD_NAME', 'Island'),
        variable('WORLD_SIZE', '2'),
        variable('WORLD_DIFFICULTY', null, { default_value: '0' }),
        variable('MAX_PLAYERS', '8'),
        variable('SERVER_PASSWORD', '', { is_editable: false }),
        variable('TERRARIA_VERSION', 'latest'),
    ];
});

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});

describe('WorldSettingsPage', () => {
    it('shows the world settings the egg has, with friendly choices', () => {
        renderPage();

        expect((screen.getByLabelText('World name') as HTMLInputElement).value).toBe('Island');
        expect((screen.getByLabelText('World size') as HTMLSelectElement).value).toBe('2');
        expect(screen.getByRole('option', { name: 'Medium' })).toBeTruthy();
        expect((screen.getByLabelText('Difficulty') as HTMLSelectElement).value).toBe('0');
        expect((screen.getByLabelText('Server password') as HTMLInputElement).disabled).toBe(true);
        expect(screen.queryByText('TERRARIA_VERSION')).toBeNull();
    });

    it('saves a setting through the startup variable it lives in', async () => {
        renderPage();

        await userEvent.selectOptions(screen.getByLabelText('Difficulty'), '2');
        await userEvent.click(screen.getAllByRole('button', { name: 'Save' })[2]!);

        expect(update).toHaveBeenCalledWith({
            path: { server_uuid: 'uuid' },
            body: { key: 'WORLD_DIFFICULTY', value: '2' },
        });
    });

    it('refuses an impossible player count', async () => {
        renderPage();

        const players = screen.getByLabelText('Max players');

        await userEvent.clear(players);
        await userEvent.type(players, '999');

        expect(screen.getByText('Terraria allows 1 to 255 players.')).toBeTruthy();
    });

    it('is read-only without startup.update', () => {
        state.permissions = new Set(['startup.read']);
        renderPage();

        expect(screen.queryByRole('button', { name: 'Save' })).toBeNull();
        expect((screen.getByLabelText('World name') as HTMLInputElement).disabled).toBe(true);
    });

    it('says so when the egg has no world settings', () => {
        variables.list = [variable('TERRARIA_VERSION', 'latest')];
        renderPage();

        expect(screen.getByText('No world settings')).toBeTruthy();
    });
});

describe('world settings', () => {
    it('picks one variable per setting', () => {
        const fields = worldFields([variable('PASSWORD', 'a'), variable('SERVER_PASSWORD', 'b')]);

        expect(fields.map((field) => field.variable.env_variable)).toEqual(['SERVER_PASSWORD']);
        expect(worldFieldError(fields[0]!, 'x'.repeat(200))).toBe('Keep it under 192 characters.');
    });
});
