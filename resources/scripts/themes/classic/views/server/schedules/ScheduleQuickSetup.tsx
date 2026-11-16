import { useState } from 'react';
import { Archive, BrushCleaning, RotateCw, UserX, Wand2, type LucideIcon } from 'lucide-react';
import {
    Badge,
    Button,
    Card,
    Dialog,
    Form,
    Icon,
    PanelLink,
    useAppForm,
    type ScheduleQuickSetupModel,
    type SchedulePresetKey,
    type SchedulePresetOption,
    type SchedulePresetValues,
    type ScheduleSummary,
} from '@/sdk';
import type { ViewProps } from '@/sdk/theme';

const icons = {
    daily_restart: RotateCw,
    backup_rotation: Archive,
    restart_when_empty: UserX,
    weekly_cleanup: BrushCleaning,
} satisfies Record<SchedulePresetKey, LucideIcon>;

interface FormProps {
    model: ScheduleQuickSetupModel;
    onSubmit: (values: SchedulePresetValues) => Promise<void>;
}

const between = (value: number | null, min: number, max: number, message: string): string | undefined =>
    value !== null && Number.isInteger(value) && value >= min && value <= max ? undefined : message;

function FormError({ error }: { error: string | null }) {
    return error ? (
        <div className='mb-4 rounded-sm border border-destructive/40 bg-destructive/10 p-3 text-sm text-foreground'>
            {error}
        </div>
    ) : null;
}

function DailyRestartForm({ model, onSubmit }: FormProps) {
    const [error, setError] = useState<string | null>(null);
    const form = useAppForm({
        defaultValues: {
            time: '04:00',
            warningMinutes: (model.canSendCommands ? 5 : 0) as number | null,
            warningCommand: 'say',
        },
        onSubmit: async ({ value }) => {
            setError(null);
            if (!/^\d{2}:\d{2}$/.test(value.time) || value.warningMinutes === null) {
                setError('Pick a time and how many minutes ahead to warn players.');

                return;
            }

            await onSubmit({ preset: 'daily_restart', ...value, warningMinutes: value.warningMinutes });
        },
    });

    return (
        <Form form={form} className='m-0 space-y-6'>
            <FormError error={error} />
            <form.AppField name='time'>
                {(field) => (
                    <field.TextField
                        type='time'
                        label='Restart at'
                        description={`Every day, in the panel's timezone (${model.timezone}).`}
                        required
                    />
                )}
            </form.AppField>
            <form.AppField
                name='warningMinutes'
                validators={{
                    onChange: ({ value }) =>
                        between(
                            value,
                            0,
                            model.canSendCommands ? model.maxWarningMinutes : 0,
                            model.canSendCommands
                                ? `Warn from 0 to ${model.maxWarningMinutes} minutes ahead.`
                                : 'Warning players needs console access, so leave this at 0.'
                        ),
                }}
            >
                {(field) => (
                    <field.NumberField
                        label='Warn players (minutes ahead)'
                        min={0}
                        max={model.canSendCommands ? model.maxWarningMinutes : 0}
                        description={
                            model.isMinecraft
                                ? 'Players see a chat message (say) then, and again one minute before. 0 restarts without a warning.'
                                : 'A console message is sent then, and again one minute before. 0 restarts without a warning.'
                        }
                    />
                )}
            </form.AppField>
            {!model.isMinecraft && (
                <form.AppField name='warningCommand'>
                    {(field) => (
                        <field.TextField
                            label='Warning command'
                            description='The console command that broadcasts a message on this game, such as say or broadcast.'
                        />
                    )}
                </form.AppField>
            )}
            <div className='flex justify-end'>
                <form.AppForm>
                    <form.SubmitButton className='w-full sm:w-auto'>Set up daily restart</form.SubmitButton>
                </form.AppForm>
            </div>
        </Form>
    );
}

