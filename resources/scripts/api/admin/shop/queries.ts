// The admin Shop tab (phase 3 Task R), written by hand until the generated API client is regenerated
// with the /api/admin/shop endpoints.
/* oxlint-disable no-restricted-properties, rules/generated-api-mutations */
import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import http from '@/api/http';
import { notifyHttpError } from '@/plugins/notifications';

export type ShopAddonResource = 'backups' | 'databases' | 'allocations' | 'memory' | 'disk' | 'cpu';
export type ShopMultiplierScope = 'location' | 'node';

export const shopAddonResources: readonly ShopAddonResource[] = [
    'memory',
    'disk',
    'cpu',
    'backups',
    'databases',
    'allocations',
];

export interface ShopResources {
    memory: number;
    swap: number;
    disk: number;
    io: number;
    cpu: number;
    databases: number;
    backups: number;
    allocations: number;
}

export interface ShopCategory {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    sort: number;
    visible: boolean;
    plans_count: number;
}

export interface ShopPlan {
    id: number;
    category_id: number;
    name: string;
    description: string | null;
    visible: boolean;
    sort: number;
    price_tokens: number;
    resources: ShopResources;
    egg_ids: number[];
    /** Null allows every deployable node. */
    node_ids: number[] | null;
}

export interface ShopPeriod {
    id: number;
    name: string;
    days: number;
    /** -20 is a 20% discount, 10 a 10% surcharge. */
    price_modifier_percent: number;
    sort: number;
    enabled: boolean;
}

export interface ShopMultiplier {
    id: number;
    scope: ShopMultiplierScope;
    scope_id: number;
    multiplier: number;
    label: string | null;
}

export interface ShopAddon {
    id: number;
    key: string;
    name: string;
    description: string | null;
    resource: ShopAddonResource;
    amount: number;
    price_tokens: number;
    max_units: number;
    enabled: boolean;
}

export interface ShopCapacity {
    memory: number;
    disk: number;
    cpu: number;
}

export interface ShopSettings {
    enabled: boolean;
    grace_days: number;
    low_balance_days: number;
    /** 0 is no limit. */
    limits: ShopCapacity;
    used: ShopCapacity;
    /** Null where there is no limit. */
    remaining: { memory: number | null; disk: number | null; cpu: number | null };
}

export interface ShopEggOption {
    id: number;
    name: string;
    tags: { id: number; name: string; color: string | null }[];
}

export interface ShopNodeOption {
    id: number;
    name: string;
    location_id: number;
    public: boolean;
    maintenance: boolean;
}

export interface ShopLocationOption {
    id: number;
    short: string;
    long: string | null;
}

export interface ShopOptions {
    eggs: ShopEggOption[];
    nodes: ShopNodeOption[];
    locations: ShopLocationOption[];
}

export type ShopCategoryInput = Pick<ShopCategory, 'name' | 'slug' | 'description' | 'visible'>;
export type ShopPlanInput = Omit<ShopPlan, 'id' | 'resources' | 'node_ids'> & ShopResources & { node_ids: number[] };
export type ShopPeriodInput = Omit<ShopPeriod, 'id'>;
export type ShopMultiplierInput = Omit<ShopMultiplier, 'id'>;
export type ShopAddonInput = Omit<ShopAddon, 'id'>;
export type ShopSettingsInput = Pick<ShopSettings, 'enabled' | 'grace_days' | 'low_balance_days' | 'limits'>;

const base = '/api/admin/shop';
const shopKey = ['admin', 'shop'] as const;

const listQuery = <T>(resource: string) =>
    queryOptions({
        queryKey: [...shopKey, resource],
        queryFn: async ({ signal }): Promise<T[]> =>
            (await http.get<{ data: T[] }>(`${base}/${resource}`, { signal })).data.data,
    });

export const adminShopCategoriesQueryOptions = () => listQuery<ShopCategory>('categories');
export const adminShopPlansQueryOptions = () => listQuery<ShopPlan>('plans');
export const adminShopPeriodsQueryOptions = () => listQuery<ShopPeriod>('periods');
export const adminShopMultipliersQueryOptions = () => listQuery<ShopMultiplier>('multipliers');
export const adminShopAddonsQueryOptions = () => listQuery<ShopAddon>('addons');

export const adminShopSettingsQueryOptions = () =>
    queryOptions({
        queryKey: [...shopKey, 'settings'],
        queryFn: async ({ signal }): Promise<ShopSettings> =>
            (await http.get<ShopSettings>(`${base}/settings`, { signal })).data,
    });

export const adminShopOptionsQueryOptions = () =>
    queryOptions({
        queryKey: [...shopKey, 'options'],
        queryFn: async ({ signal }): Promise<ShopOptions> =>
            (await http.get<ShopOptions>(`${base}/options`, { signal })).data,
        staleTime: 60_000,
    });

