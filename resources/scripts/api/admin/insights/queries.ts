import { useQuery } from '@tanstack/react-query';

import { adminGetLiveUsageOptions, adminGetStorageUsageOptions } from '@/api/generated/@tanstack/react-query.gen';
import type { AdminGetLiveUsageResponse, AdminGetStorageUsageResponse } from '@/api/generated';

export type AdminLiveUsage = AdminGetLiveUsageResponse;
export type AdminNodeUsage = AdminLiveUsage['nodes'][number];
export type AdminServerUsage = AdminNodeUsage['servers'][number];

export type AdminStorageUsage = AdminGetStorageUsageResponse;
export type AdminNodeStorage = AdminStorageUsage['nodes'][number];
export type AdminWebsiteStorage = AdminStorageUsage['website'];

/** How often the live usage refreshes; the panel caches Wings readings for the same few seconds. */
export const LIVE_USAGE_INTERVAL = 5000;

export const adminLiveUsageQueryOptions = () => adminGetLiveUsageOptions();

export const adminStorageUsageQueryOptions = () => adminGetStorageUsageOptions();

/** Live node usage, refetched every few seconds while the tab is visible. */
export const useAdminLiveUsage = () =>
    useQuery({
        ...adminLiveUsageQueryOptions(),
        refetchInterval: LIVE_USAGE_INTERVAL,
        refetchIntervalInBackground: false,
    });

export const useAdminStorageUsage = () => useQuery(adminStorageUsageQueryOptions());
