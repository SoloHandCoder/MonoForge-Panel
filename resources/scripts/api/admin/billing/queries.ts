import { keepPreviousData, useQuery } from '@tanstack/react-query';

import { adminGetRevenueOptions } from '@/api/generated/@tanstack/react-query.gen';
import type { AdminGetRevenueData, AdminGetRevenueResponse } from '@/api/generated';

export type AdminRevenue = AdminGetRevenueResponse;
export type AdminRevenueMoney = AdminRevenue['money'][number];
export type AdminRevenueDay = AdminRevenue['daily'][number];
export type AdminRevenuePlan = AdminRevenue['top_plans'][number];
export type AdminRevenueQuery = NonNullable<AdminGetRevenueData['query']>;

/** The panel caches a report for five minutes, so refetching sooner only returns the same one. */
export const REVENUE_STALE_MS = 5 * 60 * 1000;

export const adminRevenueQueryOptions = (query: AdminRevenueQuery = { range: '30' }) =>
    adminGetRevenueOptions({ query });

/** The revenue report for a range; the previous range stays on screen while the next one loads. */
export const useAdminRevenue = (query: AdminRevenueQuery) =>
    useQuery({
        ...adminRevenueQueryOptions(query),
        staleTime: REVENUE_STALE_MS,
        placeholderData: keepPreviousData,
    });
