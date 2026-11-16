/** @vitest-environment jsdom */
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import type { AxiosAdapter } from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type * as Router from '@tanstack/react-router';
import http from '@/api/http';
import {
    schedulePresetsQueryOptions,
    serverScheduleQueryOptions,
    serverSchedulesQueryOptions,
    type Schedule,
    type SchedulePresets,
    type Task,
} from '@/api/server/schedules/queries';
import { createExtensionTestHost, createTestServer, type ExtensionTestHost } from '@/sdk/testing';
import { View, ViewFixtureProvider } from '@/views/View';
import { scheduleTaskForm } from './schedules';

vi.mock('@tanstack/react-router', async (importOriginal) => {
    const router = await importOriginal<typeof Router>();
    const useParams: typeof router.useParams = (options) => ({ ...router.useParams(options), scheduleId: 7 });

    return { ...router, useParams };
});

const server = createTestServer({ identifier: 'a1b2c3d4' });
const { uuid } = server.attributes;

const task = (id: number, sequence: number, action: Task['attributes']['action'], payload: string): Task => ({
    object: 'schedule_task',
    attributes: {
        id,
        sequence_id: sequence,
        action,
        payload,
        keep_backups: null,
        time_offset: sequence > 1 ? 30 : 0,
        is_queued: false,
        continue_on_failure: false,
        created_at: '2026-10-01T00:00:00Z',
        updated_at: '2026-10-01T00:00:00Z',
    },
});

const schedule = (id: number, name: string, options: Partial<Schedule['attributes']> = {}): Schedule => ({
    object: 'server_schedule',
    attributes: {
        id,
        name,
        preset: null,
        cron: { minute: '0', hour: '4', day_of_month: '*', month: '*', day_of_week: '*' },
        is_active: true,
        is_processing: false,
        only_when_online: true,
        last_run_at: null,
        next_run_at: '2026-10-13T04:00:00Z',
        created_at: '2026-10-01T00:00:00Z',
        updated_at: '2026-10-01T00:00:00Z',
        relationships: { tasks: { object: 'list', data: [] } },
        ...options,
    },
});

let host: ExtensionTestHost | undefined;
let requests: { method: string; url: string; body: unknown }[];
const adapter = http.defaults.adapter;

beforeEach(() => {
    requests = [];
    http.defaults.adapter = (async (config) => {
        const body: unknown = config.data ? JSON.parse(String(config.data)) : undefined;

        requests.push({ method: config.method ?? 'get', url: config.url ?? '', body });

        return {
            data:
                config.method === 'post' && /\/schedules(\/presets)?$/.test(config.url ?? '')
                    ? schedule(9, 'Created')
                    : '',
            status: 200,
            statusText: 'OK',
            headers: {},
            config,
        };
    }) satisfies AxiosAdapter;
});

afterEach(() => {
    cleanup();
    host?.dispose();
    host = undefined;
    http.defaults.adapter = adapter;
});

async function renderInServer(children: ReactNode, seed: (host: ExtensionTestHost) => void) {
    host = createExtensionTestHost({ server, path: '/server/a1b2c3d4/schedules' });
    seed(host);

    await act(async () => {
        render(children, { wrapper: host!.Wrapper });
    });
}

