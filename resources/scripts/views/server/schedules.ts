import { useCallback, useMemo } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useNavigate, useParams } from '@tanstack/react-router';
import { relationshipData } from '@/api/relationships';
import {
    createScheduleFromPresetInput,
    createScheduleTaskInput,
    createServerScheduleInput,
    deleteScheduleTaskInput,
    deleteServerScheduleInput,
    executeServerScheduleInput,
    serverScheduleQueryOptions,
    updateScheduleTaskInput,
    updateServerScheduleInput,
    useCreateScheduleFromPreset,
    useCreateServerSchedule,
    useCreateServerScheduleTask,
    useDeleteServerSchedule,
    useDeleteServerScheduleTask,
    useSchedulePresets,
    useServerSchedule,
    useServerSchedules,
    useTriggerServerScheduleExecution,
    useUpdateServerSchedule,
    useUpdateServerScheduleTask,
    type Schedule,
    type SchedulePresetBody,
    type ScheduleValues,
    type Task,
} from '@/api/server/schedules/queries';
import { usePermissions } from '@/plugins/usePermissions';
import { pageStatus, pageStatusFixtures, type PageStatusModel } from '../pageStatus';
import { implementViews } from '../registry';
import { attempt } from './attempt';
import { useLoadedServer } from './loadedServer';

export interface ScheduleCron {
    readonly minute: string;
    readonly hour: string;
    readonly dayOfMonth: string;
    readonly month: string;
    readonly dayOfWeek: string;
}
/** The Quick setup presets a schedule can be made from. */
export type SchedulePresetKey = 'daily_restart' | 'backup_rotation' | 'restart_when_empty' | 'weekly_cleanup';
export interface ScheduleSummary {
    readonly id: number;
    readonly name: string;
    /** The Quick setup preset it was made from, or null for a schedule made by hand. */
    readonly preset: SchedulePresetKey | null;
    readonly cron: ScheduleCron;
    readonly active: boolean;
    /** A run is in progress. */
    readonly processing: boolean;
    readonly onlyWhenOnline: boolean;
    readonly lastRunAt: string | null;
    readonly nextRunAt: string | null;
}
/** What the schedule form edits; the cron fields take cron syntax. */
export interface ScheduleFormValues extends ScheduleCron {
    readonly name: string;
    readonly enabled: boolean;
    readonly onlyWhenOnline: boolean;
}

export type ScheduleTaskAction = 'command' | 'power' | 'backup' | 'restart_if_empty' | 'delete_logs';
export interface ScheduleTask {
    readonly id: number;
    /** The task's place in the run, from 1. */
    readonly sequence: number;
    readonly action: ScheduleTaskAction;
    /**
     * The command, the power signal, the files a backup ignores, the hours a restart when empty
     * waits, or the days of logs a cleanup keeps.
     */
    readonly payload: string;
    /** A backup task keeps only its newest this many backups; null keeps them until the server limit. */
    readonly keepBackups: number | null;
    /** Seconds to wait after the previous task; the first task runs at once. */
    readonly timeOffset: number;
    readonly continueOnFailure: boolean;
}
export interface ScheduleTaskValues {
    readonly action: ScheduleTaskAction;
    readonly payload: string;
    readonly keepBackups: number | null;
    readonly timeOffset: number | null;
    readonly continueOnFailure: boolean;
}
export interface ScheduleTaskOption<T extends string> {
    readonly value: T;
    readonly label: string;
}
/** The options, defaults and validation of the task form. */
export interface ScheduleTaskForm {
    readonly actions: readonly ScheduleTaskOption<ScheduleTaskAction>[];
    readonly powerSignals: readonly ScheduleTaskOption<string>[];
    /** The values a task's form starts from, or a new task's. */
    readonly values: (task?: ScheduleTask) => ScheduleTaskValues;
    /** The payload to show once the form's action changes. */
    readonly payloadFor: (action: ScheduleTaskAction, task?: ScheduleTask) => string;
    readonly validateTimeOffset: (value: number | null) => string | undefined;
    readonly validatePayload: (action: ScheduleTaskAction, payload: string) => string | undefined;
    /** The most backups a backup task may keep: the server's backup limit. */
    readonly maxKeepBackups: number | null;
    readonly validateKeepBackups: (value: number | null) => string | undefined;
    /** Why the values cannot be saved at all, checked when the form is submitted. */
    readonly validate: (values: ScheduleTaskValues) => string | null;
}

