import { useState, type CSSProperties, type ReactNode } from 'react';
import { Archive, Cpu, Database, HardDrive, MemoryStick, type LucideIcon } from 'lucide-react';
import type { PublicCategory, PublicPeriod, PublicPlan, PublicStorePlans } from '@/api/public/storePlans';
import Icon from '@/components/elements/Icon';
import { storeCardClass } from '@/components/store/PlanCard';
import { cpu, megabytes } from '@/components/store/storeFormat';
import { cn } from '@/lib/cn';

const integer = new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 });

/** "30 days" as "month", "90 days" as "3 months", "365 days" as "year"; other lengths in days. */
export function periodLabel(days: number): string {
    if (days === 30) {
        return 'month';
    }

    if (days === 365) {
        return 'year';
    }

    return days % 30 === 0 ? `${days / 30} months` : `${days} days`;
}

/** The plan's price for the period, if it is sold for that period. */
export const priceFor = (plan: PublicPlan, periodId: number | undefined) =>
    plan.prices.find((price) => price.periodId === periodId);

/** The lowest monthly price in a category, for the game cards. */
export function cheapestMonthly(category: PublicCategory): number | null {
    const monthly = category.plans
        .flatMap((plan) => plan.prices)
        .map((price) => Math.round((price.tokens * 30) / price.days));

    return monthly.length > 0 ? Math.min(...monthly) : null;
}

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

function PlanCard({
    plan,
    period,
    index,
    action,
}: {
    plan: PublicPlan;
    period: PublicPeriod | undefined;
    index: number;
    action: (plan: PublicPlan) => ReactNode;
}) {
    const price = priceFor(plan, period?.id);

    return (
        <article
            className={storeCardClass}
            style={{ '--rise-delay': `${Math.min(index * 0.08, 0.5)}s` } as CSSProperties}
            aria-labelledby={`home-plan-${plan.id}`}
        >
            <h3 id={`home-plan-${plan.id}`} className='font-header text-lg font-semibold text-foreground'>
                {plan.name}
            </h3>
            {plan.description && <p className='mt-1 line-clamp-2 text-sm text-muted-foreground'>{plan.description}</p>}
            {price ? (
                <p className='mt-4 flex flex-wrap items-baseline gap-x-1.5'>
                    <span className='font-header text-3xl font-bold text-foreground tabular-nums'>
                        {integer.format(price.tokens)}
                    </span>
                    <span className='text-sm text-muted-foreground'>tokens / {periodLabel(price.days)}</span>
                </p>
            ) : (
                <p className='mt-4 text-sm text-muted-foreground'>Not sold for this period</p>
            )}
            <ul className='mt-5 grid gap-2.5'>
                <Spec icon={MemoryStick}>{`${megabytes(plan.memory)} RAM`}</Spec>
                <Spec icon={HardDrive}>{`${megabytes(plan.disk)} storage`}</Spec>
                <Spec icon={Cpu}>{cpu(plan.cpu)}</Spec>
                <Spec icon={Archive}>{`${plan.backups} ${plan.backups === 1 ? 'backup' : 'backups'}`}</Spec>
                <Spec icon={Database}>{`${plan.databases} ${plan.databases === 1 ? 'database' : 'databases'}`}</Spec>
            </ul>
            <p className='mt-4 line-clamp-2 text-xs text-muted-foreground'>Runs {plan.games.join(', ')}</p>
            <div className='mt-auto pt-6'>
                {plan.available ? (
                    action(plan)
                ) : (
                    <p className='rounded-lg border border-border px-3.5 py-2 text-center text-sm text-muted-foreground'>
                        Sold out for now
                    </p>
                )}
            </div>
        </article>
    );
}

function Choice({
    label,
    options,
    value,
    onChange,
}: {
    label: string;
    options: { value: string; label: string }[];
    value: string;
    onChange: (value: string) => void;
}) {
    return (
        <div
            role='radiogroup'
            aria-label={label}
            className='inline-flex flex-wrap gap-1 rounded-full border border-border bg-card/70 p-1 backdrop-blur'
        >
            {options.map((option) => (
                <button
                    key={option.value}
                    type='button'
                    role='radio'
                    aria-checked={option.value === value}
                    onClick={() => onChange(option.value)}
                    className={cn(
                        'rounded-full px-3.5 py-1.5 text-sm transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                        option.value === value
                            ? 'bg-primary text-primary-foreground'
                            : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                    )}
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}

function Skeleton() {
    return (
        <div className='grid gap-5 sm:grid-cols-2 lg:grid-cols-3' aria-hidden='true'>
            {[0, 1, 2].map((index) => (
                <div key={index} className='h-96 animate-pulse rounded-2xl border border-border bg-card/60' />
            ))}
        </div>
    );
}

/**
 * The live plans from the shop: a category switch when there are several, a period switch, and a
 * card per plan with its price for that period. `action` draws each card's call to action.
 */
export default function HomePlans({
    data,
    loading,
    action,
    fallback,
}: {
    data: PublicStorePlans | undefined;
    loading: boolean;
    action: (plan: PublicPlan) => ReactNode;
    fallback: ReactNode;
}) {
    const [slug, setSlug] = useState<string | null>(null);
    const [periodId, setPeriodId] = useState<number | null>(null);

    if (loading) {
        return <Skeleton />;
    }

    if (!data?.enabled || data.categories.length === 0 || data.periods.length === 0) {
        return fallback;
    }

    const category = data.categories.find((entry) => entry.slug === slug) ?? data.categories[0];
    const period = data.periods.find((entry) => entry.id === periodId) ?? data.periods[0];

    return (
        <div className='flex flex-col gap-6'>
            <div className='flex flex-wrap items-center justify-between gap-3'>
                {data.categories.length > 1 ? (
                    <Choice
                        label='Game'
                        options={data.categories.map((entry) => ({ value: entry.slug, label: entry.name }))}
                        value={category.slug}
                        onChange={setSlug}
                    />
                ) : (
                    <p className='font-header text-lg font-semibold text-foreground'>{category.name}</p>
                )}
                {data.periods.length > 1 && (
                    <Choice
                        label='Billing period'
                        options={data.periods.map((entry) => ({ value: String(entry.id), label: entry.name }))}
                        value={String(period.id)}
                        onChange={(value) => setPeriodId(Number(value))}
                    />
                )}
            </div>
            {category.description && <p className='-mt-2 text-sm text-muted-foreground'>{category.description}</p>}
            <div className='grid gap-5 sm:grid-cols-2 lg:grid-cols-3'>
                {category.plans.map((plan, index) => (
                    <PlanCard key={plan.id} plan={plan} period={period} index={index} action={action} />
                ))}
            </div>
        </div>
    );
}
