import type { CSSProperties } from 'react';
import { Link } from '@tanstack/react-router';
import {
    Archive,
    ArrowRight,
    Cpu,
    Database,
    Gift,
    HardDrive,
    MemoryStick,
    Network,
    type LucideIcon,
} from 'lucide-react';
import type { StoreLocation, StorePlan } from '@/api/store/types';
import Badge from '@/components/elements/Badge';
import Icon from '@/components/elements/Icon';
import { cpu, fromPerMonth, megabytes } from '@/components/store/storeFormat';
import { tryFreeLabel, type PlanTrial } from '@/components/store/useTrial';
import { cn } from '@/lib/cn';

/** The dashboard's server card language: a soft lift and a violet glow on hover or focus. */
export const storeCardClass =
    'group relative flex min-w-0 animate-rise flex-col rounded-2xl border border-border bg-card p-5 transition-[translate,transform,box-shadow,border-color] duration-200 hover:-translate-y-1 hover:border-primary/50 hover:shadow-xl hover:shadow-primary/20 focus-within:border-primary/50';

function Spec({ icon, children }: { icon: LucideIcon; children: string }) {
    return (
        <li className='flex items-center gap-2.5 text-sm text-foreground'>
            <span className='grid size-7 shrink-0 place-items-center rounded-lg bg-(--active-item) text-(--accent-soft)'>
                <Icon icon={icon} className='size-3.5' aria-hidden />
            </span>
            {children}
        </li>
    );
}

export function EggAvatars({ plan }: { plan: StorePlan }) {
    const shown = plan.eggs.slice(0, 4);
    const more = plan.eggs.length - shown.length;

    if (shown.length === 0) {
        return null;
    }

    return (
        <div
            className='flex min-w-0 items-center gap-2'
            aria-label={`Games: ${plan.eggs.map((egg) => egg.name).join(', ')}`}
        >
            <div className='flex -space-x-2'>
                {shown.map((egg) => (
                    <span
                        key={egg.id}
                        title={egg.name}
                        className='grid size-7 place-items-center overflow-hidden rounded-full border-2 border-card bg-secondary text-xs font-semibold text-foreground'
                    >
                        {egg.image ? (
                            <img src={egg.image} alt='' className='size-full object-cover' loading='lazy' />
                        ) : (
                            egg.name.slice(0, 2).toUpperCase()
                        )}
                    </span>
                ))}
            </div>
            <span className='truncate text-xs text-muted-foreground'>
                {shown.length === 1 ? shown[0].name : `${shown.length + more} games`}
            </span>
        </div>
    );
}

/** A plan in the store grid: its lowest monthly price, its resources and its games. */
export default function PlanCard({
    plan,
    locations,
    index,
    cheapest,
    discount,
    trial = null,
}: {
    plan: StorePlan;
    locations: StoreLocation[];
    index: number;
    cheapest: boolean;
    discount: number;
    /** The plan's free trial, shown as "Try free" when this player may start it. */
    trial?: PlanTrial | null;
}) {
    const price = fromPerMonth(plan, locations);

    return (
        <article
            className={cn(storeCardClass, cheapest && 'border-primary/50')}
            style={{ '--rise-delay': `${Math.min(index * 0.08, 0.6)}s` } as CSSProperties}
            aria-labelledby={`plan-${plan.id}-name`}
        >
            <div className='flex items-start justify-between gap-3'>
                <h3 id={`plan-${plan.id}-name`} className='font-header text-lg font-semibold text-foreground'>
                    {plan.name}
                </h3>
                {cheapest && (
                    <Badge tone='accent' variant='soft' size='sm'>
                        Lowest price
                    </Badge>
                )}
            </div>
            {plan.description && <p className='mt-1 line-clamp-2 text-sm text-muted-foreground'>{plan.description}</p>}
            <p className='mt-4 flex flex-wrap items-baseline gap-x-1.5'>
                <span className='text-xs text-muted-foreground'>From</span>
                <span className='font-header text-3xl font-bold text-foreground tabular-nums'>
                    {price.toLocaleString('en-US')}
                </span>
                <span className='text-sm text-muted-foreground'>tokens / month</span>
            </p>
            {discount > 0 && (
                <p className='mt-1 text-xs font-medium text-(--status-online-fg)'>
                    Save up to {discount}% with a longer period
                </p>
            )}
            <ul className='mt-5 grid gap-2.5'>
                <Spec icon={MemoryStick}>{`${megabytes(plan.memory)} RAM`}</Spec>
                <Spec icon={HardDrive}>{`${megabytes(plan.disk)} storage`}</Spec>
                <Spec icon={Cpu}>{cpu(plan.cpu)}</Spec>
                <Spec icon={Archive}>{`${plan.backups} ${plan.backups === 1 ? 'backup' : 'backups'}`}</Spec>
                <Spec icon={Database}>{`${plan.databases} ${plan.databases === 1 ? 'database' : 'databases'}`}</Spec>
                {plan.allocations > 0 && (
                    <Spec
                        icon={Network}
                    >{`${plan.allocations} extra ${plan.allocations === 1 ? 'port' : 'ports'}`}</Spec>
                )}
            </ul>
            {trial?.eligible && (
                <Link
                    to='/store/$planId'
                    params={{ planId: String(plan.id) }}
                    hash='trial'
                    className='relative z-10 mt-5 inline-flex items-center justify-center gap-1.5 rounded-lg border border-primary/50 bg-primary/10 px-3.5 py-2 text-sm font-medium text-foreground no-underline transition-colors duration-150 hover:bg-primary/20 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring'
                >
                    <Icon icon={Gift} className='size-4 text-(--accent-soft)' aria-hidden />
                    {tryFreeLabel(trial.hours)}
                    <span className='sr-only'> ({plan.name})</span>
                </Link>
            )}
            <div className='mt-auto flex items-center justify-between gap-3 pt-6'>
                <EggAvatars plan={plan} />
                <Link
                    to='/store/$planId'
                    params={{ planId: String(plan.id) }}
                    className='inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-primary px-3.5 py-2 text-sm font-medium text-primary-foreground no-underline transition-colors duration-150 after:absolute after:inset-0 after:rounded-2xl hover:bg-primary/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring'
                >
                    Choose
                    <Icon
                        icon={ArrowRight}
                        className='size-4 transition-transform duration-200 group-hover:translate-x-0.5'
                        aria-hidden
                    />
                    <span className='sr-only'> {plan.name}</span>
                </Link>
            </div>
        </article>
    );
}