export interface ScheduleListModel {
    /** The server's identifier, which links to each schedule include. */
    readonly serverId: string;
    readonly schedules: readonly ScheduleSummary[];
    readonly loading: boolean;
    readonly canCreate: boolean;
    readonly canUpdate: boolean;
    /** The values a new schedule's form starts from. */
    readonly defaults: ScheduleFormValues;
    /** Resolves whether the schedule was created; a failure has already been reported. */
    readonly create: (values: ScheduleFormValues) => Promise<boolean>;
}

export interface ScheduleModel {
    readonly schedule: ScheduleSummary;
    readonly taskCount: number;
    readonly canUpdate: boolean;
    readonly canDelete: boolean;
    /** The values the edit form starts from. */
    readonly values: ScheduleFormValues;
    readonly running: boolean;
    readonly deleting: boolean;
    readonly taskForm: ScheduleTaskForm;
    /** Each resolves whether it succeeded; a failure has already been reported. */
    readonly actions: {
        readonly update: (values: ScheduleFormValues) => Promise<boolean>;
        readonly run: () => Promise<boolean>;
        /** Returns to the schedule list once the schedule is deleted. */
        readonly remove: () => Promise<boolean>;
        readonly createTask: (values: ScheduleTaskValues) => Promise<boolean>;
    };
}
export interface ScheduleTasksModel {
    /** In the order they run. */
    readonly tasks: readonly ScheduleTask[];
    readonly canUpdate: boolean;
    readonly deleting: boolean;
    readonly form: ScheduleTaskForm;
    /** Each resolves whether it succeeded; a failure has already been reported. */
    readonly actions: {
        readonly create: (values: ScheduleTaskValues) => Promise<boolean>;
        readonly update: (id: number, values: ScheduleTaskValues) => Promise<boolean>;
        readonly remove: (id: number) => Promise<boolean>;
    };
}

/** What setting up a preset takes; the time is `HH:MM` in the panel's timezone. */
export type SchedulePresetValues =
    | {
          readonly preset: 'daily_restart';
          readonly time: string;
          readonly warningMinutes: number;
          /** The console command that broadcasts the warning on games other than Minecraft. */
          readonly warningCommand: string;
      }
    | { readonly preset: 'backup_rotation'; readonly everyHours: number; readonly keep: number }
    | { readonly preset: 'restart_when_empty'; readonly hours: number }
    | { readonly preset: 'weekly_cleanup'; readonly days: number };
export interface SchedulePresetOption {
    readonly key: SchedulePresetKey;
    readonly title: string;
    readonly description: string;
    /** The viewer can set it up on this server. */
    readonly available: boolean;
    /** Why it cannot be set up, when it cannot. */
    readonly reason: string | null;
    /** The active schedules made from it. */
    readonly active: readonly ScheduleSummary[];
}
/** The Quick setup card on the Schedules page. */
export interface ScheduleQuickSetupModel {
    /** The viewer can set up a preset, or one is active; the card renders nothing otherwise. */
    readonly visible: boolean;
    readonly loading: boolean;
    /** The server's identifier, which links to each schedule. */
    readonly serverId: string;
    /** The timezone the times are in: the panel's. */
    readonly timezone: string;
    /** Minecraft Java: warnings use `say`; other games pick the command. */
    readonly isMinecraft: boolean;
    readonly backupLimit: number;
    /** The viewer may send console commands, which a restart warning needs. */
    readonly canSendCommands: boolean;
    /** The viewer may switch a preset's schedule off (schedule.update). */
    readonly canDisable: boolean;
    readonly backupIntervals: readonly number[];
    readonly maxWarningMinutes: number;
    readonly presets: readonly SchedulePresetOption[];
    readonly settingUp: boolean;
    readonly disabling: boolean;
    /** Resolves whether the preset was set up; a failure has already been reported. */
    readonly setUp: (values: SchedulePresetValues) => Promise<boolean>;
    /** Switches the schedule off; it stays in the list, where it can be edited or deleted. */
    readonly disable: (schedule: ScheduleSummary) => Promise<boolean>;
}

