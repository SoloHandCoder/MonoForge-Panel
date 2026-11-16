import { useMemo, useState } from 'react';
import { Link, useLocation, useParams } from '@tanstack/react-router';
import { ArrowLeft, Coins, Gift } from 'lucide-react';
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
import TrialSummary from '@/components/store/TrialSummary';
import {
    firstLocation,
    planLocations,
    soldOutInCatalog,
    useCheckoutPrice,
    usePurchaseFlow,
    validateName,
    validateSubdomain,
    withAddons,
} from '@/components/store/useCheckout';
import {
    tryFreeLabel,
    useCheckoutTrial,
    useTrialFlow,
    type CheckoutMode,
    type PlanTrial,
} from '@/components/store/useTrial';
import { cn } from '@/lib/cn';
import { useCaptcha } from '@/views/auth/captcha';

/** Buying with tokens or the free trial, for a plan that has one. */
function ModeSwitch({
    trial,
    mode,
    onChange,
}: {
    trial: PlanTrial;
    mode: CheckoutMode;
    onChange: (mode: CheckoutMode) => void;
}) {
    const options: { value: CheckoutMode; label: string; icon: typeof Coins; disabled: boolean }[] = [
        { value: 'buy', label: 'Buy with tokens', icon: Coins, disabled: false },
        { value: 'trial', label: tryFreeLabel(trial.hours), icon: Gift, disabled: !trial.eligible },
    ];

    return (
        <div className='mb-6'>
            <div
                role='radiogroup'
                aria-label='How to get this server'
                className='inline-flex w-full flex-wrap gap-1 rounded-xl border border-border bg-card p-1 sm:w-auto'
            >
                {options.map((option) => (
                    <button
                        key={option.value}
                        type='button'
                        role='radio'
                        aria-checked={mode === option.value}
                        disabled={option.disabled}
                        onClick={() => onChange(option.value)}
                        className={cn(
                            'inline-flex flex-1 items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-50 sm:flex-none',
                            mode === option.value
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-(--active-item) hover:text-foreground'
                        )}
                    >
                        <Icon icon={option.icon} className='size-4' aria-hidden />
                        {option.label}
                    </button>
                ))}
            </div>
            {!trial.eligible && trial.reason && <p className='mt-2 text-sm text-muted-foreground'>{trial.reason}</p>}
        </div>
    );
}

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

type ServerName = ReturnType<typeof useServerName>;
type CheckoutPicks = ReturnType<typeof useCheckoutChoices>;
type SubdomainState = { enabled: boolean; value: string; error: string | null; onChange: (value: string) => void };

/** Game, location, period, name and add-ons; a free trial has no period, add-ons or subdomain. */
function ChoiceSteps({
    catalog,
    plan,
    locations,
    picks,
    name,
    subdomain,
    trialMode,
}: {
    catalog: StoreCatalog;
    plan: StorePlan;
    locations: StoreLocation[];
    picks: CheckoutPicks;
    name: ServerName;
    subdomain: SubdomainState;
    trialMode: boolean;
}) {
    const { choices, period } = picks;
    const steps = locations.length > 0 ? 2 : 1;

    return (
        <div className='grid grid-cols-1 gap-4'>
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
            {!trialMode && (
                <PeriodStep
                    number={steps + 1}
                    periods={catalog.periods}
                    periodId={choices.periodId}
                    onChange={picks.setPeriodId}
                />
            )}
            <NameStep
                number={trialMode ? steps + 1 : steps + 2}
                name={name.value}
                nameError={name.error}
                onName={name.set}
                subdomain={{ ...subdomain, base: catalog.subdomainBase }}
            />
            {!trialMode && catalog.addons.length > 0 && period && (
                <AddonsStep
                    number={steps + 3}
                    addons={catalog.addons}
                    units={choices.units}
                    periodDays={period.days}
                    onChange={picks.setUnit}
                />
            )}
        </div>
    );
}