export const useAdminShopCategories = () => useQuery(adminShopCategoriesQueryOptions());
export const useAdminShopPlans = () => useQuery(adminShopPlansQueryOptions());
export const useAdminShopPeriods = () => useQuery(adminShopPeriodsQueryOptions());
export const useAdminShopMultipliers = () => useQuery(adminShopMultipliersQueryOptions());
export const useAdminShopAddons = () => useQuery(adminShopAddonsQueryOptions());
export const useAdminShopSettings = () => useQuery(adminShopSettingsQueryOptions());
export const useAdminShopOptions = () => useQuery(adminShopOptionsQueryOptions());

interface SaveVariables<TInput> {
    id?: number;
    values: TInput;
}

/** Creates (no id) or updates (with id) one shop resource, then refreshes the whole Shop tab. */
const useSaveShopItem = <TInput, TItem extends { name?: string }>(resource: string, noun: string) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({ id, values }: SaveVariables<TInput>): Promise<TItem> =>
            id === undefined
                ? (await http.post<TItem>(`${base}/${resource}`, values)).data
                : (await http.put<TItem>(`${base}/${resource}/${id}`, values)).data,
        onSuccess: async (item, { id }) => {
            await queryClient.invalidateQueries({ queryKey: shopKey });
            toast.success(id === undefined ? `${noun} created` : `${noun} updated`, {
                description: item.name ? `${item.name} has been saved.` : undefined,
            });
        },
        onError: (error, { id }) =>
            notifyHttpError(
                error,
                id === undefined ? `Unable to create ${noun.toLowerCase()}` : `Unable to update ${noun.toLowerCase()}`
            ),
    });
};

const useDeleteShopItem = (resource: string, noun: string) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (id: number) => {
            await http.delete(`${base}/${resource}/${id}`);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: shopKey });
            toast.success(`${noun} deleted`);
        },
        onError: (error) => notifyHttpError(error, `Unable to delete ${noun.toLowerCase()}`),
    });
};

export const useSaveShopCategory = () => useSaveShopItem<ShopCategoryInput, ShopCategory>('categories', 'Category');
export const useDeleteShopCategory = () => useDeleteShopItem('categories', 'Category');
export const useSaveShopPlan = () => useSaveShopItem<ShopPlanInput, ShopPlan>('plans', 'Plan');
export const useDeleteShopPlan = () => useDeleteShopItem('plans', 'Plan');
export const useSaveShopPeriod = () => useSaveShopItem<ShopPeriodInput, ShopPeriod>('periods', 'Billing period');
export const useDeleteShopPeriod = () => useDeleteShopItem('periods', 'Billing period');
export const useSaveShopMultiplier = () =>
    useSaveShopItem<ShopMultiplierInput, ShopMultiplier & { name?: string }>('multipliers', 'Multiplier');
export const useDeleteShopMultiplier = () => useDeleteShopItem('multipliers', 'Multiplier');
export const useSaveShopAddon = () => useSaveShopItem<ShopAddonInput, ShopAddon>('addons', 'Add-on');
export const useDeleteShopAddon = () => useDeleteShopItem('addons', 'Add-on');

export const useReorderShopCategories = () => {
    const queryClient = useQueryClient();
    const key = adminShopCategoriesQueryOptions().queryKey;

    return useMutation({
        mutationFn: async (ids: number[]) => {
            await http.put(`${base}/categories/order`, { ids });
        },
        // Moves the rows at once; the refetch afterwards confirms the saved order.
        onMutate: async (ids) => {
            await queryClient.cancelQueries({ queryKey: key });
            const previous = queryClient.getQueryData<ShopCategory[]>(key);

            if (previous) {
                const byId = new Map(previous.map((category) => [category.id, category]));

                queryClient.setQueryData<ShopCategory[]>(
                    key,
                    ids.flatMap((id, sort) => {
                        const category = byId.get(id);

                        return category ? [{ ...category, sort }] : [];
                    })
                );
            }

            return { previous };
        },
        onError: (error, _ids, context) => {
            if (context?.previous) {
                queryClient.setQueryData(key, context.previous);
            }

            notifyHttpError(error, 'Unable to reorder categories');
        },
        onSettled: () => queryClient.invalidateQueries({ queryKey: key }),
    });
};

export const useUpdateShopSettings = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (values: ShopSettingsInput): Promise<ShopSettings> =>
            (await http.put<ShopSettings>(`${base}/settings`, values)).data,
        onSuccess: (settings) => {
            queryClient.setQueryData(adminShopSettingsQueryOptions().queryKey, settings);
            toast.success('Shop settings updated', {
                description: settings.enabled ? 'The store is open.' : 'The store is closed.',
            });
        },
        onError: (error) => notifyHttpError(error, 'Unable to update shop settings'),
    });
};
