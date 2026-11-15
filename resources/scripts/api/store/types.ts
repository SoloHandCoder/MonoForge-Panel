import type { ClientStorePurchaseAttributes } from '@/api/generated';

/*
 * The store as the checkout uses it. Task R serves the catalog and the quotes; the `Raw*` shapes are
 * the payloads this page reads from them (snake_case attributes in the panel's `{ object, attributes }`
 * envelope), mapped here to camelCase once, at the boundary.
 */

export interface RawStoreEgg {
    id: number;
    name: string;
    description?: string | null;
    image?: string | null;
}

export interface RawStorePlan {
    id: number;
    category_id?: number | null;
    name: string;
    description?: string | null;
    price_tokens: number;
    memory: number;
    swap?: number;
    disk: number;
    cpu: number;
    databases: number;
    backups: number;
    allocations: number;
    eggs?: RawStoreEgg[];
    node_ids?: number[] | null;
    sort?: number;
}

export interface RawStoreCategory {
    id: number;
    name: string;
    description?: string | null;
    plans?: RawStorePlan[];
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
    description?: string | null;
    resource: StoreAddonResource;
    amount: number;
    price_tokens: number;
    max_units: number;
}

export interface RawStoreNode {
    id: number;
    name: string;
    multiplier?: number | string | null;
}

export interface RawStoreLocation {
    id: number;
    name?: string;
    short?: string;
    long?: string | null;
    description?: string | null;
    multiplier?: number | string | null;
    nodes?: RawStoreNode[];
}

export interface RawStoreCatalog {
    categories: RawStoreCategory[];
    periods: RawStorePeriod[];
    addons?: RawStoreAddon[];
    locations?: RawStoreLocation[];
    can_provision?: boolean;
    subdomains_enabled?: boolean;
    subdomain_base?: string | null;
    balance?: number | null;
}

export interface RawStoreQuote {
    base_per_period: number;
    multiplier: number | string;
    addons_per_period: number;
    total_per_period: number;
    period_days: number;
    lines?: { label: string; tokens: number }[];
    resources?: Partial<Record<StoreAddonResource, number>>;
}

export interface RawStoreQuoteResponse {
    quote: RawStoreQuote;
    can_provision?: boolean;
    balance?: number | null;
}

/** The panel's resource envelope; a bare payload is read as its own attributes. */
export type Envelope<TAttributes> = { object?: string; attributes: TAttributes } | TAttributes;

export interface StoreEgg {
    id: number;
    name: string;
    description: string | null;
    image: string | null;
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
    /** Nodes the plan may deploy on; null means any node. */
    nodeIds: number[] | null;
    sort: number;
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

export interface StoreNode {
    id: number;
    name: string;
    multiplier: number;
}

export interface StoreLocation {
    id: number;
    name: string;
    multiplier: number;
    nodes: StoreNode[];
}

export interface StoreCatalog {
    categories: StoreCategory[];
    periods: StorePeriod[];
    addons: StoreAddon[];
    locations: StoreLocation[];
    /** False when the shop has sold out its capacity. */
    canProvision: boolean;
    subdomainsEnabled: boolean;
    /** The domain custom subdomains sit under, such as `monoforge.net`. */
    subdomainBase: string | null;
    /** The player's token balance, when the catalog carries it. */
    balance: number | null;
}

export type StoreResources = Record<StoreAddonResource, number>;

export interface StoreQuote {
    totalPerPeriod: number;
    periodDays: number;
    multiplier: number;
    lines: { label: string; tokens: number }[];
    resources: Partial<StoreResources>;
    canProvision: boolean;
    balance: number | null;
}

export interface StoreSelection {
    planId: number;
    periodId: number;
    eggId: number;
    locationId: number | null;
    nodeId: number | null;
    /** Add-on key → units; zero units are left out. */
    addons: Record<string, number>;
}

export type PurchaseResult = ClientStorePurchaseAttributes;

const attributesOf = <TAttributes extends object>(payload: Envelope<TAttributes>): TAttributes =>
    'attributes' in payload ? payload.attributes : payload;

/** Decimal columns arrive as strings. */
const multiplierOf = (value: number | string | null | undefined): number => Number(value ?? 1) || 1;

const mapPlan = (plan: RawStorePlan, categoryId: number): StorePlan => ({
    id: plan.id,
    categoryId: plan.category_id ?? categoryId,
    name: plan.name,
    description: plan.description ?? '',
    priceTokens: plan.price_tokens,
    memory: plan.memory,
    swap: plan.swap ?? 0,
    disk: plan.disk,
    cpu: plan.cpu,
    databases: plan.databases,
    backups: plan.backups,
    allocations: plan.allocations,
    eggs: (plan.eggs ?? []).map((egg) => ({
        id: egg.id,
        name: egg.name,
        description: egg.description ?? null,
        image: egg.image ?? null,
    })),
    nodeIds: plan.node_ids ?? null,
    sort: plan.sort ?? 0,
});

export function mapCatalog(payload: Envelope<RawStoreCatalog>): StoreCatalog {
    const raw = attributesOf(payload);

    return {
        categories: raw.categories.map((category) => ({
            id: category.id,
            name: category.name,
            description: category.description ?? null,
            plans: (category.plans ?? []).map((plan) => mapPlan(plan, category.id)),
        })),
        periods: raw.periods.map((period) => ({
            id: period.id,
            name: period.name,
            days: period.days,
            priceModifierPercent: period.price_modifier_percent,
        })),
        addons: (raw.addons ?? []).map((addon) => ({
            key: addon.key,
            name: addon.name,
            description: addon.description ?? null,
            resource: addon.resource,
            amount: addon.amount,
            priceTokens: addon.price_tokens,
            maxUnits: addon.max_units,
        })),
        locations: (raw.locations ?? []).map((location) => ({
            id: location.id,
            name: location.name ?? location.long ?? location.short ?? `Location ${location.id}`,
            multiplier: multiplierOf(location.multiplier),
            nodes: (location.nodes ?? []).map((node) => ({
                id: node.id,
                name: node.name,
                multiplier: multiplierOf(node.multiplier),
            })),
        })),
        canProvision: raw.can_provision !== false,
        subdomainsEnabled: raw.subdomains_enabled === true,
        subdomainBase: raw.subdomain_base ?? null,
        balance: raw.balance ?? null,
    };
}

export function mapQuote(payload: Envelope<RawStoreQuoteResponse>): StoreQuote {
    const raw = attributesOf(payload);

    return {
        totalPerPeriod: raw.quote.total_per_period,
        periodDays: raw.quote.period_days,
        multiplier: multiplierOf(raw.quote.multiplier),
        lines: raw.quote.lines ?? [],
        resources: raw.quote.resources ?? {},
        canProvision: raw.can_provision !== false,
        balance: raw.balance ?? null,
    };
}
