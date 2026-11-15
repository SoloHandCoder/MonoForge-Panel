/* oxlint-disable rules/design-tokens -- the page backdrop and card radius are the brand's fixed sign-in look */
import { CircleAlert, CircleCheck, CircleHelp, TriangleAlert, type LucideIcon } from 'lucide-react';
import { type CSSProperties, useEffect } from 'react';
import { isAxiosError } from 'axios';
import {
    type ComponentState,
    type OverallState,
    type PublicStatus,
    type StatusComponent,
    type StatusDay,
    type StatusIncident,
    usePublicStatus,
} from '@/api/status/queries';
import Icon from '@/components/elements/Icon';
import Spinner from '@/components/elements/Spinner';
import { cn } from '@/lib/cn';
import { formatDate, fromNow } from '@/lib/dates';
import { Main } from '@/views/a11y';
import { setPageTitle } from '@/views/pageTitle';

const overallStyles = {
    operational: {
        label: 'All systems operational',
        icon: CircleCheck,
        box: 'border-success/40 bg-success/10',
        accent: 'text-success',
        dot: 'bg-success',
    },
    partial_outage: {
        label: 'Partial outage',
        icon: TriangleAlert,
        box: 'border-warning/40 bg-warning/10',
        accent: 'text-warning',
        dot: 'bg-warning',
    },
    major_outage: {
        label: 'Major outage',
        icon: CircleAlert,
        box: 'border-destructive/40 bg-destructive/10',
        accent: 'text-destructive',
        dot: 'bg-destructive',
    },
} satisfies Record<OverallState, { label: string; icon: LucideIcon; box: string; accent: string; dot: string }>;

const componentStyles = {
    operational: { label: 'Operational', icon: CircleCheck, accent: 'text-success' },
    outage: { label: 'Outage', icon: CircleAlert, accent: 'text-destructive' },
    unknown: { label: 'No recent data', icon: CircleHelp, accent: 'text-muted-foreground' },
} satisfies Record<ComponentState, { label: string; icon: LucideIcon; accent: string }>;

const incidentStyles = {
    danger: {
        label: 'Outage',
        icon: CircleAlert,
        box: 'border-destructive/40 bg-destructive/10',
        accent: 'text-destructive',
    },
    warning: { label: 'Degraded', icon: TriangleAlert, box: 'border-warning/40 bg-warning/10', accent: 'text-warning' },
} satisfies Record<StatusIncident['level'], { label: string; icon: LucideIcon; box: string; accent: string }>;

/** On narrow screens only the last 30 days fit; the bars before them are hidden. */
const MOBILE_DAYS = 30;

const formatUptime = (uptime: number | null): string =>
    uptime === null ? 'No data' : `${uptime.toFixed(uptime === 100 ? 0 : 2)}% uptime`;

/** The bar colour for a day: green when (almost) always up, amber for some downtime, red for a lot. */
export const dayTone = (uptime: number | null): string => {
    if (uptime === null) {
        return 'bg-muted';
    }

    if (uptime >= 99.5) {
        return 'bg-success';
    }

    return uptime >= 95 ? 'bg-warning' : 'bg-destructive';
};

const dayTitle = (day: StatusDay): string =>
    [
        formatDate(day.date, 'D MMM YYYY'),
        formatUptime(day.uptime),
        day.response_ms === null ? null : `${day.response_ms} ms`,
    ]
        .filter(Boolean)
        .join(' · ');

function UptimeBars({ days, uptime }: { days: StatusDay[]; uptime: number | null }) {
    const hiddenOnMobile = Math.max(0, days.length - MOBILE_DAYS);

    return (
        <div>
            <div
                role='img'
                aria-label={`${days.length}-day uptime: ${formatUptime(uptime)}`}
                className='flex h-8 items-stretch gap-[2px]'
            >
                {days.map((day, index) => (
                    <span
                        key={day.date}
                        title={dayTitle(day)}
                        aria-hidden
                        className={cn(
                            'min-w-0 flex-1 rounded-[2px] transition-opacity duration-150 hover:opacity-70',
                            dayTone(day.uptime),
                            index < hiddenOnMobile && 'max-sm:hidden'
                        )}
                    />
                ))}
            </div>
            <div className='mt-1.5 flex justify-between text-xs text-muted-foreground'>
                <span className='max-sm:hidden'>{days.length} days ago</span>
                <span className='sm:hidden'>{Math.min(days.length, MOBILE_DAYS)} days ago</span>
                <span>{formatUptime(uptime)}</span>
                <span>Today</span>
            </div>
        </div>
    );
}

