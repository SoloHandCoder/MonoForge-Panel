import type { ClientStorePurchaseAttributes, ClientStoreTrialAttributes } from '@/api/generated';

/*
 * The store as the checkout uses it. `GET /api/client/store/catalog` and `POST /api/client/store/quote`
 * (StoreController / StoreCatalogService) return plain JSON; the `Raw*` shapes below are exactly those
 * payloads, mapped here to camelCase once, at the boundary.
 */

export interface RawStoreEgg {
    id: number;
    name: string;
    description: string | null;
    image: string | null;
    tags?: { name: string; color: string | null }[];
}

export interface RawStoreResources {
    memory: number;
    swap: number;
    disk: number;
    io: number;
    cpu: number;
    databases: number;
    backups: number;
    allocations: number;
}

export interface RawStoreNode {
    id: number;
    name: string;
    multiplier: number | string;
    /** False when the plan no longer fits within the node's own shop limits. */
    can_provision?: boolean;
}

/** A location the plan may run in, with only the plan's allowed nodes, cheapest first. */
export interface RawStoreLocation {
    id: number;
    short: string;
    long: string | null;
    multiplier: number | string;
    /** False when no node of the location has room for the plan within its own shop limits. */
    can_provision?: boolean;
    nodes: RawStoreNode[];
}

export interface RawStorePlan {
    id: number;
    name: string;
    description: string | null;
    /** Tokens per 30 days, before period modifiers and multipliers. */
    price_tokens: number;
    /** The cheapest price per 30 days over the plan's nodes. */
    from_price_tokens: number;
    resources: RawStoreResources;
    /** False when the plan no longer fits within the capacity the store sells. */
    can_provision: boolean;
    eggs: RawStoreEgg[];
    locations: RawStoreLocation[];
}

export interface RawStoreCategory {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    sort: number;
    visible: boolean;
    /** Cheapest first. */
    plans: RawStorePlan[];
}

export interface RawStorePeriod {
    id: number;
    name: string;
    days: number;
    price_modifier_percent: number;
}

export type StoreAddonResource = 'backups' | 'databases' | 'allocations' | 'memory' | 'disk' | 'cpu';

export interface RawStoreAddon {
    key: string;
    name: string;
    description: string | null;
    resource: StoreAddonResource;
    amount: number;
    price_tokens: number;
    max_units: number;
}

/** Free trial servers as the catalog offers them to the signed-in player. */
export interface RawStoreTrialOffer {
    enabled: boolean;
    hours: number;
    plan_ids: number[];
    eligible: boolean;
    /** Why this player cannot start a trial; null when they can. */
    reason: string | null;
}

export interface RawStoreCatalog {
    enabled: boolean;
    subdomains_enabled: boolean;
    subdomain_base?: string | null;
    balance?: number | null;
    categories: RawStoreCategory[];
    periods: RawStorePeriod[];
    addons: RawStoreAddon[];
    trial?: RawStoreTrialOffer;
}

export interface RawStoreQuote {
    base_per_period: number;
    multiplier: number | string;
    addons_per_period: number;
    total_per_period: number;
    period_days: number;
    lines: { label: string; tokens: number }[];
    resources: Partial<Record<StoreAddonResource, number>>;
}

export interface RawStoreQuoteCoupon {
    code: string;
    valid: boolean;
    message: string | null;
    type: 'percent' | 'fixed' | null;
    discount_tokens: number;
    first_period_tokens: number;
}

export interface RawStoreQuoteResponse {
    node_id: number;
    can_provision: boolean;
    balance?: number | null;
    quote: RawStoreQuote;
    coupon?: RawStoreQuoteCoupon | null;
}

export interface StoreEgg {
    id: number;
    name: string;
    description: string | null;
    image: string | null;
}

export interface StoreNode {
    id: number;
    name: string;
    multiplier: number;
    /** False when the node is at its shop limits for the plan being bought (see planLocations). */
    available: boolean;
}

