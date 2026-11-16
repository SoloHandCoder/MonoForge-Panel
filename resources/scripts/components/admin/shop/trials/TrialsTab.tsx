import { httpErrorToHuman } from '@/api/http';
import { useAdminShopPlans } from '@/api/admin/shop/queries';
import {
    trialHourOptions,
    trialHours,
    trialStateBadge,
    useAdminShopTrials,
    useUpdateShopTrials,
    type ShopTrialRow,
    type ShopTrials,
    type ShopTrialStats,
} from '@/api/admin/shop/trials';
import Badge from '@/components/elements/Badge';
import Card from '@/components/elements/Card';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import { Form, useAppForm } from '@/components/form';
import { formatDate } from '@/lib/dates';

interface TrialsFormValues {
    enabled: boolean;
    plan_ids: (string | number)[];
    hours: string | number;
    max_concurrent: number | null;
    daily_cap: number | null;
    require_verified: boolean;
}

const dateFormat = 'MMM D, YYYY HH:mm';

function TrialsForm({ data }: { data: ShopTrials }) {
    const update = useUpdateShopTrials();
    const { data: plans } = useAdminShopPlans();
    const defaultValues: TrialsFormValues = {
        enabled: data.settings.enabled,
        plan_ids: data.settings.plan_ids,
        hours: data.settings.hours,
        max_concurrent: data.settings.max_concurrent,
        daily_cap: data.settings.daily_cap,
        require_verified: data.settings.require_verified,
    };
    const form = useAppForm({
        defaultValues,
        onSubmit: async ({ value }) => {
            try {
                await update.mutateAsync({
                    body: {
                        enabled: value.enabled,
                        plan_ids: value.plan_ids.map(Number),
                        hours: trialHours(value.hours),
                        max_concurrent: value.max_concurrent ?? 0,
                        daily_cap: value.daily_cap ?? 0,
                        require_verified: value.require_verified,
                    },
                });
            } catch {
                // Error toast is handled by the mutation.
            }
        },
    });

    return (
        <Form form={form}>
            <Card title='Free trials'>
                <div className='grid gap-5'>
                    <form.AppField name='enabled'>
                        {(field) => (
                            <field.SwitchField
                                label='Offer free trials'
                                description='New players can start one free server on a trial plan. Nothing is charged; when the trial ends the server is suspended and deleted after the grace period in Limits unless they keep it.'
                            />
                        )}
                    </form.AppField>
                    <form.AppField name='plan_ids'>
                        {(field) => (
                            <field.MultiSelectField
                                label='Trial plans'
                                description='The plans players can try. Pick small ones: trials take capacity like any shop server.'
                                options={(plans ?? []).map((plan) => ({ value: plan.id, label: plan.name }))}
                                placeholder='No plans'
                            />
                        )}
                    </form.AppField>
                    <div className='grid gap-5 sm:grid-cols-2'>
                        <form.AppField name='hours'>
                            {(field) => (
                                <field.SelectField
                                    label='Trial length'
                                    options={trialHourOptions.map((hours) => ({
                                        value: hours,
                                        label: `${hours} hours`,
                                    }))}
                                />
                            )}
                        </form.AppField>
                        <form.AppField
                            name='max_concurrent'
                            validators={{
                                onChange: ({ value }) =>
                                    value === null || value < 0 || value > 10_000
                                        ? 'Use a number between 0 and 10000.'
                                        : undefined,
                            }}
                        >
                            {(field) => (
                                <field.NumberField
                                    label='Trials at once'
                                    description='The most trials running at the same time, panel-wide.'
                                    min={0}
                                    max={10_000}
                                />
                            )}
                        </form.AppField>
                        <form.AppField
                            name='daily_cap'
                            validators={{
                                onChange: ({ value }) =>
                                    value === null || value < 0 || value > 10_000
                                        ? 'Use a number between 0 and 10000.'
                                        : undefined,
                            }}
                        >
                            {(field) => (
                                <field.NumberField
                                    label='Trials per day'
                                    description='The most trials started per day, panel-wide, so new accounts cannot take every slot at once.'
                                    min={0}
                                    max={10_000}
                                />
                            )}
                        </form.AppField>
                    </div>
                    <form.AppField name='require_verified'>
                        {(field) => (
                            <field.SwitchField
                                label='Require a linked Discord or Google account'
                                description='Keep this on: it is the main protection against farming trials with new accounts. Turned off, only the network check is left.'
                            />
                        )}
                    </form.AppField>
                    <p className='text-sm text-muted-foreground'>
                        Every player gets one trial, only if they never bought a server. A Discord or Google account and
                        a network (/24 or /48) can start one trial every 30 days, and the captcha applies when one is
                        set up.
                    </p>
                    <div className='flex justify-end'>
                        <form.AppForm>
                            <form.SubmitButton>Save changes</form.SubmitButton>
                        </form.AppForm>
                    </div>
                </div>
            </Card>
        </Form>
    );
}