declare module '../registry' {
    interface ViewModels {
        'page.server.schedules': PageStatusModel;
        'page.server.schedules.detail': PageStatusModel;
        'server.schedules': ScheduleListModel;
        'server.schedules.quickSetup': ScheduleQuickSetupModel;
        'server.schedule': ScheduleModel;
        'server.schedule.tasks': ScheduleTasksModel;
    }
}

const newSchedule: ScheduleFormValues = {
    name: '',
    minute: '*/5',
    hour: '*',
    dayOfMonth: '*',
    month: '*',
    dayOfWeek: '*',
    enabled: true,
    onlyWhenOnline: true,
};

const scheduleSummary = ({ attributes }: Schedule): ScheduleSummary => ({
    id: attributes.id,
    name: attributes.name,
    preset: attributes.preset,
    cron: {
        minute: attributes.cron.minute,
        hour: attributes.cron.hour,
        dayOfMonth: attributes.cron.day_of_month,
        month: attributes.cron.month,
        dayOfWeek: attributes.cron.day_of_week,
    },
    active: attributes.is_active,
    processing: attributes.is_processing,
    onlyWhenOnline: attributes.only_when_online,
    lastRunAt: attributes.last_run_at,
    nextRunAt: attributes.next_run_at,
});

const scheduleFormValues = ({ name, cron, active, onlyWhenOnline }: ScheduleSummary): ScheduleFormValues => ({
    name: name || newSchedule.name,
    minute: cron.minute || newSchedule.minute,
    hour: cron.hour || newSchedule.hour,
    dayOfMonth: cron.dayOfMonth || newSchedule.dayOfMonth,
    month: cron.month || newSchedule.month,
    dayOfWeek: cron.dayOfWeek || newSchedule.dayOfWeek,
    enabled: active,
    onlyWhenOnline,
});

const scheduleValues = (values: ScheduleFormValues): ScheduleValues => ({
    name: values.name,
    cron: {
        minute: values.minute,
        hour: values.hour,
        dayOfWeek: values.dayOfWeek,
        month: values.month,
        dayOfMonth: values.dayOfMonth,
    },
    onlyWhenOnline: values.onlyWhenOnline,
    isActive: values.enabled,
});

const scheduleTask = ({ attributes }: Task): ScheduleTask => ({
    id: attributes.id,
    sequence: attributes.sequence_id,
    action: attributes.action,
    payload: attributes.payload,
    keepBackups: attributes.keep_backups,
    timeOffset: attributes.time_offset,
    continueOnFailure: attributes.continue_on_failure,
});

const orderedTasks = (schedule: Schedule): ScheduleTask[] =>
    relationshipData(schedule.attributes.relationships?.tasks)
        .map(scheduleTask)
        .sort((a, b) => a.sequence - b.sequence);

const validateTimeOffset = (value: number | null): string | undefined => {
    if (value === null) {
        return 'A time offset must be provided.';
    }

    if (value < 0) {
        return 'The time offset must be at least 0 seconds.';
    }

    if (value > 900) {
        return 'The time offset must be less than 900 seconds.';
    }

    return undefined;
};

