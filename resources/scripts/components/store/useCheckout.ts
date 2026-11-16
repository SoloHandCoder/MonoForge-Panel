import { useEffect, useMemo, useRef, useState } from 'react';
import { isAxiosError } from 'axios';
import { httpErrorToHuman } from '@/api/http';
import { newIdempotencyKey, selectionBody, usePurchaseServer, useStoreQuote } from '@/api/store/queries';
import type {
    PurchaseResult,
    StoreAddon,
    StoreCatalog,
    StoreLocation,
    StorePlan,
    StoreResources,
    StoreSelection,
} from '@/api/store/types';
import { estimate, selectionMultiplier } from '@/components/store/storeFormat';

/** At least this long on the loading screen, so a fast purchase still reads as one smooth step. */
export const MIN_WORKING_MS = 2200;
const MIN_FAILURE_MS = 900;
const QUOTE_DEBOUNCE_MS = 250;

export const NAME_MAX = 60;

/** A DNS label, as TunnelSettings::SUBDOMAIN_PATTERN checks it on the server. */
const SUBDOMAIN_PATTERN = /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/;

const wait = (ms: number) => new Promise((resolve) => window.setTimeout(resolve, Math.max(0, ms)));

/**
 * Locations with at least one node the plan may use (every location when the plan allows any node).
 * Nodes at their shop limits for this plan, and locations made only of those, are unavailable.
 */
export function planLocations(plan: StorePlan, locations: StoreLocation[]): StoreLocation[] {
    const allowed = plan.nodeIds;
    const full = plan.fullNodeIds;

    return locations
        .map((location) => {
            const nodes = location.nodes
                .filter((node) => !allowed || allowed.includes(node.id))
                .map(({ id, name, multiplier }) => ({ id, name, multiplier, available: !full.includes(id) }));

            return { ...location, nodes, available: nodes.some((node) => node.available) };
        })
        .filter((location) => location.nodes.length > 0);
}

/** The first location that still has room for the plan, else the first one. */
export const firstLocation = (locations: StoreLocation[]): StoreLocation | undefined =>
    locations.find((location) => location.available) ?? locations[0];

export function validateName(name: string): string | null {
    const trimmed = name.trim();

    if (trimmed === '') {
        return 'Give your server a name.';
    }

    if (trimmed.length > NAME_MAX) {
        return `Keep it under ${NAME_MAX} characters.`;
    }

    return /[<>]/.test(trimmed) ? 'Names cannot contain < or >.' : null;
}

export const validateSubdomain = (subdomain: string): string | null =>
    subdomain === '' || SUBDOMAIN_PATTERN.test(subdomain)
        ? null
        : 'Use 1–63 lowercase letters, digits or hyphens, not starting or ending with a hyphen.';

/** The plan's resources with the chosen add-ons on top. */
export function withAddons(plan: StorePlan, addons: StoreAddon[], units: Record<string, number>): StoreResources {
    const resources: StoreResources = {
        memory: plan.memory,
        disk: plan.disk,
        cpu: plan.cpu,
        backups: plan.backups,
        databases: plan.databases,
        allocations: plan.allocations,
    };

    for (const addon of addons) {
        resources[addon.resource] += (units[addon.key] ?? 0) * addon.amount;
    }

    return resources;
}

