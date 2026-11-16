import { useMemo, useState, type FormEvent, type ReactNode } from 'react';
import {
    AlertTriangle,
    Bot,
    CalendarClock,
    Coins,
    Download,
    Moon,
    Repeat,
    TrendingDown,
    UserPlus,
    Wallet,
    type LucideIcon,
} from 'lucide-react';
import { httpErrorToHuman } from '@/api/http';
import { useAdminRevenue, type AdminRevenue } from '@/api/admin/billing/queries';
import AdminContentBlock from '@/components/admin/AdminContentBlock';
import DailyBarChart from '@/components/admin/billing/DailyBarChart';
import {
    REVENUE_PRESETS,
    customRangeError,
    downloadRevenueCsv,
    formatCount,
    formatDay,
    formatMoney,
    isoDate,
    revenueQuery,
    type RevenuePreset,
} from '@/components/admin/billing/revenueFormat';
import Badge from '@/components/elements/Badge';
import Button from '@/components/elements/Button';
import Card from '@/components/elements/Card';
import Icon from '@/components/elements/Icon';
import Label from '@/components/elements/Label';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import { TextInput } from '@/components/form/controls';
import { cn } from '@/lib/cn';

const tokens = (value: number) => `${formatCount(value)} ${value === 1 ? 'token' : 'tokens'}`;

function Kpi({ icon, label, value, detail }: { icon: LucideIcon; label: string; value: string; detail?: ReactNode }) {
    return (
        <div className='flex min-w-0 items-start gap-3 rounded-xl border border-border bg-card p-4'>
            <div className='rounded-sm bg-muted p-2 text-accent'>
                <Icon icon={icon} className='size-4' aria-hidden />
            </div>
            <div className='min-w-0'>
                <p className='text-sm text-muted-foreground'>{label}</p>
                <p className='truncate font-mono text-xl text-foreground tabular-nums'>{value}</p>
                {detail && <p className='text-xs text-muted-foreground'>{detail}</p>}
            </div>
        </div>
    );
}

/** A label and value on one row, for the cards' smaller figures. */
function Fact({ label, value, tone }: { label: string; value: ReactNode; tone?: 'warning' }) {
    return (
        <div className='flex items-baseline justify-between gap-4 py-1.5 text-sm'>
            <dt className='text-muted-foreground'>{label}</dt>
            <dd className={cn('text-right text-foreground tabular-nums', tone === 'warning' && 'font-medium')}>
                {value}
            </dd>
        </div>
    );
}

