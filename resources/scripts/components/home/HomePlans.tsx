import { useId, useState, type CSSProperties, type ReactNode } from 'react';
import type { PublicCategory, PublicPeriod, PublicPlan, PublicStorePlans } from '@/api/public/storePlans';
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

/** The lowest monthly price in a category, for the games. */
export function cheapestMonthly(category: PublicCategory): number | null {
    const monthly = category.plans
        .flatMap((plan) => plan.prices)
        .map((price) => Math.round((price.tokens * 30) / price.days));

    return monthly.length > 0 ? Math.min(...monthly) : null;
}

/** "2 GB memory, 10 GB storage, 1 CPU thread, 2 backups and 1 database." */
export function planIncludes(plan: PublicPlan): string {
    const parts = [
        `${megabytes(plan.memory)} memory`,
        `${megabytes(plan.disk)} storage`,
        cpu(plan.cpu),
        `${plan.backups} ${plan.backups === 1 ? 'backup' : 'backups'}`,
        `${plan.databases} ${plan.databases === 1 ? 'database' : 'databases'}`,
    ];

    return `${parts.slice(0, -1).join(', ')} and ${parts.at(-1)}.`;
}

/** The chosen plan: its price for the period, what it includes and the way to buy it. */
function Stage({
    plan,
    period,
    id,
    action,
}: {
    plan: PublicPlan;
    period: PublicPeriod;
    id: string;
    action: (plan: PublicPlan) => ReactNode;
}) {
    const price = priceFor(plan, period.id);

    return (
        <div id={id} className='home-stage pt-2 pb-8 lg:pt-3 lg:pb-0'>
            {price ? (
                <p className='flex flex-wrap items-baseline gap-x-3'>
                    <span className='home-display tabular-nums'>{integer.format(price.tokens)}</span>
                    <span className='home-lead'>tokens / {periodLabel(price.days)}</span>
                </p>
            ) : (
                <p className='home-lead font-semibold'>Not sold for this period</p>
            )}
            {price && price.days !== 30 && (
                <p className='home-body home-muted mt-1'>
                    About {integer.format((price.tokens * 30) / price.days)} tokens a month.
                </p>
            )}
            {plan.description && <p className='home-body mt-6 max-w-[44ch]'>{plan.description}</p>}
            <p className='home-body mt-3 max-w-[44ch]'>{planIncludes(plan)}</p>
            <p className='home-body home-muted mt-3 max-w-[44ch]'>Runs {plan.games.join(', ')}</p>
            <div className='mt-8'>
                {plan.available ? action(plan) : <p className='home-lead font-semibold'>Sold out for now</p>}
            </div>
        </div>
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
        <div role='radiogroup' aria-label={label} className='flex flex-wrap gap-x-7 gap-y-2'>
            {options.map((option) => (
                <button
                    key={option.value}
                    type='button'
                    role='radio'
                    aria-checked={option.value === value}
                    onClick={() => onChange(option.value)}
                    className='home-choice'
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}

/** A plan's name as the button that opens it; the open one's stage follows it. */
function PlanRow({
    plan,
    open,
    stageId,
    onOpen,
    children,
}: {
    plan: PublicPlan;
    open: boolean;
    stageId: string;
    onOpen: () => void;
    children: ReactNode;
}) {
    return (
        <>
            <h3 className={cn('home-plan-row py-1', plan.name.length > 22 ? 'home-heading' : 'home-display')}>
                <button
                    type='button'
                    aria-expanded={open}
                    aria-controls={open ? stageId : undefined}
                    onClick={onOpen}
                    onFocus={onOpen}
                    className='home-plan-name'
                >
                    {plan.name}
                </button>
            </h3>
            {children}
        </>
    );
}

/**
 * The live plans from the shop as a list of names: the chosen one opens its price and contents
 * beside the list (under its name on a phone). A game switch when there are several, a period
 * switch, and `action` draws the way to buy the chosen plan.
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
    const stageId = useId();
    const [slug, setSlug] = useState<string | null>(null);
    const [periodId, setPeriodId] = useState<number | null>(null);
    const [planId, setPlanId] = useState<number | null>(null);

    if (loading) {
        return <p className='home-lead home-muted'>Fetching today’s prices…</p>;
    }

    if (!data?.enabled || data.categories.length === 0 || data.periods.length === 0) {
        return fallback;
    }

    const category = data.categories.find((entry) => entry.slug === slug) ?? data.categories[0];
    const period = data.periods.find((entry) => entry.id === periodId) ?? data.periods[0];
    const plans = category.plans;
    const chosen = plans.find((plan) => plan.id === planId) ?? plans[Math.floor((plans.length - 1) / 2)] ?? null;

    return (
        <div className='flex flex-col gap-10'>
            <div className='flex flex-wrap items-end justify-between gap-x-10 gap-y-4'>
                {data.categories.length > 1 ? (
                    <Choice
                        label='Game'
                        options={data.categories.map((entry) => ({ value: entry.slug, label: entry.name }))}
                        value={category.slug}
                        onChange={(value) => {
                            setSlug(value);
                            setPlanId(null);
                        }}
                    />
                ) : (
                    <p className='home-lead font-semibold'>{category.name}</p>
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
            {plans.length === 0 ? (
                <p className='home-lead'>No plans in this game yet.</p>
            ) : (
                <div className='home-plans' style={{ '--rows': plans.length } as CSSProperties}>
                    {plans.map((plan) => (
                        <PlanRow
                            key={plan.id}
                            plan={plan}
                            open={plan.id === chosen?.id}
                            stageId={stageId}
                            onOpen={() => setPlanId(plan.id)}
                        >
                            {plan.id === chosen?.id && (
                                <Stage plan={plan} period={period} id={stageId} action={action} />
                            )}
                        </PlanRow>
                    ))}
                </div>
            )}
            {category.description && (
                <p className='home-body home-muted'>
                    {category.name}: {category.description.replace(/\.$/, '')}.
                </p>
            )}
        </div>
    );
}