const defaultPayloads = {
    command: '',
    power: 'start',
    backup: '',
    restart_if_empty: '2',
    delete_logs: '7',
} satisfies Record<ScheduleTaskAction, string>;

/** Whether the text is a whole number from min to max. */
const wholeNumberIn = (value: string, min: number, max: number): boolean =>
    /^\d+$/.test(value.trim()) && Number(value) >= min && Number(value) <= max;

/** Why the payload cannot be saved for the action; power and backup payloads always can. */
const payloadErrors = {
    command: (payload: string) => (payload.length > 0 ? null : 'A task payload must be provided.'),
    power: () => null,
    backup: () => null,
    restart_if_empty: (payload: string) =>
        wholeNumberIn(payload, 1, 72) ? null : 'Enter the hours to wait, from 1 to 72.',
    delete_logs: (payload: string) =>
        wholeNumberIn(payload, 1, 365) ? null : 'Enter the days of logs to keep, from 1 to 365.',
} satisfies Record<ScheduleTaskAction, (payload: string) => string | null>;

const validatePayload = (action: ScheduleTaskAction, payload: string): string | undefined =>
    payloadErrors[action](payload) ?? undefined;

/** Why a backup task cannot keep that many backups; empty keeps them until the server limit. */
const keepBackupsError = (value: number | null, limit: number | null): string | null => {
    if (value === null) {
        return null;
    }

    if (!Number.isInteger(value) || value < 1) {
        return 'Keep at least 1 backup, or leave it empty.';
    }

    return limit !== null && value > limit ? `This server keeps at most ${limit} backups.` : null;
};

/** The task form for a server allowed `backupLimit` backups. */
export function scheduleTaskForm(backupLimit: number | null): ScheduleTaskForm {
    const initialAction = (task?: ScheduleTask): ScheduleTaskAction => task?.action || 'command';

    return {
        actions: [
            { value: 'command', label: 'Send command' },
            { value: 'power', label: 'Send power action' },
            { value: 'backup', label: 'Create backup' },
            { value: 'restart_if_empty', label: 'Restart when empty' },
            { value: 'delete_logs', label: 'Delete old logs' },
        ],
        powerSignals: [
            { value: 'start', label: 'Start the server' },
            { value: 'restart', label: 'Restart the server' },
            { value: 'stop', label: 'Stop the server' },
            { value: 'kill', label: 'Terminate the server' },
        ],
        values: (task) => ({
            action: initialAction(task),
            payload: task?.payload || '',
            keepBackups: task?.keepBackups ?? null,
            timeOffset: task?.timeOffset ?? 0,
            continueOnFailure: task?.continueOnFailure || false,
        }),
        payloadFor: (action, task) => {
            if (action === initialAction(task)) {
                return task?.payload || '';
            }

            return defaultPayloads[action];
        },
        validateTimeOffset,
        validatePayload,
        maxKeepBackups: backupLimit,
        validateKeepBackups: (value) => keepBackupsError(value, backupLimit) ?? undefined,
        validate: ({ action }) =>
            backupLimit === 0 && action === 'backup'
                ? "A backup task cannot be created when the server's backup limit is set to 0."
                : null,
    };
}

function useScheduleId(): number {
    const { scheduleId } = useParams({ strict: false });

    return Number(scheduleId);
}

/**
 * The route's schedule, which the detail page loads before its blocks render. A deleted schedule's
 * blocks keep its last copy while the page navigates away, rather than fetching it again.
 */
function useLoadedSchedule(): Schedule {
    const { uuid } = useLoadedServer().attributes;
    const { data } = useQuery({
        ...serverScheduleQueryOptions(uuid, useScheduleId()),
        enabled: false,
        placeholderData: keepPreviousData,
    });

    if (!data) {
        throw new Error('Schedule views render once the detail page has loaded the schedule.');
    }

    return data;
}

function useTaskForm(): ScheduleTaskForm {
    const backupLimit = useLoadedServer().attributes.feature_limits.backups;

    return useMemo(() => scheduleTaskForm(backupLimit), [backupLimit]);
}