describe('server.schedules', () => {
    it('lists the schedules with their status and a link to each', async () => {
        await renderInServer(<View name='server.schedules' />, ({ queryClient }) =>
            queryClient.setQueryData(serverSchedulesQueryOptions(uuid).queryKey, {
                object: 'list',
                data: [schedule(1, 'Nightly restart'), schedule(2, 'Hourly backup', { is_processing: true })],
            })
        );

        expect(screen.getByRole('link', { name: 'Nightly restart' })).toHaveAttribute(
            'href',
            '/server/a1b2c3d4/schedules/1'
        );
        expect(screen.getByText('active')).toHaveClass('bg-success/90', 'rounded-full');
        expect(screen.getByText('processing')).toHaveAttribute('data-tone', 'neutral');
        expect(screen.getByRole('link', { name: 'Edit Hourly backup' })).toHaveAttribute(
            'href',
            '/server/a1b2c3d4/schedules/2'
        );
    });

    it('creates a schedule from the dialog', async () => {
        await renderInServer(<View name='server.schedules' />, ({ queryClient }) =>
            queryClient.setQueryData(serverSchedulesQueryOptions(uuid).queryKey, { object: 'list', data: [] })
        );

        fireEvent.click(screen.getAllByRole('button', { name: 'New schedule' })[0]);
        const dialog = await screen.findByRole('dialog');

        fireEvent.change(within(dialog).getByLabelText('Schedule name'), { target: { value: 'Nightly' } });
        await act(async () => {
            fireEvent.click(within(dialog).getByRole('button', { name: 'Create schedule' }));
        });

        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        expect(requests).toContainEqual({
            method: 'post',
            url: `/api/client/servers/${uuid}/schedules`,
            body: {
                is_active: true,
                only_when_online: true,
                name: 'Nightly',
                minute: '*/5',
                hour: '*',
                day_of_month: '*',
                month: '*',
                day_of_week: '*',
            },
        });
        expect(await screen.findByRole('link', { name: 'Created' })).toBeVisible();
    });

    it('offers no create or edit actions without permission', async () => {
        await renderInServer(
            <ViewFixtureProvider fixtures={{ 'server.schedules': 'readOnly' }}>
                <View name='server.schedules' />
            </ViewFixtureProvider>,
            () => {}
        );

        expect(screen.queryByRole('button', { name: 'New schedule' })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /^Edit / })).not.toBeInTheDocument();
    });
});

describe('page.server.schedules.detail', () => {
    const seeded = schedule(7, 'Nightly restart', {
        relationships: {
            tasks: {
                object: 'list',
                data: [task(2, 2, 'power', 'restart'), task(1, 1, 'command', 'say Restarting soon')],
            },
        },
    });
    const seed = ({ queryClient }: ExtensionTestHost) =>
        queryClient.setQueryData(serverScheduleQueryOptions(uuid, 7).queryKey, seeded);

    it('shows the schedule with its tasks in order', async () => {
        await renderInServer(<View name='page.server.schedules.detail' />, seed);

        const rows = screen.getAllByRole('row').slice(1);

        expect(screen.getByRole('heading', { name: /Nightly restart/ })).toHaveTextContent('Active');
        expect(rows.map((row) => within(row).getAllByRole('cell')[0].textContent)).toEqual([
            'Send Commandsay Restarting soon',
            'Send Power Actionrestart',
        ]);
    });

    it('runs the schedule now', async () => {
        await renderInServer(<View name='page.server.schedules.detail' />, seed);

        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Run Now' }));
        });

        expect(requests).toContainEqual({
            method: 'post',
            url: `/api/client/servers/${uuid}/schedules/7/execute`,
            body: undefined,
        });
        await waitFor(() => expect(screen.getByRole('button', { name: 'Run Now' })).toBeDisabled());
        expect(screen.getByRole('heading', { name: /Nightly restart/ })).toHaveTextContent('Processing');
    });

    it('deletes a task once confirmed', async () => {
        await renderInServer(<View name='page.server.schedules.detail' />, seed);

        fireEvent.click(screen.getByRole('button', { name: 'Delete task 2 (Send Power Action)' }));
        await act(async () => {
            fireEvent.click(await screen.findByRole('button', { name: 'Delete Task' }));
        });

        expect(requests).toContainEqual({
            method: 'delete',
            url: `/api/client/servers/${uuid}/schedules/7/tasks/2`,
            body: undefined,
        });
        await waitFor(() => expect(screen.getAllByRole('row')).toHaveLength(2));
    });

    it('returns to the list once the schedule is deleted', async () => {
        await renderInServer(<View name='page.server.schedules.detail' />, seed);

        fireEvent.click(screen.getByRole('button', { name: 'Delete' }));
        await act(async () => {
            fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Delete' }));
        });

        await waitFor(() => expect(host!.location().pathname).toBe('/server/a1b2c3d4/schedules'));
        // The deleted schedule is not fetched again while the page leaves.
        expect(requests).toEqual([
            { method: 'delete', url: `/api/client/servers/${uuid}/schedules/7`, body: undefined },
        ]);
    });
});

