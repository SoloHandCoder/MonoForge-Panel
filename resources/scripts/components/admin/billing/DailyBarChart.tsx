import { useId, useRef, useState, type KeyboardEvent } from 'react';
import ChartCard from '@/components/elements/ChartCard';
import { useElementWidth } from '@/components/server/console/chart';
import { formatCompact, formatDay } from '@/components/admin/billing/revenueFormat';

export interface DailyBarPoint {
    /** The day, as YYYY-MM-DD. */
    date: string;
    value: number;
}

interface DailyBarChartProps {
    title: string;
    /** What a value is, for the tooltip and table, such as "Tokens sold". */
    valueLabel: string;
    data: readonly DailyBarPoint[];
    format: (value: number) => string;
    /** Any CSS colour; a theme chart token by default. */
    color?: string;
    height?: number;
}

const AXIS_WIDTH = 44;
const AXIS_HEIGHT = 20;
const TOP = 8;
// The surface gap between neighbouring bars, and the rounding of each bar's data end.
const GAP = 2;
const RADIUS = 4;

/** A round maximum just above the data, so the top gridline reads as a plain number. */
export function niceMax(max: number): number {
    if (max <= 0) {
        return 1;
    }

    const magnitude = 10 ** Math.floor(Math.log10(max));
    const step = [1, 2, 2.5, 5, 10].find((candidate) => candidate * magnitude >= max) ?? 10;

    return step * magnitude;
}

/** A bar with a rounded top and a square foot on the baseline. */
function barPath(x: number, y: number, width: number, height: number): string {
    const r = Math.min(RADIUS, width / 2, height);

    return [
        `M${x} ${y + height}`,
        `V${y + r}`,
        `Q${x} ${y} ${x + r} ${y}`,
        `H${x + width - r}`,
        `Q${x + width} ${y} ${x + width} ${y + r}`,
        `V${y + height}`,
        'Z',
    ].join(' ');
}

/** The bar a key moves to from the active one, or undefined for a key that does not move. */
function keyTarget(key: string, active: number | null, count: number): number | undefined {
    switch (key) {
        case 'ArrowLeft':
            return (active ?? count) - 1;
        case 'ArrowRight':
            return (active ?? -1) + 1;
        case 'Home':
            return 0;
        case 'End':
            return count - 1;
        default:
            return undefined;
    }
}

interface DayLabel {
    anchor: 'start' | 'middle' | 'end';
    x: number;
}

/** Where a day's label sits: the first and last hug the plot's edges, others centre on their bar. */
function dayLabelPosition(index: number, count: number, column: number, width: number): DayLabel {
    if (index === 0) {
        return { anchor: 'start', x: AXIS_WIDTH };
    }

    if (index === count - 1) {
        return { anchor: 'end', x: width };
    }

    return { anchor: 'middle', x: AXIS_WIDTH + (index + 0.5) * column };
}

/**
 * One value per day as columns: hover, focus and the arrow keys show a day's value, and the same
 * numbers are available as a table.
 */