export interface StoreLocation {
    id: number;
    name: string;
    multiplier: number;
    /** False when every node of the location is at its shop limits for the plan being bought. */
    available: boolean;
    nodes: StoreNode[];
}

export interface StorePlan {
    id: number;
    categoryId: number | null;
    name: string;
    description: string;
    /** Tokens per 30 days, before period modifiers and multipliers. */
    priceTokens: number;
    memory: number;
    swap: number;
    disk: number;
    cpu: number;
    databases: number;
    backups: number;
    allocations: number;
    eggs: StoreEgg[];
    /** The nodes the plan may deploy on (the catalog lists only those). */
    nodeIds: number[] | null;
    /** The plan's position in its category; the catalog sends plans cheapest first. */
    sort: number;
    /** False when the plan no longer fits within the capacity the store sells. */
    canProvision: boolean;
    /** The nodes that are at their own shop limits for this plan. */
    fullNodeIds: number[];
}

export interface StoreCategory {
    id: number;
    name: string;
    description: string | null;
    plans: StorePlan[];
}

export interface StorePeriod {
    id: number;
    name: string;
    days: number;
    /** −20 is a 20% discount, +10 a 10% surcharge. */
    priceModifierPercent: number;
}

export interface StoreAddon {
    key: string;
    name: string;
    description: string | null;
    resource: StoreAddonResource;
    /** Resource added per unit. */
    amount: number;
    /** Tokens per unit per 30 days. */
    priceTokens: number;
    /** 0 means no cap. */
    maxUnits: number;
}

export interface StoreCatalog {
    /** False while the admin has the store closed. */
    enabled: boolean;
    categories: StoreCategory[];
    periods: StorePeriod[];
    addons: StoreAddon[];
    /** Every location some plan may run in, each with the nodes any plan may use there. */
    locations: StoreLocation[];
    /** False when no plan fits within the capacity the store sells any more. */
    canProvision: boolean;
    subdomainsEnabled: boolean;
    /** The domain custom subdomains sit under, such as `monoforge.net`. */
    subdomainBase: string | null;
    /** The player's token balance. */
    balance: number | null;
    trial: StoreTrialOffer;
}

export interface StoreTrialOffer {
    /** Whether the store offers free trials at all. */
    enabled: boolean;
    hours: number;
    planIds: number[];
    /** Whether this player may start one. */
    eligible: boolean;
    reason: string | null;
}

export type StoreResources = Record<StoreAddonResource, number>;

/** A coupon checked against the quote: what it takes off the first period, or why it cannot be used. */
export interface StoreQuoteCoupon {
    code: string;
    valid: boolean;
    message: string | null;
    discount: number;
    /** What the first period costs with the coupon; renewals cost `totalPerPeriod`. */
    firstPeriod: number;
}

export interface StoreQuote {
    totalPerPeriod: number;
    periodDays: number;
    multiplier: number;
    lines: { label: string; tokens: number }[];
    resources: Partial<StoreResources>;
    canProvision: boolean;
    balance: number | null;
    coupon: StoreQuoteCoupon | null;
}

export interface StoreSelection {
    planId: number;
    periodId: number;
    eggId: number;
    locationId: number | null;
    nodeId: number | null;
    /** Add-on key → units; zero units are left out. */
    addons: Record<string, number>;
    /** A discount coupon for the first period. */
    coupon?: string | null;
}

export type PurchaseResult = ClientStorePurchaseAttributes;

export type TrialResult = ClientStoreTrialAttributes;

const noTrial: StoreTrialOffer = { enabled: false, hours: 0, planIds: [], eligible: false, reason: null };

export const mapTrialOffer = (raw: RawStoreTrialOffer | undefined): StoreTrialOffer =>
    raw
        ? { enabled: raw.enabled, hours: raw.hours, planIds: raw.plan_ids, eligible: raw.eligible, reason: raw.reason }
        : noTrial;