/** The panel's average response time per day as a small line, newest on the right. */
function ResponseSparkline({ days }: { days: StatusDay[] }) {
    const points = days
        .map((day, index) => ({ index, value: day.response_ms }))
        .filter((point): point is { index: number; value: number } => point.value !== null);

    if (points.length < 2) {
        return null;
    }

    const max = Math.max(...points.map((point) => point.value), 1);
    const width = 120;
    const height = 28;
    const step = width / Math.max(1, days.length - 1);
    const path = points
        .map(
            (point) =>
                `${(point.index * step).toFixed(1)},${(height - (point.value / max) * (height - 4) - 2).toFixed(1)}`
        )
        .join(' ');

    return (
        <svg
            viewBox={`0 0 ${width} ${height}`}
            aria-hidden
            className='h-7 w-28 text-primary'
            preserveAspectRatio='none'
        >
            <polyline points={path} fill='none' stroke='currentColor' strokeWidth='1.5' strokeLinejoin='round' />
        </svg>
    );
}

function ComponentRow({
    component,
    responseMs,
    showResponse = false,
    delay,
}: {
    component: StatusComponent;
    responseMs?: number | null;
    showResponse?: boolean;
    delay: number;
}) {
    const style = componentStyles[component.status];

    return (
        <li
            className='animate-rise py-5 first:pt-0 last:pb-0'
            style={{ '--rise-delay': `${delay}ms` } as CSSProperties}
        >
            <div className='mb-3 flex flex-wrap items-center justify-between gap-x-4 gap-y-1'>
                <h3 className='text-sm font-semibold text-foreground'>{component.name}</h3>
                <div className='flex items-center gap-4'>
                    {showResponse && (
                        <div className='flex items-center gap-2 text-xs text-muted-foreground'>
                            <ResponseSparkline days={component.days} />
                            <span>
                                <span className='sr-only'>Response time: </span>
                                {responseMs === null || responseMs === undefined ? '—' : `${responseMs} ms`}
                            </span>
                        </div>
                    )}
                    <span className={cn('inline-flex items-center gap-1.5 text-sm font-medium', style.accent)}>
                        <Icon icon={style.icon} aria-hidden className='size-4' />
                        {style.label}
                    </span>
                </div>
            </div>
            <UptimeBars days={component.days} uptime={component.uptime} />
        </li>
    );
}

function Incidents({ incidents }: { incidents: StatusIncident[] }) {
    if (incidents.length === 0) {
        return null;
    }

    return (
        <section aria-labelledby='status-incidents' className='flex flex-col gap-3'>
            <h2 id='status-incidents' className='text-sm font-semibold tracking-wide text-muted-foreground uppercase'>
                Current incidents
            </h2>
            {incidents.map((incident) => {
                const style = incidentStyles[incident.level];

                return (
                    <article
                        key={incident.id}
                        data-level={incident.level}
                        className={cn('animate-fade-in rounded-[14px] border px-5 py-4', style.box)}
                    >
                        <div className='flex items-start gap-3'>
                            <Icon
                                icon={style.icon}
                                aria-hidden
                                className={cn('mt-0.5 size-5 shrink-0', style.accent)}
                            />
                            <div className='min-w-0 flex-1'>
                                <h3 className='font-semibold text-foreground'>
                                    <span className='sr-only'>{style.label}: </span>
                                    {incident.title}
                                </h3>
                                <p className='mt-1 whitespace-pre-line break-words text-sm text-muted-foreground'>
                                    {incident.body}
                                </p>
                                {incident.started_at && (
                                    <p className='mt-2 text-xs text-muted-foreground'>
                                        Since {formatDate(incident.started_at, 'D MMM YYYY, HH:mm')}
                                    </p>
                                )}
                            </div>
                        </div>
                    </article>
                );
            })}
        </section>
    );
}