export default function DailyBarChart({
    title,
    valueLabel,
    data,
    format,
    color = 'var(--chart-1)',
    height = 168,
}: DailyBarChartProps) {
    const frame = useRef<HTMLDivElement>(null);
    const width = useElementWidth(frame);
    const [active, setActive] = useState<number | null>(null);
    const uid = useId();

    const total = data.reduce((sum, point) => sum + point.value, 0);
    const max = niceMax(Math.max(0, ...data.map((point) => point.value)));
    const plotWidth = Math.max(0, width - AXIS_WIDTH);
    const plotHeight = height - AXIS_HEIGHT - TOP;
    const column = data.length > 0 ? plotWidth / data.length : 0;
    const barWidth = Math.max(1, column - GAP);
    const y = (value: number) => TOP + plotHeight - (value / max) * plotHeight;
    const point = active === null ? undefined : data[active];
    const summary = `${title}: ${format(total)} in total over ${data.length} days, at most ${format(Math.max(0, ...data.map((entry) => entry.value)))} in a day.`;

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (data.length === 0) {
            return;
        }

        const next = keyTarget(event.key, active, data.length);

        if (next !== undefined) {
            event.preventDefault();
            setActive(Math.min(data.length - 1, Math.max(0, next)));
        } else if (event.key === 'Escape') {
            setActive(null);
        }
    };

    const ticks = [0, max / 2, max];
    const labelled = data.length > 0 ? [...new Set([0, Math.floor((data.length - 1) / 2), data.length - 1])] : [];

    return (
        <ChartCard title={title} legend={<span className='text-muted-foreground tabular-nums'>{format(total)}</span>}>
            <div
                ref={frame}
                className='relative mr-2 rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none'
                style={{ height }}
                role='group'
                aria-label={summary}
                aria-describedby={`${uid}-hint`}
                tabIndex={0}
                onKeyDown={onKeyDown}
                onBlur={() => setActive(null)}
                onMouseLeave={() => setActive(null)}
            >
                <span id={`${uid}-hint`} className='sr-only'>
                    Use the left and right arrow keys to read each day.
                </span>
                {width > 0 && (
                    <svg width={width} height={height} aria-hidden='true' className='block'>
                        {ticks.map((tick) => (
                            <g key={tick}>
                                <line
                                    x1={AXIS_WIDTH}
                                    x2={width}
                                    y1={y(tick)}
                                    y2={y(tick)}
                                    stroke='var(--border)'
                                    strokeWidth={1}
                                />
                                <text
                                    x={AXIS_WIDTH - 6}
                                    y={y(tick)}
                                    textAnchor='end'
                                    dominantBaseline='middle'
                                    fill='var(--muted-foreground)'
                                    fontSize={11}
                                >
                                    {formatCompact(tick)}
                                </text>
                            </g>
                        ))}
                        {data.map((entry, index) => {
                            const x = AXIS_WIDTH + index * column + GAP / 2;
                            const barHeight = entry.value > 0 ? Math.max(1, (entry.value / max) * plotHeight) : 0;

                            return (
                                <g key={entry.date}>
                                    {barHeight > 0 && (
                                        <path
                                            d={barPath(x, TOP + plotHeight - barHeight, barWidth, barHeight)}
                                            fill={color}
                                            opacity={active === null || active === index ? 1 : 0.45}
                                        />
                                    )}
                                    {/* The hit target is the whole column, far larger than a short bar. */}
                                    <rect
                                        x={AXIS_WIDTH + index * column}
                                        y={0}
                                        width={column}
                                        height={TOP + plotHeight}
                                        fill='transparent'
                                        onMouseEnter={() => setActive(index)}
                                    />
                                </g>
                            );
                        })}
                        {labelled.map((index) => {
                            const entry = data[index];
                            const { anchor, x } = dayLabelPosition(index, data.length, column, width);

                            return entry ? (
                                <text
                                    key={entry.date}
                                    x={x}
                                    y={height - 4}
                                    textAnchor={anchor}
                                    fill='var(--muted-foreground)'
                                    fontSize={11}
                                >
                                    {formatDay(entry.date)}
                                </text>
                            ) : null;
                        })}
                    </svg>
                )}
                {point && active !== null && (
                    <div
                        className='pointer-events-none absolute top-0 z-10 rounded-md border border-border bg-popover px-2.5 py-1.5 text-xs shadow-md'
                        style={{
                            left: Math.min(
                                Math.max(0, AXIS_WIDTH + (active + 0.5) * column - 70),
                                Math.max(0, width - 140)
                            ),
                        }}
                    >
                        <p className='text-muted-foreground'>{formatDay(point.date)}</p>
                        <p className='font-medium text-foreground tabular-nums'>
                            {valueLabel}: {format(point.value)}
                        </p>
                    </div>
                )}
                <p className='sr-only' aria-live='polite'>
                    {point ? `${formatDay(point.date)}: ${format(point.value)}` : ''}
                </p>
            </div>
            <details className='mx-2 mt-1 mb-3 text-sm'>
                <summary className='cursor-pointer text-xs text-muted-foreground hover:text-foreground'>
                    Show as a table
                </summary>
                <div className='mt-2 max-h-64 overflow-y-auto'>
                    <table className='w-full text-sm'>
                        <thead>
                            <tr className='border-b border-border text-left text-xs text-muted-foreground'>
                                <th scope='col' className='py-1.5 font-medium'>
                                    Day
                                </th>
                                <th scope='col' className='py-1.5 text-right font-medium'>
                                    {valueLabel}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.map((entry) => (
                                <tr key={entry.date} className='border-b border-border last:border-0'>
                                    <td className='py-1.5 text-foreground'>{entry.date}</td>
                                    <td className='py-1.5 text-right text-foreground tabular-nums'>
                                        {format(entry.value)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </details>
        </ChartCard>
    );
}
