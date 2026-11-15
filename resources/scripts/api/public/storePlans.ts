import { queryOptions, useQuery } from '@tanstack/react-query';
import { apiListThePublicStorePlans } from '@/api/generated';

export interface PublicPeriod {
    id: number;
    name: string;
    days: number;
}

export interface PublicPlanPrice {
    periodId: number;
    days: number;
    tokens: number;
}

export interface PublicPlan {
    id: number;
    name: string;
    description: string | null;
    memory: number;
    disk: number;
    cpu: number;
    databases: number;
    backups: number;
    games: string[];
    available: boolean;
    prices: PublicPlanPrice[];
}

export interface PublicCategory {
    name: string;
    slug: string;
    description: string | null;
    plans: PublicPlan[];
}

export interface PublicStorePlans {
    enabled: boolean;
    assistantEnabled: boolean;
    periods: PublicPeriod[];
    categories: PublicCategory[];
}

/** The exact payload of GET /api/public/store/plans; the generated type marks every field optional. */
export interface RawPublicStorePlans {
    enabled: boolean;
    assistant_enabled: boolean;
    periods: PublicPeriod[];
    categories: {
        name: string;
        slug: string;
        description: string | null;
        plans: {
            id: number;
            name: string;
            description: string | null;
            resources: { memory: number; disk: number; cpu: number; databases: number; backups: number };
            games: string[];
            available: boolean;
            prices: { period_id: number; days: number; tokens: number }[];
        }[];
    }[];
}

export const mapPublicStorePlans = (raw: RawPublicStorePlans): PublicStorePlans => ({
    enabled: raw.enabled,
    assistantEnabled: raw.assistant_enabled,
    periods: raw.periods.map(({ id, name, days }) => ({ id, name, days })),
    categories: raw.categories.map((category) => ({
        name: category.name,
        slug: category.slug,
        description: category.description,
        plans: category.plans.map((plan) => ({
            id: plan.id,
            name: plan.name,
            description: plan.description,
            ...plan.resources,
            games: plan.games,
            available: plan.available,
            prices: plan.prices.map((price) => ({ periodId: price.period_id, days: price.days, tokens: price.tokens })),
        })),
    })),
});

/** The plans on sale for the public homepage. The server caches them for a minute, so the page does too. */
export const publicStorePlansQueryOptions = () =>
    queryOptions({
        queryKey: ['public', 'store', 'plans'],
        queryFn: async ({ signal }): Promise<PublicStorePlans> =>
            mapPublicStorePlans((await apiListThePublicStorePlans({ signal })).data as RawPublicStorePlans),
        staleTime: 60_000,
        retry: 1,
    });

export const usePublicStorePlans = () => useQuery(publicStorePlansQueryOptions());