/** Waits for the selection to settle before asking the server for a quote. */
function useDebounced<T>(value: T, key: string): T {
    const [settled, setSettled] = useState(value);
    const latest = useRef(value);

    latest.current = value;

    useEffect(() => {
        const timer = window.setTimeout(() => setSettled(latest.current), QUOTE_DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
    }, [key]);

    return settled;
}

export interface CheckoutChoices {
    eggId: number | null;
    locationId: number | null;
    nodeId: number | null;
    periodId: number | null;
    units: Record<string, number>;
}

function selectionFor(
    plan: StorePlan,
    choices: CheckoutChoices,
    addons: Record<string, number>,
    hasLocations: boolean
): StoreSelection | null {
    const { eggId, locationId, nodeId, periodId } = choices;

    if (eggId === null || periodId === null || (locationId === null && hasLocations)) {
        return null;
    }

    return { planId: plan.id, periodId, eggId, locationId, nodeId, addons };
}

function estimateFor(catalog: StoreCatalog, plan: StorePlan, choices: CheckoutChoices, addons: Record<string, number>) {
    const period = catalog.periods.find((each) => each.id === choices.periodId);
    const multiplier = selectionMultiplier(catalog, choices.locationId, choices.nodeId);

    return {
        total: period ? estimate(plan, period, multiplier, catalog.addons, addons) : 0,
        days: period?.days ?? 30,
    };
}

/** Whether the catalog already says the plan, or the picked location or node, is sold out. */
export function soldOutInCatalog(
    plan: StorePlan,
    locations: StoreLocation[],
    { locationId, nodeId }: CheckoutChoices
): boolean {
    if (!plan.canProvision) {
        return true;
    }

    const location = locations.find((each) => each.id === locationId);

    if (!location) {
        return false;
    }

    return !location.available || location.nodes.some((node) => node.id === nodeId && !node.available);
}

/** The live price of the current choices: the server's quote once it matches, a local estimate until then. */
export function useCheckoutPrice(
    catalog: StoreCatalog,
    plan: StorePlan,
    choices: CheckoutChoices,
    locations: StoreLocation[]
) {
    const { units } = choices;
    const hasLocations = locations.length > 0;
    const catalogSoldOut = soldOutInCatalog(plan, locations, choices);
    const addons = useMemo(() => Object.fromEntries(Object.entries(units).filter(([, count]) => count > 0)), [units]);
    const selection = selectionFor(plan, choices, addons, hasLocations);
    const key = JSON.stringify(selection);
    const quoted = useDebounced(selection, key);
    const quote = useStoreQuote(quoted);
    const matches = !quote.isPlaceholderData && JSON.stringify(quoted) === key;
    const data = matches ? (quote.data ?? null) : null;
    const local = estimateFor(catalog, plan, choices, addons);
    const balance = quote.data?.balance ?? catalog.balance;
    const total = data ? data.totalPerPeriod : local.total;

    return {
        selection,
        total,
        days: data ? data.periodDays : local.days,
        lines: data?.lines.length ? data.lines : null,
        resources: { ...withAddons(plan, catalog.addons, addons), ...data?.resources },
        updating: data === null && quote.fetchStatus === 'fetching',
        balance,
        short: balance !== null && balance < total,
        soldOut: catalogSoldOut || data?.canProvision === false,
    };
}

export interface CheckoutError {
    message: string;
    short: boolean;
}

/** Sends the purchase behind the loading screen, keeping one idempotency key per attempt. */
export function usePurchaseFlow() {
    const purchase = usePurchaseServer();
    const keyRef = useRef(newIdempotencyKey());
    const [working, setWorking] = useState(false);
    const [result, setResult] = useState<PurchaseResult | null>(null);
    const [error, setError] = useState<CheckoutError | null>(null);

    const buy = async (
        selection: StoreSelection,
        details: { name: string; subdomain: string | null; expectedTotal: number }
    ) => {
        setError(null);
        setWorking(true);
        const started = Date.now();

        try {
            const response = await purchase.mutateAsync({
                body: {
                    ...selectionBody(selection),
                    name: details.name,
                    subdomain: details.subdomain,
                    expected_total: details.expectedTotal,
                    idempotency_key: keyRef.current,
                },
            });

            await wait(MIN_WORKING_MS - (Date.now() - started));
            setResult(response.attributes);
        } catch (cause) {
            await wait(MIN_FAILURE_MS - (Date.now() - started));
            // An answered request is settled, so the next attempt is a new purchase; one lost on
            // the way keeps its key, so retrying it can never buy twice.
            if (isAxiosError(cause) && cause.response) {
                keyRef.current = newIdempotencyKey();
            }

            setWorking(false);
            setError({
                message: httpErrorToHuman(cause),
                short: isAxiosError(cause) && cause.response?.status === 402,
            });
        }
    };

    return { buy, working, result, error };
}
