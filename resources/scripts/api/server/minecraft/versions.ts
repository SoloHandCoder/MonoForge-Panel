import { useMutation, useQuery } from '@tanstack/react-query';

import { clientGetMinecraftVersionInstallProgress } from '@/api/generated';
import type {
    ClientGetMinecraftSoftwareResponse,
    ClientGetMinecraftVersionInstallProgressResponse,
    ClientListMinecraftVersionsResponse,
} from '@/api/generated';
import {
    clientGetMinecraftSoftwareOptions,
    clientInstallMinecraftVersionMutation,
    clientListMinecraftVersionsOptions,
} from '@/api/generated/@tanstack/react-query.gen';
import { retryUnlessClientError } from '@/api/queryRetry';

export type MinecraftSoftwareInfo = ClientGetMinecraftSoftwareResponse['attributes'];
export type MinecraftSoftwareOption = MinecraftSoftwareInfo['software'][number];
export type MinecraftVersion = ClientListMinecraftVersionsResponse['data'][number];
export type MinecraftInstallProgress = ClientGetMinecraftVersionInstallProgressResponse['attributes'];

const retryRead = retryUnlessClientError(2);

export const useMinecraftSoftware = (uuid: string) =>
    useQuery({
        ...clientGetMinecraftSoftwareOptions({ path: { server_uuid: uuid } }),
        enabled: uuid.length > 0,
        retry: retryRead,
        select: (response) => response.attributes,
    });

export const useMinecraftVersions = (uuid: string, software: string | null, snapshots: boolean) =>
    useQuery({
        ...clientListMinecraftVersionsOptions({
            path: { server_uuid: uuid, software: software ?? '' },
            query: snapshots ? { snapshots: true } : {},
        }),
        enabled: uuid.length > 0 && !!software,
        retry: retryRead,
        // The panel caches the lists for an hour.
        staleTime: 5 * 60_000,
        select: (response) => response.data,
    });

const POLL_MS = 2000;
// A switch waits in the queue and then downloads for up to about 16 minutes.
const POLL_LIMIT = 600;

/** Polls a queued switch until it completed or failed. */
export const waitForMinecraftInstall = async (
    uuid: string,
    id: string,
    sleep: (ms: number) => Promise<void> = (ms) => new Promise((resolve) => setTimeout(resolve, ms)),
    attempt = 0
): Promise<MinecraftInstallProgress> => {
    if (attempt >= POLL_LIMIT) {
        throw new Error('The switch is taking longer than expected. Check the console later.');
    }

    const { data } = await clientGetMinecraftVersionInstallProgress({ path: { server_uuid: uuid, installJob: id } });

    if (data.attributes.status === 'completed' || data.attributes.status === 'failed') {
        return data.attributes;
    }

    await sleep(POLL_MS);

    return waitForMinecraftInstall(uuid, id, sleep, attempt + 1);
};

export const useInstallMinecraftVersion = (uuid: string) => {
    const mutation = useMutation({ ...clientInstallMinecraftVersionMutation() });

    return {
        ...mutation,
        install: (software: string, version: string) =>
            mutation.mutateAsync({ path: { server_uuid: uuid }, body: { software, version } }),
    };
};

/** The software names, for messages. */
export const SOFTWARE_LABELS = new Map<string, string>([
    ['vanilla', 'Vanilla'],
    ['paper', 'Paper'],
    ['purpur', 'Purpur'],
    ['folia', 'Folia'],
    ['fabric', 'Fabric'],
    ['forge', 'Forge'],
    ['neoforge', 'NeoForge'],
    ['quilt', 'Quilt'],
    ['spigot', 'Spigot'],
    ['bukkit', 'Bukkit'],
    ['velocity', 'Velocity'],
    ['waterfall', 'Waterfall'],
    ['bungeecord', 'BungeeCord'],
]);

export const softwareLabel = (software: string | null | undefined): string =>
    software ? (SOFTWARE_LABELS.get(software) ?? software) : 'Unknown software';
