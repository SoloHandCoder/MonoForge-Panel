import { useMemo, useState } from 'react';
import { Coins, Store as StoreIcon } from 'lucide-react';
import { httpErrorToHuman } from '@/api/http';
import { useStoreCatalog, WALLET_PATH } from '@/api/store/queries';
import type { StoreCatalog } from '@/api/store/types';
import Icon from '@/components/elements/Icon';
import PageContentBlock from '@/components/elements/PageContentBlock';
import { ServerError } from '@/components/elements/ScreenBlock';
import PlanCard from '@/components/store/PlanCard';
import { CapacityNotice } from '@/components/store/StoreNotices';
import { bestDiscount, sortPlans, tokens } from '@/components/store/storeFormat';
import { cn } from '@/lib/cn';

function PlanSkeletons() {
    return (
        <div className='grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3' aria-hidden>
            {[0, 1, 2].map((key) => (
                <div key={key} className='h-96 animate-pulse rounded-2xl border border-border bg-card' />
            ))}
        </div>
    );
}

export function BalanceChip({ balance }: { balance: number | null }) {
    return (
        <a
            href={WALLET_PATH}
            className='inline-flex items-center gap-2 rounded-full border border-border bg-card px-3.5 py-1.5 text-sm text-foreground no-underline transition-colors duration-150 hover:border-primary/50 hover:bg-(--hover-row)'
        >
            <Icon icon={Coins} className='size-4 text-(--accent-soft)' aria-hidden />
            {balance === null ? 'Wallet' : <span className='tabular-nums'>{tokens(balance)}</span>}
            <span className='text-muted-foreground'>· Buy tokens</span>
        </a>
    );
}

function CategoryTabs({
    categories,
    activeId,
    onSelect,
}: {
    categories: StoreCatalog['categories'];
    activeId: number | undefined;
    onSelect: (id: number) => void;
}) {
    return (
        <div role='tablist' aria-label='Categories' className='mb-6 flex flex-wrap gap-2'>
            {categories.map((category) => {
                const selected = category.id === activeId;

                return (
                    <button
                        key={category.id}
                        type='button'
                        role='tab'
                        aria-selected={selected}
                        onClick={() => onSelect(category.id)}
                        className={cn(
                            'rounded-full border px-4 py-1.5 text-sm transition-colors duration-150',
                            selected
                                ? 'border-primary bg-(--active-item) text-(--active-item-foreground)'
                                : 'border-border text-muted-foreground hover:bg-(--hover-row) hover:text-foreground'
                        )}
                    >
                        {category.name}
                    </button>
                );
            })}
        </div>
    );
}

/** The catalog's categories as tabs, each a grid of plans with the cheapest first. */
function StorePlans({ catalog }: { catalog: StoreCatalog }) {
    const [categoryId, setCategoryId] = useState<number | null>(null);
    const categories = useMemo(
        () => catalog.categories.filter((category) => category.plans.length > 0),
        [catalog.categories]
    );
    const active = categories.find((category) => category.id === categoryId) ?? categories[0];
    const plans = useMemo(
        () => (active ? sortPlans(active.plans, catalog.locations) : []),
        [active, catalog.locations]
    );
    const discount = bestDiscount(catalog.periods);

    if (!active) {
        return (
            <div className='rounded-2xl border border-dashed border-border px-6 py-16 text-center'>
                <p className='font-header text-lg font-semibold text-foreground'>No plans for sale yet</p>
                <p className='mt-1 text-sm text-muted-foreground'>Check back soon — new servers are on the way.</p>
            </div>
        );
    }

    return (
        <>
            {categories.length > 1 && (
                <CategoryTabs categories={categories} activeId={active.id} onSelect={setCategoryId} />
            )}
            {active.description && <p className='mb-5 text-sm text-muted-foreground'>{active.description}</p>}
            <div
                key={active.id}
                role={categories.length > 1 ? 'tabpanel' : undefined}
                className='grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3'
            >
                {plans.map((plan, index) => (
                    <PlanCard
                        key={plan.id}
                        plan={plan}
                        locations={catalog.locations}
                        index={index}
                        cheapest={index === 0 && plans.length > 1}
                        discount={discount}
                    />
                ))}
            </div>
        </>
    );
}

function StoreBody() {
    const { data: catalog, error, refetch } = useStoreCatalog();

    if (catalog) {
        return (
            <>
                {!catalog.canProvision && <CapacityNotice className='mb-6' />}
                <StorePlans catalog={catalog} />
            </>
        );
    }

    return error ? <ServerError message={httpErrorToHuman(error)} onRetry={() => refetch()} /> : <PlanSkeletons />;
}

/** The store: plans by category, cheapest first, each opening its checkout. */
export default function StoreContainer() {
    const { data: catalog } = useStoreCatalog();

    return (
        <PageContentBlock title='Store'>
            <header className='relative mb-8 overflow-hidden rounded-3xl border border-border bg-card px-6 py-8 sm:px-8'>
                <div
                    aria-hidden
                    className='pointer-events-none absolute -top-24 -right-16 size-72 rounded-full bg-primary/25 blur-3xl'
                />
                <div className='relative flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between'>
                    <div className='max-w-xl'>
                        <p className='flex items-center gap-2 text-sm font-medium text-(--accent-soft)'>
                            <Icon icon={StoreIcon} className='size-4' aria-hidden />
                            MonoForge Store
                        </p>
                        <h1 className='mt-2 font-header text-3xl font-bold text-foreground sm:text-4xl'>
                            Your next server, ready in minutes
                        </h1>
                        <p className='mt-2 text-muted-foreground'>
                            Pick a plan, choose your game and pay with tokens. Your server renews automatically from
                            your balance, and you can stop any time.
                        </p>
                    </div>
                    <BalanceChip balance={catalog?.balance ?? null} />
                </div>
            </header>
            <StoreBody />
        </PageContentBlock>
    );
}