/** Creates a task on the schedule; the time offset must have passed validation. */
function useCreateTask(schedule: Schedule): (values: ScheduleTaskValues) => Promise<boolean> {
    const { uuid } = useLoadedServer().attributes;
    const { mutateAsync } = useCreateServerScheduleTask(schedule);

    return useCallback(
        ({ timeOffset, ...values }: ScheduleTaskValues) =>
            attempt(() =>
                mutateAsync(createScheduleTaskInput(uuid, schedule, { ...values, timeOffset: timeOffset ?? 0 }))
            ),
        [mutateAsync, uuid, schedule]
    );
}

const useSchedulesPageModel = () => pageStatus(useServerSchedules(useLoadedServer().attributes.uuid));

function useScheduleListModel(): ScheduleListModel {
    const { uuid, identifier } = useLoadedServer().attributes;
    const { data, isLoading } = useServerSchedules(uuid);
    const [canCreate, canUpdate] = usePermissions(['schedule.create', 'schedule.update']);
    const { mutateAsync } = useCreateServerSchedule();
    const create = useCallback(
        (values: ScheduleFormValues) =>
            attempt(() => mutateAsync(createServerScheduleInput(uuid, scheduleValues(values)))),
        [mutateAsync, uuid]
    );

    return useMemo(
        () => ({
            serverId: identifier,
            schedules: (data?.data ?? []).map(scheduleSummary),
            loading: isLoading,
            canCreate,
            canUpdate,
            defaults: newSchedule,
            create,
        }),
        [identifier, data, isLoading, canCreate, canUpdate, create]
    );
}

/** The order and copy of the Quick setup presets. */
const presetCopy: readonly { key: SchedulePresetKey; title: string; description: string }[] = [
    {
        key: 'daily_restart',
        title: 'Daily restart',
        description: 'Restart every day at a set time, warning players first.',
    },
    {
        key: 'backup_rotation',
        title: 'Backup rotation',
        description: 'Back up every few hours and keep only the newest backups.',
    },
    {
        key: 'restart_when_empty',
        title: 'Restart when empty',
        description: 'Restart once nobody has played for a few hours.',
    },
    { key: 'weekly_cleanup', title: 'Weekly cleanup', description: 'Delete old log files every Monday morning.' },
];

const BACKUP_INTERVALS = [1, 2, 3, 4, 6, 8, 12, 24] as const;
const MAX_WARNING_MINUTES = 15;

/** The request body for a preset; a time the form let through as `HH:MM`. */
function presetBody(values: SchedulePresetValues, isMinecraft: boolean): SchedulePresetBody {
    switch (values.preset) {
        case 'daily_restart': {
            const [hour = 0, minute = 0] = values.time.split(':').map(Number);
            const command = values.warningCommand.trim();

            const body: SchedulePresetBody = {
                preset: 'daily_restart',
                hour,
                minute,
                warning_minutes: values.warningMinutes,
            };

            // Minecraft always warns with `say`; other games may name their broadcast command.
            if (!isMinecraft && command !== '') {
                body.warning_command = command;
            }

            return body;
        }

        case 'backup_rotation':
            return { preset: 'backup_rotation', every_hours: values.everyHours, keep: values.keep };
        case 'restart_when_empty':
            return { preset: 'restart_when_empty', hours: values.hours };
        case 'weekly_cleanup':
            return { preset: 'weekly_cleanup', days: values.days };
    }
}

