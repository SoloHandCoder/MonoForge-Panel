import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';

import {
    clientGetModpackInstallProgressOptions,
    clientGetModpackInstallProgressQueryKey,
    clientGetPluginEnvironmentOptions,
    clientInstallModpackMutation,
    clientInstallPluginMutation,
    clientListInstalledPluginsOptions,
    clientListPluginVersionsOptions,
    clientRemoveInstalledPluginMutation,
    clientSearchPluginsOptions,
    clientUpdateInstalledPluginMutation,
} from '@/api/generated/@tanstack/react-query.gen';
import type {
    ClientGetPluginEnvironmentResponse,
    ClientListInstalledPluginsResponse,
    ClientListPluginVersionsResponse,
    ClientModpackInstallAttributes,
    ClientSearchPluginsResponse,
} from '@/api/generated';
import { hasGeneratedOperationId, hasPathParam } from '@/api/queryKeyGuards';
import { retryUnlessClientError } from '@/api/queryRetry';
import { notifyHttpError } from '@/plugins/notifications';

export type PluginSource = 'modrinth' | 'hangar' | 'curseforge';
export type PluginProjectType = 'plugin' | 'mod' | 'modpack';
export type PluginSort = 'relevance' | 'downloads' | 'updated' | 'newest';

export type PluginEnvironment = ClientGetPluginEnvironmentResponse['attributes'];
export type PluginPlatform = PluginEnvironment['platform'];
export type PluginSearchResults = ClientSearchPluginsResponse;
export type PluginProject = ClientSearchPluginsResponse['data'][number];
export type PluginVersion = ClientListPluginVersionsResponse['data'][number];
export type InstalledPlugin = ClientListInstalledPluginsResponse['data'][number];
export type ModpackProgress = ClientModpackInstallAttributes;

/** The user's choice of loader and Minecraft version over what the panel detected. */
export interface PlatformOverrides {
    loader?: string | null;
    game_version?: string | null;
}

export const PLUGIN_SOURCE_LABELS = {
    modrinth: 'Modrinth',
    hangar: 'Hangar',
    curseforge: 'CurseForge',
} as const satisfies Record<PluginSource, string>;

const LOADER_LABELS = new Map<string, string>([
    ['paper', 'Paper'],
    ['purpur', 'Purpur'],
    ['folia', 'Folia'],
    ['spigot', 'Spigot'],
    ['bukkit', 'Bukkit'],
    ['velocity', 'Velocity'],
    ['waterfall', 'Waterfall'],
    ['bungeecord', 'BungeeCord'],
    ['fabric', 'Fabric'],
    ['quilt', 'Quilt'],
    ['forge', 'Forge'],
    ['neoforge', 'NeoForge'],
]);

export const loaderLabel = (loader: string | null | undefined): string =>
    loader ? (LOADER_LABELS.get(loader) ?? loader) : 'Unknown loader';

/** Only overrides that are set travel with a request, so detection stays the default. */
/** The override fields a request carries. */
interface PlatformQuery {
    loader?: string;
    game_version?: string;
}

const overrideQuery = (overrides: PlatformOverrides): PlatformQuery => {
    const query: PlatformQuery = {};

    if (overrides.loader) {
        query.loader = overrides.loader;
    }

    if (overrides.game_version) {
        query.game_version = overrides.game_version;
    }

    return query;
};

const retryRead = retryUnlessClientError(2);

export const usePluginEnvironment = (uuid: string, overrides: PlatformOverrides) =>
    useQuery({
        ...clientGetPluginEnvironmentOptions({ path: { server_uuid: uuid }, query: overrideQuery(overrides) }),
        enabled: uuid.length > 0,
        placeholderData: keepPreviousData,
        retry: retryRead,
        select: (response) => response.attributes,
    });

export interface PluginSearchParams {
    source: PluginSource;
    type: PluginProjectType;
    query: string;
    page: number;
    sort: PluginSort;
}

export const usePluginSearch = (
    uuid: string,
    params: PluginSearchParams,
    overrides: PlatformOverrides,
    enabled: boolean
) =>
    useQuery({
        ...clientSearchPluginsOptions({
            path: { server_uuid: uuid },
            query: { ...params, ...overrideQuery(overrides) },
        }),
        enabled: enabled && uuid.length > 0,
        placeholderData: keepPreviousData,
        retry: retryRead,
        // The panel caches catalog searches for ten minutes as well.
        staleTime: 60_000,
    });

export const usePluginVersions = (
    uuid: string,
    params: { source: PluginSource; type: PluginProjectType; project: string } | null,
    overrides: PlatformOverrides
) =>
    useQuery({
        ...clientListPluginVersionsOptions({
            path: { server_uuid: uuid },
            query: { source: 'modrinth', project: '', ...params, ...overrideQuery(overrides) },
        }),
        enabled: params !== null && uuid.length > 0,
        retry: retryRead,
        staleTime: 60_000,
        select: (response) => response.data,
    });

export const useInstalledPlugins = (uuid: string, overrides: PlatformOverrides, enabled: boolean) =>
    useQuery({
        ...clientListInstalledPluginsOptions({ path: { server_uuid: uuid }, query: overrideQuery(overrides) }),
        enabled: enabled && uuid.length > 0,
        retry: retryRead,
    });