describe('the task form', () => {
    const form = scheduleTaskForm(0);
    const restart = {
        id: 3,
        sequence: 2,
        action: 'power',
        payload: 'stop',
        keepBackups: null,
        timeOffset: 60,
        continueOnFailure: true,
    } as const;

    it('starts from the task, or a command for a new one', () => {
        expect(form.values()).toEqual({
            action: 'command',
            payload: '',
            keepBackups: null,
            timeOffset: 0,
            continueOnFailure: false,
        });
        expect(form.values(restart)).toEqual({
            action: 'power',
            payload: 'stop',
            keepBackups: null,
            timeOffset: 60,
            continueOnFailure: true,
        });
    });

    it('resets the payload for another action and restores the task own', () => {
        expect(form.payloadFor('power')).toBe('start');
        expect(form.payloadFor('backup', restart)).toBe('');
        expect(form.payloadFor('power', restart)).toBe('stop');
        expect(form.payloadFor('restart_if_empty')).toBe('2');
        expect(form.payloadFor('delete_logs')).toBe('7');
    });

    it('takes whole hours and days for restart when empty and log cleanup tasks', () => {
        expect(form.validatePayload('restart_if_empty', '2')).toBeUndefined();
        expect(form.validatePayload('restart_if_empty', '73')).toBe('Enter the hours to wait, from 1 to 72.');
        expect(form.validatePayload('restart_if_empty', '1.5')).toBe('Enter the hours to wait, from 1 to 72.');
        expect(form.validatePayload('delete_logs', '0')).toBe('Enter the days of logs to keep, from 1 to 365.');
        expect(form.validatePayload('delete_logs', '30')).toBeUndefined();
    });

    it('keeps at most the server limit of backups', () => {
        const limited = scheduleTaskForm(3);

        expect(limited.validateKeepBackups(null)).toBeUndefined();
        expect(limited.validateKeepBackups(3)).toBeUndefined();
        expect(limited.validateKeepBackups(4)).toBe('This server keeps at most 3 backups.');
        expect(limited.validateKeepBackups(0)).toBe('Keep at least 1 backup, or leave it empty.');
    });

    it('validates the payload, the offset and the backup limit', () => {
        expect(form.validatePayload('command', '')).toBe('A task payload must be provided.');
        expect(form.validatePayload('backup', '')).toBeUndefined();
        expect(form.validateTimeOffset(901)).toBe('The time offset must be less than 900 seconds.');
        expect(form.validateTimeOffset(null)).toBe('A time offset must be provided.');
        expect(form.validate({ ...form.values(), action: 'backup' })).toContain('backup limit is set to 0');
        expect(scheduleTaskForm(2).validate({ ...form.values(), action: 'backup' })).toBeNull();
    });
});

