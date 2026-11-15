import type { ReactNode } from 'react';
import { ArrowDownUp, Cpu, HardDrive, MemoryStick } from 'lucide-react';
import { bytesToString, cn } from '@/sdk';
import type { ServerGraphPoint, ServerNetworkPoint, ViewProps } from '@/sdk/theme';

const WIDTH = 120;
const HEIGHT = 36;

interface SparkSeries {
    readonly values: readonly { readonly t: number; readonly value: number | null }[];
    readonly color: string;
    readonly label: string;
}

/** The points of a series as an SVG path, newest at the right edge, scaled to `max`, with its first and last x. */
function sparkPath(values: SparkSeries['values'], right: number, windowMs: number, max: number) {
    const commands: string[] = [];
    let first = 0;
    let last = 0;

    for (const { t, value } of values) {
        if (value === null) {
            continue;
        }

        const x = Math.max(0, WIDTH - ((right - t) / windowMs) * WIDTH);
        const y = HEIGHT - 2 - (Math.min(value, max) / max) * (HEIGHT - 4);

        if (commands.length === 0) {
            first = x;
        }

        last = x;
        commands.push(`${commands.length === 0 ? 'M' : 'L'}${x.toFixed(1)} ${y.toFixed(1)}`);
    }

    return { line: commands.join(' '), first: first.toFixed(1), last: last.toFixed(1) };
}

/** A tiny live graph of the last minute; the first series is filled beneath its line. */
function Sparkline({ series, windowMs, max }: { series: readonly SparkSeries[]; windowMs: number; max: number }) {
    const right = Math.max(0, ...series.flatMap((entry) => entry.values.map((point) => point.t)));
    const scale =
        max > 0 ? max : Math.max(1, ...series.flatMap((entry) => entry.values.map((point) => point.value ?? 0))) * 1.15;

    return (
        <svg
            viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
            preserveAspectRatio='none'
            aria-hidden='true'
            className='h-10 w-full overflow-visible'
        >
            {series.map((entry, index) => {
                const { line, first, last } = sparkPath(entry.values, right, windowMs, scale);

                if (line === '') {
                    return null;
                }

                return (
                    <g key={entry.label}>
                        {index === 0 && (
                            <path
                                d={`${line} L${last} ${HEIGHT} L${first} ${HEIGHT} Z`}
                                fill={entry.color}
                                opacity={0.14}
                            />
                        )}
                        <path
                            d={line}
                            fill='none'
                            stroke={entry.color}
                            strokeWidth={1.5}
                            strokeLinejoin='round'
                            strokeLinecap='round'
                            vectorEffect='non-scaling-stroke'
                        />
                    </g>
                );
            })}
        </svg>
    );
}

type Alarm = 'warning' | 'danger' | null;

function alarmOf(value: number | null, limit: number): Alarm {
    if (value === null || !limit) {
        return null;
    }

    if (value / limit > 0.9) {
        return 'danger';
    }

    return value / limit > 0.8 ? 'warning' : null;
}

const alarmClass = { warning: 'text-warning', danger: 'text-destructive' };

function GraphCard({
    icon,
    title,
    value,
    limit,
    alarm = null,
    children,
}: {
    icon: ReactNode;
    title: string;
    value: string;
    limit?: string;
    alarm?: Alarm;
    children: ReactNode;
}) {
    return (
        <div className='rounded-xl border border-border bg-card p-3 transition-colors hover:border-primary/40'>
            <div className='flex items-start justify-between gap-2'>
                <h3 className='flex items-center gap-1.5 text-xs font-medium text-muted-foreground'>
                    {icon}
                    {title}
                </h3>
                <p
                    className={cn(
                        'text-right text-sm font-semibold tabular-nums text-foreground',
                        alarm && alarmClass[alarm]
                    )}
                >
                    {value}
                    {limit && <span className='ml-1 text-xs font-normal text-muted-foreground'>/ {limit}</span>}
                </p>
            </div>
            <div className='mt-2'>{children}</div>
        </div>
    );
}