/** Every installed-list query of the server, whatever overrides it was read with. */
const invalidateInstalled = (queryClient: ReturnType<typeof useQueryClient>, uuid: string) =>
    queryClient.invalidateQueries({
        predicate: ({ queryKey }) =>
            hasGeneratedOperationId(queryKey, 'clientListInstalledPlugins') &&
            hasPathParam(queryKey, 'server_uuid', uuid),
    });

export const useInstallPlugin = (uuid: string, overrides: PlatformOverrides) => {
    const queryClient = useQueryClient();
    const mutation = useMutation({
        ...clientInstallPluginMutation(),
        onSuccess: async (result) => {
            toast.success(`Installed ${result.attributes.file_name}`);
            await invalidateInstalled(queryClient, uuid);
        },
        onError: (error) => notifyHttpError(error, 'Could not install it'),
    });

    return {
        ...mutation,
        install: (source: PluginSource, project: string, version: string) =>
            mutation.mutateAsync({
                path: { server_uuid: uuid },
                body: { source, project, version, ...overrideQuery(overrides) },
            }),
    };
};

export const useUpdateInstalledPlugin = (uuid: string, overrides: PlatformOverrides) => {
    const queryClient = useQueryClient();
    const mutation = useMutation({
        ...clientUpdateInstalledPluginMutation(),
        onSuccess: async (result) => {
            toast.success(`Updated to ${result.attributes.file_name}`);
            await invalidateInstalled(queryClient, uuid);
        },
        onError: (error) => notifyHttpError(error, 'Could not update it'),
    });

    return {
        ...mutation,
        update: (file: string) =>
            mutation.mutateAsync({ path: { server_uuid: uuid }, body: { file, ...overrideQuery(overrides) } }),
    };
};

export const useRemoveInstalledPlugin = (uuid: string, overrides: PlatformOverrides) => {
    const queryClient = useQueryClient();
    const mutation = useMutation({
        ...clientRemoveInstalledPluginMutation(),
        onSuccess: async (_result, variables) => {
            toast.success(`Removed ${variables.body.file}`);
            await invalidateInstalled(queryClient, uuid);
        },
        onError: (error) => notifyHttpError(error, 'Could not remove it'),
    });

    return {
        ...mutation,
        remove: (file: string) =>
            mutation.mutateAsync({ path: { server_uuid: uuid }, body: { file, ...overrideQuery(overrides) } }),
    };
};

export const modpackRunning = (progress: ModpackProgress | undefined): boolean =>
    progress?.status === 'queued' || progress?.status === 'running';

export const useModpackProgress = (uuid: string) =>
    useQuery({
        ...clientGetModpackInstallProgressOptions({ path: { server_uuid: uuid } }),
        enabled: uuid.length > 0,
        retry: retryRead,
        select: (response) => response.attributes,
        // Polls every two seconds while an install runs, and not at all otherwise.
        refetchInterval: (query) => (modpackRunning(query.state.data?.attributes) ? 2000 : false),
    });

export const useInstallModpack = (uuid: string) => {
    const queryClient = useQueryClient();
    const mutation = useMutation({
        ...clientInstallModpackMutation(),
        onSuccess: (result) => {
            queryClient.setQueryData(clientGetModpackInstallProgressQueryKey({ path: { server_uuid: uuid } }), result);
        },
        onError: (error) => notifyHttpError(error, 'Could not start the modpack install'),
    });

    return {
        ...mutation,
        install: (project: string, version: string) =>
            mutation.mutateAsync({ path: { server_uuid: uuid }, body: { project, version } }),
    };
};

/** Where a modpack install is, in words. */
export const MODPACK_STEPS = new Map<string, string>([
    ['idle', 'Not started'],
    ['queued', 'Waiting to start'],
    ['starting', 'Starting'],
    ['backup', 'Backing up the server'],
    ['download', 'Downloading the modpack'],
    ['extract', 'Unpacking the modpack'],
    ['files', 'Downloading mods'],
    ['overrides', 'Reading the config overrides'],
    ['install', 'Moving files into place'],
    ['cleanup', 'Cleaning up'],
    ['done', 'Done'],
]);

/**
 * Why a modpack version may not suit the server, or null when it does: another Minecraft
 * version, or a loader the server does not run.
 */
export function modpackMismatch(version: PluginVersion, platform: PluginPlatform | undefined): string | null {
    if (!platform) {
        return null;
    }

    const problems: string[] = [];

    if (platform.game_version && !version.game_versions.includes(platform.game_version)) {
        problems.push(
            `it is for Minecraft ${version.game_versions.join(', ') || 'another version'}, the server runs ${platform.game_version}`
        );
    }

    if (platform.loader && version.loaders.length > 0 && !version.loaders.includes(platform.loader)) {
        problems.push(
            `it needs ${version.loaders.map(loaderLabel).join(' or ')}, the server runs ${loaderLabel(platform.loader)}`
        );
    }

    return problems.length > 0 ? `This modpack may not run here: ${problems.join('; ')}.` : null;
}