/** The order card, the confirmation and the purchase of a server bought with tokens. */
function BuyCheckout({
    catalog,
    plan,
    locations,
    picks,
    name,
    subdomain,
}: {
    catalog: StoreCatalog;
    plan: StorePlan;
    locations: StoreLocation[];
    picks: CheckoutPicks;
    name: ServerName;
    subdomain: SubdomainState;
}) {
    const { egg, location, period, choices } = picks;
    const [coupon, setCoupon] = useState<string | null>(null);
    const [confirming, setConfirming] = useState(false);
    const price = useCheckoutPrice(catalog, plan, { ...choices, coupon }, locations);
    const flow = usePurchaseFlow();
    const canBuy = isBuyable(price, name.valid && subdomain.error === null && !flow.working, coupon);

    const confirm = () => {
        setConfirming(false);

        if (price.selection) {
            void flow.buy(price.selection, {
                name: name.value.trim(),
                subdomain: subdomain.enabled && subdomain.value !== '' ? subdomain.value : null,
                // With a coupon, the confirmed amount is the discounted first period.
                expectedTotal: price.firstPeriod ?? price.total,
                coupon,
            });
        }
    };

    return (
        <>
            {(flow.working || flow.result) && <PurchaseOverlay result={flow.result} />}
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

/** The order card and the start of a free trial (behind the captcha, when the panel uses one). */
function TrialCheckout({
    trial,
    plan,
    locations,
    choices,
    name,
}: {
    trial: PlanTrial;
    plan: StorePlan;
    locations: StoreLocation[];
    choices: CheckoutPicks['choices'];
    name: ServerName;
}) {
    const flow = useTrialFlow();
    const captcha = useCaptcha('trial');
    const soldOut = soldOutInCatalog(plan, locations, choices);
    const { eggId } = choices;
    const canStart = name.valid && eggId !== null && !soldOut && !flow.working;

    const start = () => {
        name.touch();

        if (canStart) {
            void flow.begin(
                {
                    planId: plan.id,
                    eggId,
                    locationId: choices.locationId,
                    nodeId: choices.nodeId,
                    name: name.value.trim(),
                },
                captcha.token
            );
        }
    };

    return (
        <>
            {(flow.working || flow.result) && <PurchaseOverlay result={flow.result} trialHours={trial.hours} />}
            <TrialSummary
                hours={trial.hours}
                resources={withAddons(plan, [], {})}
                soldOut={soldOut}
                error={flow.error}
                canStart={canStart}
                working={flow.working}
                Captcha={captcha.Captcha}
                onStart={start}
            />
        </>
    );
}

function Checkout({ catalog, plan }: { catalog: StoreCatalog; plan: StorePlan }) {
    const locations = useMemo(() => planLocations(plan, catalog.locations), [plan, catalog.locations]);
    const picks = useCheckoutChoices(catalog, plan, locations);
    const [subdomain, setSubdomain] = useState('');
    // "Try free" on a plan card opens the checkout at #trial.
    const { hash } = useLocation();
    const { trial, active, setMode } = useCheckoutTrial(catalog, plan, hash);
    const name = useServerName(picks.egg ? `${picks.egg.name} server` : '');
    const subdomainsEnabled = catalog.subdomainsEnabled && active === null;
    const subdomainState: SubdomainState = {
        enabled: subdomainsEnabled,
        value: subdomain,
        error: subdomainsEnabled ? validateSubdomain(subdomain) : null,
        onChange: setSubdomain,
    };

    return (
        <>
            <CheckoutHeader plan={plan} />
            {trial && <ModeSwitch trial={trial} mode={active ? 'trial' : 'buy'} onChange={setMode} />}
            <div className='grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start'>
                <ChoiceSteps
                    catalog={catalog}
                    plan={plan}
                    locations={locations}
                    picks={picks}
                    name={name}
                    subdomain={subdomainState}
                    trialMode={active !== null}
                />
                {active ? (
                    <TrialCheckout
                        trial={active}
                        plan={plan}
                        locations={locations}
                        choices={picks.choices}
                        name={name}
                    />
                ) : (
                    <BuyCheckout
                        catalog={catalog}
                        plan={plan}
                        locations={locations}
                        picks={picks}
                        name={name}
                        subdomain={subdomainState}
                    />
                )}
            </div>
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