function Overall({ status }: { status: PublicStatus }) {
    const style = overallStyles[status.state];

    return (
        <section
            aria-live='polite'
            className={cn('animate-rise flex items-center gap-4 rounded-[18px] border px-5 py-5 sm:px-6', style.box)}
        >
            <span className='relative flex size-3 shrink-0'>
                <span
                    className={cn('absolute inline-flex size-full animate-ping rounded-full opacity-60', style.dot)}
                />
                <span className={cn('relative inline-flex size-3 rounded-full', style.dot)} />
            </span>
            <div className='min-w-0 flex-1'>
                <h1 className='text-lg font-semibold text-foreground sm:text-xl'>{style.label}</h1>
                <p className='text-xs text-muted-foreground'>
                    {status.checked_at ? `Checked ${fromNow(status.checked_at)}` : 'Waiting for the first check'}
                </p>
            </div>
            <Icon icon={style.icon} aria-hidden className={cn('size-7 shrink-0 max-sm:hidden', style.accent)} />
        </section>
    );
}

export function StatusView({ status }: { status: PublicStatus }) {
    return (
        <div className='flex flex-col gap-6'>
            <Overall status={status} />
            <Incidents incidents={status.incidents} />
            <section
                aria-labelledby='status-components'
                className='rounded-[18px] border border-border bg-card p-5 text-card-foreground shadow-lg sm:p-6'
            >
                <h2
                    id='status-components'
                    className='mb-5 text-sm font-semibold tracking-wide text-muted-foreground uppercase'
                >
                    Services
                </h2>
                <ul className='divide-y divide-border'>
                    <ComponentRow
                        component={status.panel}
                        responseMs={status.panel.response_ms}
                        showResponse
                        delay={60}
                    />
                    {status.nodes.map((node, index) => (
                        <ComponentRow key={node.name} component={node} delay={120 + index * 60} />
                    ))}
                </ul>
            </section>
        </div>
    );
}

function StatusBody() {
    const { data, error, isPending, refetch } = usePublicStatus();

    if (isPending) {
        return <Spinner size='large' centered />;
    }

    if (error && !data) {
        const off = isAxiosError(error) && error.response?.status === 404;

        return (
            <div className='rounded-[18px] border border-border bg-card p-6 text-center text-sm text-muted-foreground'>
                {off ? (
                    <p>The status page is not available.</p>
                ) : (
                    <>
                        <p>The status could not be loaded.</p>
                        <button
                            type='button'
                            onClick={() => refetch()}
                            className='mt-3 font-medium text-accent underline underline-offset-2'
                        >
                            Try again
                        </button>
                    </>
                )}
            </div>
        );
    }

    return <StatusView status={data} />;
}

/**
 * The public /status page in the violet sign-in look: the overall state, current incidents, and
 * the panel and every shown node with 90 days of uptime. Nodes are named by display name only.
 */
export default function StatusPage() {
    useEffect(() => {
        setPageTitle('Status | MonoForge');
    }, []);

    return (
        <div className='relative isolate min-h-screen bg-[radial-gradient(120%_120%_at_80%_0%,#241a44,#0e0d15_60%)] px-4 py-8 sm:py-12'>
            <div className='mx-auto flex w-full max-w-3xl flex-col gap-6'>
                <header className='flex flex-wrap items-center justify-between gap-4'>
                    <a
                        href='/'
                        className='flex items-center gap-2.5 text-lg font-semibold tracking-wide text-foreground no-underline'
                    >
                        <img src='/favicons/favicon.svg' alt='' className='size-8' />
                        MonoForge
                    </a>
                    <span className='rounded-full border border-border bg-card/70 px-3 py-1.5 text-sm text-muted-foreground'>
                        System status
                    </span>
                </header>
                <Main>
                    <StatusBody />
                </Main>
                <p className='text-center text-xs text-muted-foreground'>
                    &copy; <span suppressHydrationWarning>{new Date().getFullYear()}</span> MonoForge
                    <span aria-hidden> · </span>
                    <a href='/' className='no-underline hover:text-foreground'>
                        Back to the panel
                    </a>
                </p>
            </div>
        </div>
    );
}
