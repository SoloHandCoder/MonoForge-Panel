import { useMemo, useState } from 'react';
import { Link, useParams } from '@tanstack/react-router';
import { ArrowLeft } from 'lucide-react';
import { httpErrorToHuman } from '@/api/http';
import { useStoreCatalog } from '@/api/store/queries';
import type { StoreCatalog, StoreLocation, StorePlan } from '@/api/store/types';
import Icon from '@/components/elements/Icon';
import PageContentBlock from '@/components/elements/PageContentBlock';
import { NotFound, ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import { AddonsStep, EggStep, LocationStep, NameStep, PeriodStep } from '@/components/store/CheckoutSteps';
import OrderSummary, { ConfirmPurchaseDialog } from '@/components/store/OrderSummary';
import { EggAvatars } from '@/components/store/PlanCard';
import PurchaseOverlay from '@/components/store/PurchaseOverlay';
import {
    firstLocation,
    planLocations,
    useCheckoutPrice,
    usePurchaseFlow,
    validateName,
    validateSubdomain,
} from '@/components/store/useCheckout';

function useServerName(defaultName: string) {
    const [name, setName] = useState<string | null>(null);
    const [touched, setTouched] = useState(false);
    const value = name ?? defaultName;

    return {
        value,
        error: touched ? validateName(value) : null,
        valid: validateName(value) === null,
        set: (next: string) => {
            setTouched(true);
            setName(next);
        },
        touch: () => setTouched(true),
    };
}

/** Everything the order needs before the buy button wakes up; an applied coupon must be valid. */
function isBuyable(price: ReturnType<typeof useCheckoutPrice>, formValid: boolean, coupon: string | null): boolean {
    const couponOk = coupon === null || price.coupon?.valid === true;

    return formValid && couponOk && price.selection !== null && !price.soldOut && !price.short && !price.updating;
}

/** The player's picks, starting on the first game, location and period. */
function useCheckoutChoices(catalog: StoreCatalog, plan: StorePlan, locations: StoreLocation[]) {
    const [eggId, setEggId] = useState<number | null>(plan.eggs[0]?.id ?? null);
    const [locationId, setLocationId] = useState<number | null>(firstLocation(locations)?.id ?? null);
    const [nodeId, setNodeId] = useState<number | null>(null);
    const [periodId, setPeriodId] = useState<number | null>(catalog.periods[0]?.id ?? null);
    const [units, setUnits] = useState<Record<string, number>>({});

    return {
        choices: { eggId, locationId, nodeId, periodId, units },
        egg: plan.eggs.find((each) => each.id === eggId),
        location: locations.find((each) => each.id === locationId),
        period: catalog.periods.find((each) => each.id === periodId),
        setEggId,
        setLocation: (id: number) => {
            setLocationId(id);
            setNodeId(null);
        },
        setNodeId,
        setPeriodId,
        setUnit: (key: string, count: number) => setUnits((current) => ({ ...current, [key]: count })),
    };
}

function CheckoutHeader({ plan }: { plan: StorePlan }) {
    return (
        <>
            <Link
                to='/store'
                className='mb-5 inline-flex items-center gap-1.5 text-sm text-muted-foreground no-underline transition-colors hover:text-foreground'
            >
                <Icon icon={ArrowLeft} className='size-4' aria-hidden />
                All plans
            </Link>
            <div className='mb-6 flex flex-wrap items-end justify-between gap-4'>
                <div>
                    <h1 className='font-header text-3xl font-bold text-foreground'>{plan.name}</h1>
                    {plan.description && <p className='mt-1 max-w-2xl text-muted-foreground'>{plan.description}</p>}
                </div>
                <EggAvatars plan={plan} />
            </div>
        </>
    );
}

function Checkout({ catalog, plan }: { catalog: StoreCatalog; plan: StorePlan }) {
    const locations = useMemo(() => planLocations(plan, catalog.locations), [plan, catalog.locations]);
    const picks = useCheckoutChoices(catalog, plan, locations);
    const { egg, location, period, choices } = picks;
    const [subdomain, setSubdomain] = useState('');
    const [coupon, setCoupon] = useState<string | null>(null);
    const [confirming, setConfirming] = useState(false);

    const name = useServerName(egg ? `${egg.name} server` : '');
    const subdomainError = catalog.subdomainsEnabled ? validateSubdomain(subdomain) : null;
    const price = useCheckoutPrice(catalog, plan, { ...choices, coupon }, locations);
    const flow = usePurchaseFlow();
    const steps = locations.length > 0 ? 2 : 1;
    const canBuy = isBuyable(price, name.valid && subdomainError === null && !flow.working, coupon);

    const confirm = () => {
        setConfirming(false);

        if (price.selection) {
            void flow.buy(price.selection, {
                name: name.value.trim(),
                subdomain: catalog.subdomainsEnabled && subdomain !== '' ? subdomain : null,
                // With a coupon, the confirmed amount is the discounted first period.
                expectedTotal: price.firstPeriod ?? price.total,
                coupon,
            });
        }
    };

    return (
        <>
            {(flow.working || flow.result) && <PurchaseOverlay result={flow.result} />}
            <CheckoutHeader plan={plan} />
            <div className='grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start'>
                <div className='grid gap-4'>
                    <EggStep eggs={plan.eggs} eggId={choices.eggId} onChange={picks.setEggId} />
                    {locations.length > 0 && (
                        <LocationStep
                            locations={locations}
                            locationId={choices.locationId}
                            nodeId={choices.nodeId}
                            onLocation={picks.setLocation}
                            onNode={picks.setNodeId}
                        />
                    )}
                    <PeriodStep
                        number={steps + 1}
                        periods={catalog.periods}
                        periodId={choices.periodId}
                        onChange={picks.setPeriodId}
                    />
                    <NameStep
                        number={steps + 2}
                        name={name.value}
                        nameError={name.error}
                        onName={name.set}
                        subdomain={{
                            enabled: catalog.subdomainsEnabled,
                            base: catalog.subdomainBase,
                            value: subdomain,
                            error: subdomainError,
                            onChange: setSubdomain,
                        }}
                    />
                    {catalog.addons.length > 0 && period && (
                        <AddonsStep
                            number={steps + 3}
                            addons={catalog.addons}
                            units={choices.units}
                            periodDays={period.days}
                            onChange={picks.setUnit}
                        />
                    )}
                </div>
                <OrderSummary
                    price={price}
                    error={flow.error}
                    canBuy={canBuy}
                    coupon={{
                        applied: coupon,
                        result: price.coupon,
                        onApply: setCoupon,
                        onRemove: () => setCoupon(null),
                    }}
                    onBuy={() => {
                        name.touch();
                        setConfirming(true);
                    }}
                />
            </div>
            <ConfirmPurchaseDialog
                open={confirming}
                title={`${name.value.trim()} · ${plan.name}`}
                subtitle={[egg?.name, location?.name, period?.name].filter(Boolean).join(' · ')}
                price={price}
                onClose={() => setConfirming(false)}
                onConfirm={confirm}
            />
        </>
    );
}

function CheckoutBody({ planId }: { planId: string }) {
    const { data: catalog, error, refetch } = useStoreCatalog();

    if (!catalog) {
        return error ? (
            <ServerError message={httpErrorToHuman(error)} onRetry={() => refetch()} />
        ) : (
            <Spinner size='large' centered />
        );
    }

    const plan = catalog.categories.flatMap((category) => category.plans).find((each) => String(each.id) === planId);

    return plan ? (
        <Checkout key={plan.id} catalog={catalog} plan={plan} />
    ) : (
        <NotFound message='This plan is not for sale any more.' />
    );
}

/** The checkout of one plan: game, location, period, name, add-ons, then the confirmed purchase. */
export default function CheckoutContainer() {
    const { planId } = useParams({ from: '/authenticated/store/$planId' });

    return (
        <PageContentBlock title='Store · Checkout'>
            <CheckoutBody planId={planId} />
        </PageContentBlock>
    );
}