describe('server.schedules.quickSetup', () => {
    const presets = (options: Partial<SchedulePresets> = {}) =>
        ({
            object: 'schedule_presets',
            attributes: {
                timezone: 'Europe/Berlin',
                is_minecraft: true,
                backup_limit: 3,
                can_send_commands: true,
                presets: [
                    { key: 'daily_restart', available: true, reason: null },
                    { key: 'backup_rotation', available: true, reason: null },
                    { key: 'restart_when_empty', available: true, reason: null },
                    { key: 'weekly_cleanup', available: false, reason: 'You need the file.delete permission.' },
                ],
                ...options,
            },
        }) satisfies { object: string; attributes: SchedulePresets };
    const seed =
        (schedules: Schedule[] = [], options: Partial<SchedulePresets> = {}) =>
        ({ queryClient }: ExtensionTestHost) => {
            queryClient.setQueryData(schedulePresetsQueryOptions(uuid).queryKey, presets(options));
            queryClient.setQueryData(serverSchedulesQueryOptions(uuid).queryKey, { object: 'list', data: schedules });
        };

    it('offers each preset, says why one is unavailable and shows the timezone', async () => {
        await renderInServer(<View name='server.schedules.quickSetup' />, seed());

        expect(screen.getByRole('heading', { name: 'Quick setup' })).toBeVisible();
        expect(screen.getByText(/Times are in Europe\/Berlin/)).toBeVisible();
        expect(screen.getByRole('button', { name: 'Set up Daily restart' })).toBeVisible();
        expect(screen.getByRole('button', { name: 'Set up Backup rotation' })).toBeVisible();
        expect(screen.queryByRole('button', { name: 'Set up Weekly cleanup' })).not.toBeInTheDocument();
        expect(screen.getByText('You need the file.delete permission.')).toBeVisible();
    });

    it('sets up a daily restart from its dialog', async () => {
        await renderInServer(<View name='server.schedules.quickSetup' />, seed());

        fireEvent.click(screen.getByRole('button', { name: 'Set up Daily restart' }));
        const dialog = await screen.findByRole('dialog');

        expect(within(dialog).getByText(/Europe\/Berlin/)).toBeVisible();
        // Minecraft warns with say, so the command is not asked for.
        expect(within(dialog).queryByLabelText('Warning command')).not.toBeInTheDocument();
        fireEvent.change(within(dialog).getByLabelText('Restart at'), { target: { value: '05:30' } });
        await act(async () => {
            fireEvent.click(within(dialog).getByRole('button', { name: 'Set up daily restart' }));
        });

        expect(requests).toContainEqual({
            method: 'post',
            url: `/api/client/servers/${uuid}/schedules/presets`,
            body: { preset: 'daily_restart', hour: 5, minute: 30, warning_minutes: 5 },
        });
    });

    it('shows active presets with a link and disables one in a click', async () => {
        const active = schedule(4, 'Daily restart at 04:00 (Europe/Berlin)', { preset: 'daily_restart' });

        await renderInServer(<View name='server.schedules.quickSetup' />, seed([active]));

        expect(screen.getByText('Active')).toBeVisible();
        expect(screen.getByRole('link', { name: 'Daily restart at 04:00 (Europe/Berlin)' })).toHaveAttribute(
            'href',
            '/server/a1b2c3d4/schedules/4'
        );
        expect(screen.queryByRole('button', { name: 'Set up Daily restart' })).not.toBeInTheDocument();
        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Disable Daily restart at 04:00 (Europe/Berlin)' }));
        });

        expect(requests).toContainEqual({
            method: 'post',
            url: `/api/client/servers/${uuid}/schedules/4`,
            body: expect.objectContaining({ name: 'Daily restart at 04:00 (Europe/Berlin)', is_active: false }),
        });
    });

    it('renders nothing for a viewer who cannot set up or disable presets', async () => {
        await renderInServer(
            <ViewFixtureProvider fixtures={{ 'server.schedules.quickSetup': 'readOnly' }}>
                <View name='server.schedules.quickSetup' />
            </ViewFixtureProvider>,
            () => {}
        );

        expect(screen.queryByRole('heading', { name: 'Quick setup' })).not.toBeInTheDocument();
    });
});

describe('server.schedules.quickSetup on another game', () => {
    it('asks for the broadcast command and sends it with the daily restart', async () => {
        await renderInServer(<View name='server.schedules.quickSetup' />, ({ queryClient }) => {
            queryClient.setQueryData(schedulePresetsQueryOptions(uuid).queryKey, {
                object: 'schedule_presets',
                attributes: {
                    timezone: 'UTC',
                    is_minecraft: false,
                    backup_limit: 0,
                    can_send_commands: true,
                    presets: [
                        { key: 'daily_restart' as const, available: true, reason: null },
                        {
                            key: 'backup_rotation' as const,
                            available: false,
                            reason: 'This server cannot keep backups.',
                        },
                        { key: 'restart_when_empty' as const, available: false, reason: 'Not this game.' },
                        {
                            key: 'weekly_cleanup' as const,
                            available: false,
                            reason: 'Weekly cleanup is for Minecraft servers.',
                        },
                    ],
                },
            });
            queryClient.setQueryData(serverSchedulesQueryOptions(uuid).queryKey, { object: 'list', data: [] });
        });

        fireEvent.click(screen.getByRole('button', { name: 'Set up Daily restart' }));
        const dialog = await screen.findByRole('dialog');

        fireEvent.change(within(dialog).getByLabelText('Warning command'), { target: { value: 'broadcast' } });
        await act(async () => {
            fireEvent.click(within(dialog).getByRole('button', { name: 'Set up daily restart' }));
        });

        expect(requests).toContainEqual({
            method: 'post',
            url: `/api/client/servers/${uuid}/schedules/presets`,
            body: { preset: 'daily_restart', hour: 4, minute: 0, warning_minutes: 5, warning_command: 'broadcast' },
        });
        expect(screen.getByText('This server cannot keep backups.')).toBeVisible();
    });
});