function BackupRotationForm({ model, onSubmit }: FormProps) {
    const form = useAppForm({
        defaultValues: { everyHours: 6 as string | number, keep: Math.min(3, model.backupLimit) as number | null },
        onSubmit: async ({ value }) => {
            if (value.keep !== null) {
                await onSubmit({ preset: 'backup_rotation', everyHours: Number(value.everyHours), keep: value.keep });
            }
        },
    });

    return (
        <Form form={form} className='m-0 space-y-6'>
            <form.AppField name='everyHours'>
                {(field) => (
                    <field.SelectField
                        label='Back up'
                        options={model.backupIntervals.map((hours) => ({
                            value: hours,
                            label: hours === 1 ? 'Every hour' : `Every ${hours} hours`,
                        }))}
                    />
                )}
            </form.AppField>
            <form.AppField
                name='keep'
                validators={{
                    onChange: ({ value }) =>
                        between(value, 1, model.backupLimit, `Keep from 1 to ${model.backupLimit} backups.`),
                }}
            >
                {(field) => (
                    <field.NumberField
                        label='Keep'
                        min={1}
                        max={model.backupLimit}
                        description={`This server keeps at most ${model.backupLimit} backups. Once this schedule has made that many, it deletes its oldest unlocked one to make room. Lock a backup to keep it.`}
                    />
                )}
            </form.AppField>
            <div className='flex justify-end'>
                <form.AppForm>
                    <form.SubmitButton className='w-full sm:w-auto'>Set up backup rotation</form.SubmitButton>
                </form.AppForm>
            </div>
        </Form>
    );
}

function NumberPresetForm({
    preset,
    label,
    description,
    max,
    initial,
    submit,
    onSubmit,
}: Omit<FormProps, 'model'> & {
    preset: 'restart_when_empty' | 'weekly_cleanup';
    label: string;
    description: string;
    max: number;
    initial: number;
    submit: string;
}) {
    const form = useAppForm({
        defaultValues: { amount: initial as number | null },
        onSubmit: async ({ value }) => {
            if (value.amount === null) {
                return;
            }

            await onSubmit(
                preset === 'restart_when_empty'
                    ? { preset, hours: value.amount }
                    : { preset: 'weekly_cleanup', days: value.amount }
            );
        },
    });

    return (
        <Form form={form} className='m-0 space-y-6'>
            <form.AppField
                name='amount'
                validators={{ onChange: ({ value }) => between(value, 1, max, `Enter a number from 1 to ${max}.`) }}
            >
                {(field) => <field.NumberField label={label} min={1} max={max} description={description} />}
            </form.AppField>
            <div className='flex justify-end'>
                <form.AppForm>
                    <form.SubmitButton className='w-full sm:w-auto'>{submit}</form.SubmitButton>
                </form.AppForm>
            </div>
        </Form>
    );
}

function PresetForm({
    model,
    preset,
    onDone,
}: {
    model: ScheduleQuickSetupModel;
    preset: SchedulePresetKey;
    onDone: () => void;
}) {
    const onSubmit = async (values: SchedulePresetValues) => {
        if (await model.setUp(values)) {
            onDone();
        }
    };

    switch (preset) {
        case 'daily_restart':
            return <DailyRestartForm model={model} onSubmit={onSubmit} />;
        case 'backup_rotation':
            return <BackupRotationForm model={model} onSubmit={onSubmit} />;
        case 'restart_when_empty':
            return (
                <NumberPresetForm
                    preset='restart_when_empty'
                    label='Hours with nobody online'
                    description='Checked every 15 minutes while the server runs. The server restarts once nobody has been online for this long.'
                    max={72}
                    initial={2}
                    submit='Set up restart when empty'
                    onSubmit={onSubmit}
                />
            );
        case 'weekly_cleanup':
            return (
                <NumberPresetForm
                    preset='weekly_cleanup'
                    label='Delete logs older than (days)'
                    description='Every Monday morning, .log and .log.gz files in logs/ older than this are deleted. latest.log is kept.'
                    max={365}
                    initial={7}
                    submit='Set up weekly cleanup'
                    onSubmit={onSubmit}
                />
            );
    }
}

