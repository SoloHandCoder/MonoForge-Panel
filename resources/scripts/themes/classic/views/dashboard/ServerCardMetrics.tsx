/* oxlint-disable rules/design-tokens -- the usage bars use the brand's usage gradient tokens */
import { Cpu, HardDrive, MemoryStick, type LucideIcon } from 'lucide-react';
import { Badge, bytesToString, cn, Spinner } from '@/sdk';
import type { ServerCardState, ServerStatMetric, ServerUnavailableReason, UiTone, ViewProps } from '@/sdk/theme';

const unavailableLabels = {
    suspended: 'Suspended',
    'connection-error': 'Connection Error',
    maintenance: 'Under Maintenance',
    transferring: 'Transferring',
    installing: 'Installing',
    'restoring-backup': 'Restoring Backup',
    unavailable: 'Unavailable',
} as const;

const unavailableTones = {
    suspended: 'danger',
    'connection-error': 'danger',
    maintenance: 'warning',
    transferring: 'neutral',
    installing: 'neutral',
    'restoring-backup': 'neutral',
    unavailable: 'neutral',
} satisfies Record<ServerUnavailableReason, UiTone>;

function UnavailableBadge({ reason }: { reason: ServerUnavailableReason }) {
    return (
        <div>
            <Badge tone={unavailableTones[reason]}>{unavailableLabels[reason]}</Badge>
        </div>
    );
}

interface UsageProps {
    label: string;
    icon: LucideIcon;
    metric: ServerStatMetric;
    value: string;
    limit: string;
}

/** How full the bar is; an unlimited metric has nothing to fill against. */
const fraction = ({ value, limit }: ServerStatMetric): number =>
    limit > 0 ? Math.min(1, Math.max(0, value / limit)) : 0;

function Usage({ label, icon: Icon, metric, value, limit }: UsageProps) {
    // The card shows only the danger alarm; a warning draws like no alarm.
    const danger = metric.alarm === 'danger';

    return (
        <div data-part='usage' className='min-w-0'>
            <div className='flex items-center justify-between gap-2 text-xs'>
                <span className='inline-flex items-center gap-1.5 text-muted-foreground'>
                    <Icon size='1em' className='shrink-0' />
                    {label}
                </span>
                <span className={cn('truncate tabular-nums', danger ? 'text-destructive' : 'text-foreground')}>
                    {value} <span className='text-muted-foreground'>of {limit}</span>
                </span>
            </div>
            <div className='mt-1.5 h-1.5 overflow-hidden rounded-full bg-white/10'>
                <div
                    className='h-full origin-left rounded-full bg-linear-to-r from-[var(--usage-from)] to-[var(--usage-to)] transition-transform duration-500'
                    style={{ transform: `scaleX(${fraction(metric)})` }}
                />
            </div>
        </div>
    );
}

const bytes = (n: number): string => (n === 0 ? 'Unlimited' : bytesToString(n));

function MetricsContent({ state }: { state: ServerCardState }) {
    if (state.kind === 'loading') {
        return <Spinner size='small' />;
    }

    if (state.kind === 'unavailable') {
        return <UnavailableBadge reason={state.reason} />;
    }

    return (
        <>
            <Usage
                label='CPU'
                icon={Cpu}
                metric={state.cpu}
                value={`${state.cpu.value.toFixed(2)} %`}
                limit={state.cpu.limit === 0 ? 'Unlimited' : `${state.cpu.limit} %`}
            />
            <Usage
                label='Memory'
                icon={MemoryStick}
                metric={state.memory}
                value={bytesToString(state.memory.value)}
                limit={bytes(state.memory.limit)}
            />
            <Usage
                label='Disk'
                icon={HardDrive}
                metric={state.disk}
                value={bytesToString(state.disk.value)}
                limit={bytes(state.disk.limit)}
            />
        </>
    );
}

export default function ServerCardMetrics({ model, className }: ViewProps<'dashboard.serverCard.metrics'>) {
    return (
        <div className={cn('grid grid-cols-1 gap-3', className)}>
            <MetricsContent state={model.state} />
        </div>
    );
}