function useScheduleQuickSetupModel(): ScheduleQuickSetupModel {
    const { uuid, identifier } = useLoadedServer().attributes;
    const { data: presets, isLoading: presetsLoading } = useSchedulePresets(uuid);
    const { data: schedules, isLoading: schedulesLoading } = useServerSchedules(uuid);
    const [canCreate, canDisable] = usePermissions(['schedule.create', 'schedule.update']);
    const { mutateAsync: createFromPreset, isPending: settingUp } = useCreateScheduleFromPreset();
    const { mutateAsync: updateSchedule, isPending: disabling } = useUpdateServerSchedule();
    const isMinecraft = presets?.is_minecraft ?? false;

    const setUp = useCallback(
        (values: SchedulePresetValues) =>
            attempt(() => createFromPreset(createScheduleFromPresetInput(uuid, presetBody(values, isMinecraft)))),
        [createFromPreset, uuid, isMinecraft]
    );
    const disable = useCallback(
        (schedule: ScheduleSummary) =>
            attempt(() =>
                updateSchedule(
                    updateServerScheduleInput(
                        uuid,
                        schedule.id,
                        scheduleValues({ ...scheduleFormValues(schedule), enabled: false })
                    )
                )
            ),
        [updateSchedule, uuid]
    );

    return useMemo(() => {
        const summaries = (schedules?.data ?? []).map(scheduleSummary);
        const options = presetCopy.map(({ key, title, description }) => {
            const preset = presets?.presets.find((entry) => entry.key === key);

            return {
                key,
                title,
                description,
                available: preset?.available ?? false,
                reason: preset?.reason ?? null,
                active: summaries.filter((schedule) => schedule.preset === key && schedule.active),
            };
        });

        return {
            visible: presets !== undefined && (canCreate || options.some((option) => option.active.length > 0)),
            loading: presetsLoading || schedulesLoading,
            serverId: identifier,
            timezone: presets?.timezone ?? 'UTC',
            isMinecraft,
            backupLimit: presets?.backup_limit ?? 0,
            canSendCommands: presets?.can_send_commands ?? false,
            canDisable,
            backupIntervals: BACKUP_INTERVALS,
            maxWarningMinutes: MAX_WARNING_MINUTES,
            presets: options,
            settingUp,
            disabling,
            setUp,
            disable,
        };
    }, [
        presets,
        schedules,
        presetsLoading,
        schedulesLoading,
        identifier,
        isMinecraft,
        canCreate,
        canDisable,
        settingUp,
        disabling,
        setUp,
        disable,
    ]);
}

const useScheduleDetailPageModel = () =>
    pageStatus(useServerSchedule(useLoadedServer().attributes.uuid, useScheduleId()));

function useScheduleModel(): ScheduleModel {
    const navigate = useNavigate();
    const { uuid, identifier } = useLoadedServer().attributes;
    const schedule = useLoadedSchedule();
    const [canUpdate, canDelete] = usePermissions(['schedule.update', 'schedule.delete']);
    const { mutateAsync: updateSchedule } = useUpdateServerSchedule();
    const { mutateAsync: runSchedule, isPending: running } = useTriggerServerScheduleExecution(schedule);
    const { mutateAsync: deleteSchedule, isPending: deleting } = useDeleteServerSchedule();
    const createTask = useCreateTask(schedule);
    const taskForm = useTaskForm();
    const { id } = schedule.attributes;

    const actions = useMemo(
        () => ({
            update: (values: ScheduleFormValues) =>
                attempt(() => updateSchedule(updateServerScheduleInput(uuid, id, scheduleValues(values)))),
            run: () => attempt(() => runSchedule(executeServerScheduleInput(uuid, schedule))),
            remove: async () => {
                const removed = await attempt(() => deleteSchedule(deleteServerScheduleInput(uuid, id)));

                if (removed) {
                    void navigate({ to: '/server/$id/schedules', params: { id: identifier } });
                }

                return removed;
            },
            createTask,
        }),
        [updateSchedule, runSchedule, deleteSchedule, createTask, navigate, uuid, identifier, id, schedule]
    );

    return useMemo(() => {
        const summary = scheduleSummary(schedule);

        return {
            schedule: summary,
            taskCount: relationshipData(schedule.attributes.relationships?.tasks).length,
            canUpdate,
            canDelete,
            values: scheduleFormValues(summary),
            running,
            deleting,
            taskForm,
            actions,
        };
    }, [schedule, canUpdate, canDelete, running, deleting, taskForm, actions]);
}