function MoneyCard({ report }: { report: AdminRevenue }) {
    return (
        <Card title='Money in' icon={Wallet}>
            {report.money.length === 0 ? (
                <p className='text-sm text-muted-foreground'>No Tebex payments in this range.</p>
            ) : (
                <div className='-mx-1 overflow-x-auto'>
                    <table className='w-full min-w-md text-sm'>
                        <caption className='sr-only'>Money per currency; currencies are never added together.</caption>
                        <thead>
                            <tr className='border-b border-border text-left text-xs text-muted-foreground'>
                                <th scope='col' className='px-1 py-2 font-medium'>
                                    Currency
                                </th>
                                <th scope='col' className='px-1 py-2 text-right font-medium'>
                                    Paid
                                </th>
                                <th scope='col' className='px-1 py-2 text-right font-medium'>
                                    Refunded
                                </th>
                                <th scope='col' className='px-1 py-2 text-right font-medium'>
                                    Charged back
                                </th>
                                <th scope='col' className='px-1 py-2 text-right font-medium'>
                                    Net
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {report.money.map((row) => (
                                <tr key={row.currency} className='border-b border-border last:border-0'>
                                    <th scope='row' className='px-1 py-2 text-left font-medium text-foreground'>
                                        {row.currency}
                                    </th>
                                    <td className='px-1 py-2 text-right tabular-nums'>
                                        {formatMoney(row.gross, row.currency)}
                                        <span className='block text-xs text-muted-foreground'>
                                            {formatCount(row.payments)} {row.payments === 1 ? 'payment' : 'payments'}
                                        </span>
                                    </td>
                                    <td className='px-1 py-2 text-right tabular-nums'>
                                        {formatMoney(row.refunded, row.currency)}
                                        <span className='block text-xs text-muted-foreground'>
                                            {formatCount(row.refunds)}
                                        </span>
                                    </td>
                                    <td className='px-1 py-2 text-right tabular-nums'>
                                        {formatMoney(row.charged_back, row.currency)}
                                        <span className='block text-xs text-muted-foreground'>
                                            {formatCount(row.chargebacks)}
                                        </span>
                                    </td>
                                    <td className='px-1 py-2 text-right font-medium text-foreground tabular-nums'>
                                        {formatMoney(row.net, row.currency)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            {report.disputes_open > 0 && (
                <p className='mt-3 flex items-center gap-2 text-sm text-muted-foreground'>
                    <Icon icon={AlertTriangle} className='size-4 text-warning' aria-hidden />
                    {formatCount(report.disputes_open)} {report.disputes_open === 1 ? 'dispute is' : 'disputes are'}{' '}
                    open; their tokens are held.
                </p>
            )}
        </Card>
    );
}

function SubscriptionsCard({ report }: { report: AdminRevenue }) {
    const { subscriptions, renewals_due: renewals, churn } = report;

    return (
        <Card title='Subscriptions' icon={Repeat}>
            <dl className='divide-y divide-border'>
                <Fact label='Active' value={formatCount(subscriptions.active)} />
                <Fact label='Worth per 30 days' value={tokens(subscriptions.mrr_tokens)} />
                <Fact label='Suspended for not being paid' value={formatCount(subscriptions.suspended)} />
            </dl>
            <h3 className='mt-4 mb-1 flex items-center gap-1.5 text-xs font-medium tracking-wide text-muted-foreground uppercase'>
                <Icon icon={CalendarClock} className='size-3.5' aria-hidden />
                Renewals in the next {renewals.days} days
            </h3>
            <dl className='divide-y divide-border'>
                <Fact label='Due' value={`${formatCount(renewals.count)} · ${tokens(renewals.tokens)}`} />
                <Fact
                    label='Not covered by the balance'
                    tone={renewals.short_of_balance > 0 ? 'warning' : undefined}
                    value={
                        renewals.short_of_balance > 0 ? (
                            <Badge tone='warning' variant='soft' size='sm'>
                                <Icon icon={AlertTriangle} className='mr-1 inline size-3' aria-hidden />
                                {formatCount(renewals.short_of_balance)} short
                            </Badge>
                        ) : (
                            'None'
                        )
                    }
                />
            </dl>
            <h3 className='mt-4 mb-1 flex items-center gap-1.5 text-xs font-medium tracking-wide text-muted-foreground uppercase'>
                <Icon icon={TrendingDown} className='size-3.5' aria-hidden />
                Churn in this range
            </h3>
            <dl className='divide-y divide-border'>
                <Fact label='Cancelled' value={formatCount(churn.cancelled)} />
                <Fact label='Deleted unpaid' value={formatCount(churn.unpaid)} />
                <Fact label='Deleted while running' value={formatCount(churn.deleted)} />
                <Fact label='Worth per 30 days' value={tokens(churn.mrr_tokens)} />
            </dl>
        </Card>
    );
}

function TopPlansCard({ report }: { report: AdminRevenue }) {
    const most = Math.max(1, ...report.top_plans.map((plan) => plan.active));

    return (
        <Card title='Top plans' icon={Coins}>
            {report.top_plans.length === 0 ? (
                <p className='text-sm text-muted-foreground'>No active subscriptions yet.</p>
            ) : (
                <ol className='space-y-3'>
                    {report.top_plans.map((plan) => (
                        <li key={plan.id ?? 'deleted'} className='text-sm'>
                            <div className='mb-1 flex items-baseline justify-between gap-3'>
                                <span className='truncate text-foreground' title={plan.name}>
                                    {plan.name}
                                </span>
                                <span className='shrink-0 text-muted-foreground tabular-nums'>
                                    {formatCount(plan.active)} active · {tokens(plan.mrr_tokens)} / 30 days
                                </span>
                            </div>
                            <div className='h-2 rounded-full bg-muted' aria-hidden>
                                <div
                                    className='h-2 rounded-full'
                                    style={{
                                        width: `${(plan.active / most) * 100}%`,
                                        backgroundColor: 'var(--chart-1)',
                                    }}
                                />
                            </div>
                        </li>
                    ))}
                </ol>
            )}
        </Card>
    );
}

function RevenueReport({ report }: { report: AdminRevenue }) {
    const sold = useMemo(() => report.daily.map((day) => ({ date: day.date, value: day.tokens_sold })), [report]);
    const users = useMemo(() => report.daily.map((day) => ({ date: day.date, value: day.new_users })), [report]);
    const reversed = report.tokens.refunded + report.tokens.charged_back;

    return (
        <div className='space-y-4'>
            <div className='grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4'>
                <Kpi
                    icon={Coins}
                    label='Tokens sold'
                    value={formatCount(report.tokens.sold)}
                    detail={`${formatCount(report.tokens.purchases)} ${report.tokens.purchases === 1 ? 'purchase' : 'purchases'}`}
                />
                <Kpi
                    icon={Wallet}
                    label='Net tokens'
                    value={formatCount(report.tokens.net)}
                    detail={reversed > 0 ? `${tokens(reversed)} refunded or charged back` : 'Nothing refunded'}
                />
                <Kpi
                    icon={UserPlus}
                    label='Paying users'
                    value={formatCount(report.users.paying)}
                    detail={`${formatCount(report.users.new)} new ${report.users.new === 1 ? 'account' : 'accounts'}`}
                />
                <Kpi
                    icon={Repeat}
                    label='Spent on servers'
                    value={formatCount(report.tokens.spent_on_servers)}
                    detail='Renewals, purchases and add-ons'
                />
            </div>
            <div className='grid grid-cols-1 gap-4 xl:grid-cols-2'>
                <DailyBarChart title='Tokens sold per day' valueLabel='Tokens sold' data={sold} format={formatCount} />
                <DailyBarChart
                    title='New accounts per day'
                    valueLabel='New accounts'
                    data={users}
                    format={formatCount}
                />
            </div>
            <div className='grid grid-cols-1 gap-4 xl:grid-cols-2'>
                <MoneyCard report={report} />
                <SubscriptionsCard report={report} />
            </div>
            <div className='grid grid-cols-1 gap-4 xl:grid-cols-2'>
                <TopPlansCard report={report} />
                <div className='grid grid-cols-1 content-start gap-4'>
                    <Kpi
                        icon={Moon}
                        label='Saved by auto-sleep'
                        value={tokens(report.sleep_saved_tokens)}
                        detail='Not charged at renewal because servers slept'
                    />
                    <Kpi
                        icon={Bot}
                        label='AI assistant usage'
                        value={tokens(report.ai.charged_tokens)}
                        detail={`${formatCount(report.ai.ai_tokens)} model tokens, free allowance included`}
                    />
                </div>
            </div>
        </div>
    );
}

function RevenueBody({
    data,
    error,
    isFetching,
    onRetry,
}: {
    data: AdminRevenue | undefined;
    /** The last request's error, as text. */
    error: string | null;
    isFetching: boolean;
    onRetry: () => void;
}) {
    if (!data) {
        return error === null ? <Spinner size='large' centered /> : <ServerError message={error} onRetry={onRetry} />;
    }

    return (
        <div
            className={cn('transition-opacity duration-150 motion-reduce:transition-none', isFetching && 'opacity-60')}
            aria-busy={isFetching}
        >
            {error !== null && (
                <p role='alert' className='mb-3 text-sm text-destructive'>
                    Could not load that range: {error} Showing the previous one.
                </p>
            )}
            <RevenueReport report={data} />
        </div>
    );
}

export default function RevenueContainer() {
    const today = isoDate(new Date());
    const [preset, setPreset] = useState<RevenuePreset | 'custom'>('30');
    const [from, setFrom] = useState(() => isoDate(new Date(Date.now() - 29 * 86_400_000)));
    const [to, setTo] = useState(today);
    const [applied, setApplied] = useState(() => revenueQuery('30', '', ''));
    const { data, error, isFetching, refetch } = useAdminRevenue(applied);
    const customError = preset === 'custom' ? customRangeError(from, to, today) : null;

    const choose = (value: RevenuePreset | 'custom') => {
        setPreset(value);

        if (value !== 'custom') {
            setApplied(revenueQuery(value, '', ''));
        }
    };

    const applyCustom = (event: FormEvent) => {
        event.preventDefault();

        if (!customError) {
            setApplied(revenueQuery('custom', from, to));
        }
    };

    return (
        <AdminContentBlock
            title='Admin · Revenue'
            heading='Revenue'
            description='What the token shop sold, what went back out, and what subscriptions are worth. Figures are cached for five minutes.'
        >
            <div className='mb-4 flex flex-wrap items-end justify-between gap-3'>
                <div className='flex flex-wrap items-end gap-3'>
                    <div className='flex flex-wrap gap-1' role='group' aria-label='Range'>
                        {[...REVENUE_PRESETS, { value: 'custom' as const, label: 'Custom' }].map((option) => (
                            <Button.Text
                                key={option.value}
                                type='button'
                                size='xsmall'
                                color={preset === option.value ? 'primary' : 'grey'}
                                aria-pressed={preset === option.value}
                                onClick={() => choose(option.value)}
                            >
                                {option.label}
                            </Button.Text>
                        ))}
                    </div>
                    {preset === 'custom' && (
                        <form className='flex flex-wrap items-end gap-2' onSubmit={applyCustom} noValidate>
                            <div>
                                <Label htmlFor='revenue-from'>From</Label>
                                <TextInput
                                    id='revenue-from'
                                    type='date'
                                    value={from}
                                    max={today}
                                    onChange={(event) => setFrom(event.currentTarget.value)}
                                    $hasError={!!customError}
                                    aria-describedby={customError ? 'revenue-range-error' : undefined}
                                />
                            </div>
                            <div>
                                <Label htmlFor='revenue-to'>To</Label>
                                <TextInput
                                    id='revenue-to'
                                    type='date'
                                    value={to}
                                    max={today}
                                    onChange={(event) => setTo(event.currentTarget.value)}
                                    $hasError={!!customError}
                                    aria-describedby={customError ? 'revenue-range-error' : undefined}
                                />
                            </div>
                            <Button type='submit' size='small' disabled={!!customError}>
                                Show
                            </Button>
                            {customError && (
                                <p
                                    id='revenue-range-error'
                                    role='alert'
                                    className='basis-full text-xs text-destructive'
                                >
                                    {customError}
                                </p>
                            )}
                        </form>
                    )}
                </div>
                <div className='flex items-center gap-3'>
                    {data && (
                        <span className='text-xs text-muted-foreground'>
                            {formatDay(data.range.from)} – {formatDay(data.range.to)} · {data.range.days} days
                        </span>
                    )}
                    <Button.Text
                        type='button'
                        size='small'
                        disabled={!data}
                        onClick={() => data && downloadRevenueCsv(data)}
                    >
                        <Icon icon={Download} className='mr-1.5 inline size-4' aria-hidden />
                        Export CSV
                    </Button.Text>
                </div>
            </div>
            <RevenueBody
                data={data}
                error={error ? httpErrorToHuman(error) : null}
                isFetching={isFetching}
                onRetry={() => void refetch()}
            />
        </AdminContentBlock>
    );
}
