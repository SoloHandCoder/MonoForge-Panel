import type {
    StoreAddon,
    StoreAddonResource,
    StoreCatalog,
    StoreLocation,
    StorePeriod,
    StorePlan,
} from '@/api/store/types';

const integer = new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 });

export const tokens = (value: number): string => `${integer.format(value)} ${value === 1 ? 'token' : 'tokens'}`;

/** Megabytes as the store shows them: "512 MB", "2 GB", "1.5 GB", "Unlimited" for 0. */
export function megabytes(mb: number): string {
    if (mb <= 0) {
        return 'Unlimited';
    }

    if (mb < 1024) {
        return `${integer.format(mb)} MB`;
    }

    const gb = mb / 1024;

    return `${Number.isInteger(gb) ? gb : gb.toFixed(1)} GB`;
}

/** CPU as threads: 100% is one thread. */
export function cpu(percent: number): string {
    if (percent <= 0) {
        return 'Unlimited CPU';
    }

    const threads = percent / 100;

    return Number.isInteger(threads) ? `${threads} ${threads === 1 ? 'CPU thread' : 'CPU threads'}` : `${percent}% CPU`;
}

const plural = (count: number, one: string, many: string) => `${count} ${count === 1 ? one : many}`;

export const resourceLabel = (resource: StoreAddonResource, amount: number): string => {
    switch (resource) {
        case 'memory':
            return `${megabytes(amount)} RAM`;
        case 'disk':
            return `${megabytes(amount)} storage`;
        case 'cpu':
            return cpu(amount);
        case 'backups':
            return plural(amount, 'backup', 'backups');
        case 'databases':
            return plural(amount, 'database', 'databases');
        case 'allocations':
            return plural(amount, 'extra port', 'extra ports');
    }
};

/** The plan's resources, as the cards and the confirmation list them. */
export function planResources(
    plan: Pick<StorePlan, 'memory' | 'disk' | 'cpu' | 'backups' | 'databases' | 'allocations'>
): string[] {
    return [
        `${megabytes(plan.memory)} RAM`,
        `${megabytes(plan.disk)} storage`,
        cpu(plan.cpu),
        plural(plan.backups, 'backup', 'backups'),
        plural(plan.databases, 'database', 'databases'),
        ...(plan.allocations > 0 ? [plural(plan.allocations, 'extra port', 'extra ports')] : []),
    ];
}

/** The multipliers a plan can be bought at: of its allowed nodes, or of every location when any node goes. */
function planMultipliers(plan: StorePlan, locations: StoreLocation[]): number[] {
    const multipliers = locations.flatMap((location) => {
        const allowed = plan.nodeIds;
        const nodes = allowed ? location.nodes.filter((node) => allowed.includes(node.id)) : location.nodes;

        if (nodes.length === 0) {
            return plan.nodeIds ? [] : [location.multiplier];
        }

        return nodes.map((node) => (node.multiplier === 1 ? location.multiplier : node.multiplier));
    });

    return multipliers.length > 0 ? multipliers : [1];
}

/** The lowest price per 30 days the plan sells at, over its locations' multipliers. */
export const fromPerMonth = (plan: StorePlan, locations: StoreLocation[]): number =>
    Math.max(1, Math.ceil(plan.priceTokens * Math.min(...planMultipliers(plan, locations))));

/** Cheapest first; the admin's order breaks ties. */
export function sortPlans(plans: StorePlan[], locations: StoreLocation[]): StorePlan[] {
    return [...plans].sort(
        (a, b) =>
            fromPerMonth(a, locations) - fromPerMonth(b, locations) || a.sort - b.sort || a.name.localeCompare(b.name)
    );
}

/** The biggest discount any billing period gives, as a positive percent. */
export const bestDiscount = (periods: StorePeriod[]): number =>
    Math.max(0, ...periods.map((period) => -period.priceModifierPercent));

export function periodNote(period: StorePeriod): string | null {
    if (period.priceModifierPercent < 0) {
        return `Save ${-period.priceModifierPercent}%`;
    }

    return period.priceModifierPercent > 0 ? `+${period.priceModifierPercent}%` : null;
}

export const multiplierNote = (multiplier: number): string | null =>
    multiplier === 1 ? null : `${Number(multiplier.toFixed(2))}× price`;

/**
 * A local estimate with the store's formula, shown at once while the server's quote loads:
 * base = round(price × days / 30 × modifier), add-ons likewise, total = ceil(base × multiplier + add-ons).
 */
export function estimate(
    plan: StorePlan,
    period: StorePeriod,
    multiplier: number,
    addons: StoreAddon[],
    units: Record<string, number>
): number {
    const modifier = 1 + period.priceModifierPercent / 100;
    const base = Math.round(((plan.priceTokens * period.days) / 30) * modifier);
    const extras = addons.reduce(
        (sum, addon) => sum + (units[addon.key] ?? 0) * ((addon.priceTokens * period.days) / 30) * modifier,
        0
    );

    return Math.max(1, Math.ceil(base * multiplier + extras));
}

/** The multiplier for a location, or one of its nodes. */
export function selectionMultiplier(catalog: StoreCatalog, locationId: number | null, nodeId: number | null): number {
    const location = catalog.locations.find((each) => each.id === locationId);
    const node = location?.nodes.find((each) => each.id === nodeId);

    const fallback = location?.multiplier ?? 1;

    return !node || node.multiplier === 1 ? fallback : node.multiplier;
}

/** "every 30 days", "every 365 days". */
export const everyDays = (days: number): string => (days === 1 ? 'every day' : `every ${days} days`);