function useScheduleTasksModel(): ScheduleTasksModel {
    const { uuid } = useLoadedServer().attributes;
    const schedule = useLoadedSchedule();
    const [canUpdate] = usePermissions('schedule.update');
    const create = useCreateTask(schedule);
    const { mutateAsync: updateTask } = useUpdateServerScheduleTask(schedule);
    const { mutateAsync: deleteTask, isPending: deleting } = useDeleteServerScheduleTask(schedule);
    const form = useTaskForm();

    const actions = useMemo(
        () => ({
            create,
            update: (id: number, { timeOffset, ...values }: ScheduleTaskValues) =>
                attempt(() =>
                    updateTask(updateScheduleTaskInput(uuid, schedule, id, { ...values, timeOffset: timeOffset ?? 0 }))
                ),
            remove: (id: number) => attempt(() => deleteTask(deleteScheduleTaskInput(uuid, schedule, id))),
        }),
        [create, updateTask, deleteTask, uuid, schedule]
    );

    return useMemo(
        () => ({ tasks: orderedTasks(schedule), canUpdate, deleting, form, actions }),
        [schedule, canUpdate, deleting, form, actions]
    );
}

const resolved = async () => true;
const scheduleFixture = (options: Partial<ScheduleSummary> = {}): ScheduleSummary => ({
    id: 1,
    name: 'Nightly restart',
    preset: null,
    cron: { minute: '0', hour: '4', dayOfMonth: '*', month: '*', dayOfWeek: '*' },
    active: true,
    processing: false,
    onlyWhenOnline: true,
    lastRunAt: '2026-10-11T04:00:00Z',
    nextRunAt: '2026-10-12T04:00:00Z',
    ...options,
});

const scheduleFixtures: readonly ScheduleSummary[] = [
    scheduleFixture(),
    scheduleFixture({
        id: 2,
        name: 'Hourly backup',
        cron: { minute: '0', hour: '*/1', dayOfMonth: '*', month: '*', dayOfWeek: '*' },
        processing: true,
        lastRunAt: '2026-10-12T09:00:00Z',
        nextRunAt: '2026-10-12T10:00:00Z',
    }),
    scheduleFixture({
        id: 3,
        name: 'Weekend event',
        cron: { minute: '30', hour: '18', dayOfMonth: '*', month: '*', dayOfWeek: 'SAT,SUN' },
        active: false,
        lastRunAt: null,
        nextRunAt: null,
    }),
];

const listFixture = (options: Partial<ScheduleListModel> = {}): ScheduleListModel => ({
    serverId: 'a1b2c3d4',
    schedules: scheduleFixtures,
    loading: false,
    canCreate: true,
    canUpdate: true,
    defaults: newSchedule,
    create: resolved,
    ...options,
});

const taskFixtures: readonly ScheduleTask[] = [
    {
        id: 1,
        sequence: 1,
        action: 'command',
        payload: 'say Restarting in 5 minutes',
        keepBackups: null,
        timeOffset: 0,
        continueOnFailure: true,
    },
    { id: 2, sequence: 2, action: 'backup', payload: '', keepBackups: 3, timeOffset: 240, continueOnFailure: false },
    {
        id: 3,
        sequence: 3,
        action: 'power',
        payload: 'restart',
        keepBackups: null,
        timeOffset: 60,
        continueOnFailure: false,
    },
];

const otherGameReasons = {
    daily_restart: null,
    backup_rotation: null,
    restart_when_empty: 'This needs a game that tells the panel who is online (Minecraft Java for now).',
    weekly_cleanup: 'Weekly cleanup is for Minecraft servers.',
} satisfies Record<SchedulePresetKey, string | null>;