function StatsCard({ stats }: { stats: ShopTrialStats }) {
    const rows: [string, string][] = [
        ['Started', stats.started.toLocaleString('en-US')],
        ['Running now', stats.running.toLocaleString('en-US')],
        ['Kept (paid)', stats.converted.toLocaleString('en-US')],
        ['Ended without keeping', stats.lapsed.toLocaleString('en-US')],
    ];

    return (
        <Card title='Conversion'>
            <p className='font-header text-4xl font-bold text-foreground tabular-nums'>{stats.conversion_rate}%</p>
            <p className='mb-4 text-sm text-muted-foreground'>of the trials that are over were kept.</p>
            <dl className='grid gap-2 text-sm'>
                {rows.map(([label, value]) => (
                    <div key={label} className='flex items-baseline justify-between gap-3'>
                        <dt className='text-muted-foreground'>{label}</dt>
                        <dd className='font-medium text-foreground tabular-nums'>{value}</dd>
                    </div>
                ))}
            </dl>
        </Card>
    );
}

function TrialItem({ trial }: { trial: ShopTrialRow }) {
    const badge = trialStateBadge(trial.state);

    return (
        <li className='flex flex-col gap-1 border-b border-border py-3 last:border-b-0 sm:flex-row sm:items-center sm:gap-4'>
            <div className='min-w-0 flex-1'>
                <p className='truncate font-medium text-foreground'>
                    {trial.server?.name ?? 'Deleted server'}
                    <span className='font-normal text-muted-foreground'> · {trial.plan?.name ?? 'Deleted plan'}</span>
                </p>
                <p className='truncate text-sm text-muted-foreground'>
                    {trial.user ? trial.user.username : 'Deleted account'} · {trial.hours} h ·{' '}
                    {trial.started_at ? `started ${formatDate(trial.started_at, dateFormat)}` : ''}
                </p>
            </div>
            <div className='flex items-center gap-3 text-sm text-muted-foreground'>
                <span>
                    {trial.converted_at
                        ? `Kept ${formatDate(trial.converted_at, dateFormat)}`
                        : `Ends ${formatDate(trial.ends_at, dateFormat)}`}
                </span>
                <Badge tone={badge.tone} variant='soft' size='sm'>
                    {badge.label}
                </Badge>
            </div>
        </li>
    );
}

function TrialList({ trials }: { trials: ShopTrialRow[] }) {
    return (
        <Card title='Recent trials'>
            {trials.length === 0 ? (
                <p className='text-sm text-muted-foreground'>No trials yet.</p>
            ) : (
                <ul aria-label='Recent trials'>
                    {trials.map((trial) => (
                        <TrialItem key={trial.id} trial={trial} />
                    ))}
                </ul>
            )}
        </Card>
    );
}

/** Free trial servers: the settings, the conversion rate and the latest trials. */
export default function TrialsTab() {
    const { data, error, refetch } = useAdminShopTrials();

    if (error && !data) {
        return <ServerError message={httpErrorToHuman(error)} onRetry={() => void refetch()} />;
    }

    if (!data) {
        return <Spinner size='large' centered />;
    }

    return (
        <div className='grid gap-6'>
            <div className='grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]'>
                <TrialsForm data={data} />
                <div className='lg:self-start'>
                    <StatsCard stats={data.stats} />
                </div>
            </div>
            <TrialList trials={data.trials} />
        </div>
    );
}