const lastValue = (points: readonly ServerGraphPoint[]) => points.at(-1)?.value ?? null;
const mib = (value: number) => bytesToString(value * 1024 * 1024);
const mibLimit = (limit: number) => (limit ? mib(limit) : '∞');
const cpuLimit = (limit: number) => (limit ? `${limit}%` : '∞');

const PRIMARY = 'var(--chart-1)';
const SECONDARY = 'var(--chart-2)';

export default function Graphs({ model, className }: ViewProps<'server.graphs'>) {
    const { windowMs, live } = model;
    const cpu = lastValue(model.cpu.points);
    const memory = lastValue(model.memory.points);
    const disk = lastValue(model.disk.points);
    const network: ServerNetworkPoint | undefined = model.network.points.at(-1);
    const idle = (value: number | null) => !live || value === null;

    return (
        <div className={cn('grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-1', className)}>
            <GraphCard
                icon={<Cpu aria-hidden='true' className='size-3.5' />}
                title='CPU Load'
                value={idle(cpu) ? 'Offline' : `${(cpu ?? 0).toFixed(1)}%`}
                limit={idle(cpu) ? undefined : cpuLimit(model.cpu.limit)}
                alarm={alarmOf(cpu, model.cpu.limit)}
            >
                <Sparkline
                    series={[{ values: model.cpu.points, color: PRIMARY, label: 'cpu' }]}
                    windowMs={windowMs}
                    max={model.cpu.limit}
                />
            </GraphCard>
            <GraphCard
                icon={<MemoryStick aria-hidden='true' className='size-3.5' />}
                title='Memory'
                value={idle(memory) ? 'Offline' : mib(memory ?? 0)}
                limit={idle(memory) ? undefined : mibLimit(model.memory.limit)}
                alarm={alarmOf(memory, model.memory.limit)}
            >
                <Sparkline
                    series={[{ values: model.memory.points, color: PRIMARY, label: 'memory' }]}
                    windowMs={windowMs}
                    max={model.memory.limit}
                />
            </GraphCard>
            <GraphCard
                icon={<HardDrive aria-hidden='true' className='size-3.5' />}
                title='Disk'
                value={disk === null ? '—' : mib(disk)}
                limit={disk === null ? undefined : mibLimit(model.disk.limit)}
                alarm={alarmOf(disk, model.disk.limit)}
            >
                <Sparkline
                    series={[{ values: model.disk.points, color: PRIMARY, label: 'disk' }]}
                    windowMs={windowMs}
                    max={model.disk.limit}
                />
            </GraphCard>
            <GraphCard
                icon={<ArrowDownUp aria-hidden='true' className='size-3.5' />}
                title='Network'
                value={
                    !live || !network
                        ? 'Offline'
                        : `↓ ${bytesToString(network.rx ?? 0)}/s  ↑ ${bytesToString(network.tx ?? 0)}/s`
                }
            >
                <Sparkline
                    series={[
                        {
                            values: model.network.points.map((point) => ({ t: point.t, value: point.rx })),
                            color: PRIMARY,
                            label: 'inbound',
                        },
                        {
                            values: model.network.points.map((point) => ({ t: point.t, value: point.tx })),
                            color: SECONDARY,
                            label: 'outbound',
                        },
                    ]}
                    windowMs={windowMs}
                    max={0}
                />
                <p className='mt-1 flex gap-3 text-xs text-muted-foreground'>
                    <span className='flex items-center gap-1'>
                        <span aria-hidden='true' className='size-1.5 rounded-full bg-chart-1' />
                        Inbound
                    </span>
                    <span className='flex items-center gap-1'>
                        <span aria-hidden='true' className='size-1.5 rounded-full bg-chart-2' />
                        Outbound
                    </span>
                </p>
            </GraphCard>
        </div>
    );
}