const quickSetupFixture = (options: Partial<ScheduleQuickSetupModel> = {}): ScheduleQuickSetupModel => ({
    visible: true,
    loading: false,
    serverId: 'a1b2c3d4',
    timezone: 'Europe/Berlin',
    isMinecraft: true,
    backupLimit: 3,
    canSendCommands: true,
    canDisable: true,
    backupIntervals: BACKUP_INTERVALS,
    maxWarningMinutes: MAX_WARNING_MINUTES,
    presets: presetCopy.map((copy) => ({ ...copy, available: true, reason: null, active: [] })),
    settingUp: false,
    disabling: false,
    setUp: resolved,
    disable: resolved,
    ...options,
});

const scheduleModelFixture = (options: Partial<ScheduleModel> = {}): ScheduleModel => ({
    schedule: scheduleFixture(),
    taskCount: taskFixtures.length,
    canUpdate: true,
    canDelete: true,
    values: scheduleFormValues(scheduleFixture()),
    running: false,
    deleting: false,
    taskForm: scheduleTaskForm(3),
    actions: { update: resolved, run: resolved, remove: resolved, createTask: resolved },
    ...options,
});

const tasksFixture = (options: Partial<ScheduleTasksModel> = {}): ScheduleTasksModel => ({
    tasks: taskFixtures,
    canUpdate: true,
    deleting: false,
    form: scheduleTaskForm(3),
    actions: { create: resolved, update: resolved, remove: resolved },
    ...options,
});

implementViews({
    'page.server.schedules': {
        use: useSchedulesPageModel,
        fixtures: pageStatusFixtures,
    },
    'server.schedules': {
        use: useScheduleListModel,
        fixtures: {
            schedules: () => listFixture(),
            empty: () => listFixture({ schedules: [] }),
            readOnly: () => listFixture({ canCreate: false, canUpdate: false }),
            loading: () => listFixture({ schedules: [], loading: true }),
        },
    },
    'server.schedules.quickSetup': {
        use: useScheduleQuickSetupModel,
        fixtures: {
            presets: () => quickSetupFixture(),
            active: () =>
                quickSetupFixture({
                    presets: presetCopy.map((copy) => ({
                        ...copy,
                        available: true,
                        reason: null,
                        active:
                            copy.key === 'daily_restart'
                                ? [
                                      scheduleFixture({
                                          name: 'Daily restart at 04:00 (Europe/Berlin)',
                                          preset: 'daily_restart',
                                      }),
                                  ]
                                : [],
                    })),
                }),
            otherGame: () =>
                quickSetupFixture({
                    isMinecraft: false,
                    presets: presetCopy.map(({ key, title, description }) => ({
                        key,
                        title,
                        description,
                        available: otherGameReasons[key] === null,
                        reason: otherGameReasons[key],
                        active: [],
                    })),
                }),
            readOnly: () => quickSetupFixture({ visible: false, canDisable: false }),
        },
    },
    'page.server.schedules.detail': {
        use: useScheduleDetailPageModel,
        fixtures: pageStatusFixtures,
    },
    'server.schedule': {
        use: useScheduleModel,
        fixtures: {
            active: () => scheduleModelFixture(),
            processing: () => scheduleModelFixture({ schedule: scheduleFixture({ processing: true }) }),
            inactive: () =>
                scheduleModelFixture({
                    schedule: scheduleFixture({ active: false, lastRunAt: null, nextRunAt: null }),
                    taskCount: 0,
                }),
            readOnly: () => scheduleModelFixture({ canUpdate: false, canDelete: false }),
        },
    },
    'server.schedule.tasks': {
        use: useScheduleTasksModel,
        fixtures: {
            tasks: () => tasksFixture(),
            empty: () => tasksFixture({ tasks: [] }),
            readOnly: () => tasksFixture({ canUpdate: false }),
            noBackups: () => tasksFixture({ form: scheduleTaskForm(0) }),
        },
    },
});