function ActiveSchedule({ model, schedule }: { model: ScheduleQuickSetupModel; schedule: ScheduleSummary }) {
    return (
        <li className='flex flex-wrap items-center justify-between gap-2'>
            <PanelLink
                destination={{
                    to: '/server/$id/schedules/$scheduleId',
                    params: { id: model.serverId, scheduleId: String(schedule.id) },
                }}
                className='min-w-0 break-words text-sm font-medium hover:text-primary'
            >
                {schedule.name}
            </PanelLink>
            {model.canDisable && (
                <Button.Text
                    size='xsmall'
                    isSecondary
                    disabled={model.disabling}
                    aria-label={`Disable ${schedule.name}`}
                    onClick={() => void model.disable(schedule)}
                >
                    Disable
                </Button.Text>
            )}
        </li>
    );
}

function PresetStatus({ model, option }: { model: ScheduleQuickSetupModel; option: SchedulePresetOption }) {
    if (option.active.length > 0) {
        return (
            <ul className='space-y-2'>
                {option.active.map((schedule) => (
                    <ActiveSchedule key={schedule.id} model={model} schedule={schedule} />
                ))}
            </ul>
        );
    }

    if (!option.available) {
        return option.reason ? <p className='mt-auto text-xs text-muted-foreground'>{option.reason}</p> : null;
    }

    return (
        <div className='mt-auto flex justify-end'>
            <Dialog.Trigger
                trigger={({ onClick }) => (
                    <Button
                        size='xsmall'
                        className='w-full sm:w-auto'
                        aria-label={`Set up ${option.title}`}
                        onClick={onClick}
                    >
                        Set up
                    </Button>
                )}
            >
                {(dialog) => (
                    <Dialog open={dialog.open} title={option.title} onClose={dialog.onClose}>
                        <PresetForm model={model} preset={option.key} onDone={dialog.onClose} />
                    </Dialog>
                )}
            </Dialog.Trigger>
        </div>
    );
}

function PresetTile({ model, option }: { model: ScheduleQuickSetupModel; option: SchedulePresetOption }) {
    const active = option.active.length > 0;

    return (
        <div className='flex min-w-0 flex-col gap-3 rounded-sm border border-border p-4'>
            <div className='flex items-start gap-3'>
                <Icon icon={icons[option.key]} className='mt-0.5 shrink-0 text-muted-foreground' />
                <div className='min-w-0 flex-1'>
                    <div className='flex flex-wrap items-center gap-2'>
                        <h3 className='font-medium'>{option.title}</h3>
                        {active && (
                            <Badge tone='success' variant='soft' size='sm'>
                                Active
                            </Badge>
                        )}
                    </div>
                    <p className='mt-1 text-sm text-muted-foreground'>{option.description}</p>
                </div>
            </div>
            <PresetStatus model={model} option={option} />
        </div>
    );
}

/**
 * Quick setup: presets that make ordinary schedules (shown in the list below, where they can be
 * edited), with the active ones and a one-click switch off.
 */
export default function ScheduleQuickSetup({ model, className }: ViewProps<'server.schedules.quickSetup'>) {
    if (!model.visible) {
        return null;
    }

    return (
        <Card title='Quick setup' icon={Wand2} loading={model.loading || model.settingUp} className={className}>
            <p className='mb-4 text-sm text-muted-foreground'>
                Common schedules in a click. Each one appears in the list below, where you can edit, run or delete it.
                Times are in {model.timezone}.
            </p>
            <div className='grid gap-4 md:grid-cols-2'>
                {model.presets.map((option) => (
                    <PresetTile key={option.key} model={model} option={option} />
                ))}
            </div>
        </Card>
    );
}