/** Decimal columns may arrive as strings. */
const multiplierOf = (value: number | string | null | undefined): number => Number(value ?? 1) || 1;

const mapLocation = (location: RawStoreLocation): StoreLocation => ({
    id: location.id,
    name: location.long || location.short || `Location ${location.id}`,
    multiplier: multiplierOf(location.multiplier),
    available: true,
    nodes: location.nodes.map((node) => ({
        id: node.id,
        name: node.name,
        multiplier: multiplierOf(node.multiplier),
        available: true,
    })),
});

const mapPlan = (plan: RawStorePlan, categoryId: number, sort: number): StorePlan => ({
    id: plan.id,
    categoryId,
    name: plan.name,
    description: plan.description ?? '',
    priceTokens: plan.price_tokens,
    memory: plan.resources.memory,
    swap: plan.resources.swap,
    disk: plan.resources.disk,
    cpu: plan.resources.cpu,
    databases: plan.resources.databases,
    backups: plan.resources.backups,
    allocations: plan.resources.allocations,
    eggs: plan.eggs.map((egg) => ({
        id: egg.id,
        name: egg.name,
        description: egg.description ?? null,
        image: egg.image ?? null,
    })),
    nodeIds: plan.locations.flatMap((location) => location.nodes.map((node) => node.id)),
    sort,
    canProvision: plan.can_provision,
    fullNodeIds: plan.locations.flatMap((location) =>
        location.nodes.filter((node) => node.can_provision === false).map((node) => node.id)
    ),
});

/** The plans' locations merged into one list, each with every node some plan may use there. */
function mergeLocations(plans: RawStorePlan[]): StoreLocation[] {
    const merged = new Map<number, StoreLocation>();

    for (const location of plans.flatMap((plan) => plan.locations.map(mapLocation))) {
        const known = merged.get(location.id);

        if (!known) {
            merged.set(location.id, { ...location, nodes: [...location.nodes] });
            continue;
        }

        for (const node of location.nodes) {
            if (!known.nodes.some((each) => each.id === node.id)) {
                known.nodes.push(node);
            }
        }
    }

    return [...merged.values()];
}

export function mapCatalog(raw: RawStoreCatalog): StoreCatalog {
    const plans = raw.categories.flatMap((category) => category.plans);

    return {
        enabled: raw.enabled,
        categories: raw.categories.map((category) => ({
            id: category.id,
            name: category.name,
            description: category.description ?? null,
            plans: category.plans.map((plan, index) => mapPlan(plan, category.id, index)),
        })),
        periods: raw.periods.map((period) => ({
            id: period.id,
            name: period.name,
            days: period.days,
            priceModifierPercent: period.price_modifier_percent,
        })),
        addons: raw.addons.map((addon) => ({
            key: addon.key,
            name: addon.name,
            description: addon.description ?? null,
            resource: addon.resource,
            amount: addon.amount,
            priceTokens: addon.price_tokens,
            maxUnits: addon.max_units,
        })),
        locations: mergeLocations(plans),
        canProvision: plans.length === 0 || plans.some((plan) => plan.can_provision),
        subdomainsEnabled: raw.subdomains_enabled,
        subdomainBase: raw.subdomain_base ?? null,
        balance: raw.balance ?? null,
        trial: mapTrialOffer(raw.trial),
    };
}

export function mapQuote(raw: RawStoreQuoteResponse): StoreQuote {
    return {
        totalPerPeriod: raw.quote.total_per_period,
        periodDays: raw.quote.period_days,
        multiplier: multiplierOf(raw.quote.multiplier),
        lines: raw.quote.lines,
        resources: raw.quote.resources,
        canProvision: raw.can_provision,
        balance: raw.balance ?? null,
        coupon: raw.coupon
            ? {
                  code: raw.coupon.code,
                  valid: raw.coupon.valid,
                  message: raw.coupon.message,
                  discount: raw.coupon.discount_tokens,
                  firstPeriod: raw.coupon.first_period_tokens,
              }
            : null,
    };
}
