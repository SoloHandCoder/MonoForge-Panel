import { useMemo } from 'react';
import {
    createServerAllocationInput,
    deleteServerAllocationInput,
    setPrimaryAllocationInput,
    updateAllocationNotesInput,
    useCreateServerAllocation,
    useDeleteServerAllocation,
    useServerAllocations,
    updateServerMinecraftHostnameInput,
    useServerMinecraftHostname,
    useSetPrimaryAllocation,
    useUpdateAllocationNotes,
    useUpdateServerMinecraftHostname,
    type ServerAllocation,
} from '@/api/server/network/queries';
import { useCurrentServer } from '@/api/server/queries';
import { ip } from '@/lib/formatters';
import { addressPending, playitAddress } from '@/lib/playit';
import { usePermissions } from '@/plugins/usePermissions';
import { implementViews } from '../registry';
import { pageStatus, pageStatusFixtures, type PageStatusModel } from '../pageStatus';

export interface ServerAllocationItem {
    readonly id: number;
    readonly ip: string;
    readonly alias: string | null;
    /** The alias, or the IP in brackets when it is IPv6. */
    readonly address: string;
    readonly port: number;
    readonly notes: string | null;
    /** The allocation the server binds to; it cannot be removed. */
    readonly primary: boolean;
}
/** The server's name in its node's shared Minecraft entrance: players join `<hostname>.<domain>`. */
export interface ServerMinecraftHostnameModel {
    readonly hostname: string;
    readonly domain: string;
    /** The full address players use; null while the entrance has none yet. */
    readonly address: string | null;
    /** The user may change the name (the rename permission). */
    readonly canEdit: boolean;
    /** Core has already shown a toast for a rejection; the promise rejects with it. */
    readonly rename: (hostname: string) => Promise<void>;
}

export interface ServerAllocationsModel {
    readonly allocations: readonly ServerAllocationItem[];
    /** The public address players connect to (a playit.gg tunnel, the game gateway or the shared Minecraft entrance). */
    readonly playitAddress: string | null;
    /** Set when the server is joined through its node's shared Minecraft entrance. */
    readonly minecraftHostname?: ServerMinecraftHostnameModel | null;
    /** The server is on a playit.gg node and has no tunnel yet, so players have no public address to use. */
    readonly addressPending?: boolean;
    /** The list has not loaded yet. */
    readonly loading: boolean;
    /** How many allocations the server may have; at 0 they are managed by an administrator. */
    readonly limit: number;
    readonly permissions: { readonly create: boolean; readonly update: boolean; readonly delete: boolean };
    /** The user may add allocations and the server has a limit to add them under. */
    readonly canManage: boolean;
    /** `canManage`, with room left under the limit. */
    readonly canCreate: boolean;
    /** Core has already shown a toast for a rejection. */
    readonly actions: {
        /** Assigns another allocation from the node. */
        readonly create: () => Promise<void>;
        readonly setPrimary: (id: number) => Promise<void>;
        readonly setNotes: (id: number, notes: string) => Promise<void>;
        /** Remove without a confirmation; ask the user first. */
        readonly remove: (id: number) => Promise<void>;
    };
}

declare module '../registry' {
    interface ViewModels {
        'page.server.network': PageStatusModel;
        'server.allocations': ServerAllocationsModel;
    }
}

const noAllocations: ServerAllocation[] = [];

const allocationItem = ({ attributes }: ServerAllocation): ServerAllocationItem => ({
    id: attributes.id,
    ip: attributes.ip,
    alias: attributes.ip_alias,
    address: attributes.ip_alias || ip(attributes.ip),
    port: attributes.port,
    notes: attributes.notes,
    primary: attributes.is_default,
});

function useAllocationsQuery() {
    const server = useCurrentServer();

    return { server, query: useServerAllocations(server?.attributes.uuid ?? '') };
}

const useNetworkPageModel = () => pageStatus(useAllocationsQuery().query);

function useServerAllocationsModel(): ServerAllocationsModel {
    const { server, query } = useAllocationsQuery();
    const uuid = server?.attributes.uuid ?? '';
    const limit = server?.attributes.feature_limits.allocations ?? 0;
    const [create, update, remove, rename] = usePermissions([
        'allocation.create',
        'allocation.update',
        'allocation.delete',
        'settings.rename',
    ]);
    const { data: hostname } = useServerMinecraftHostname(uuid);
    const { mutateAsync: renameHostname } = useUpdateServerMinecraftHostname(uuid);
    const { mutateAsync: createAllocation } = useCreateServerAllocation();
    const { mutateAsync: setPrimary } = useSetPrimaryAllocation();
    const { mutateAsync: setNotes } = useUpdateAllocationNotes();
    const { mutateAsync: deleteAllocation } = useDeleteServerAllocation();
    const listed = query.data?.data ?? noAllocations;
    const tunnel = server ? playitAddress(server.attributes) : null;
    const pending = server ? addressPending(server.attributes) : false;
    const loading = query.data === undefined;

    return useMemo(() => {
        const allocations = listed.map(allocationItem);
        const canManage = create && limit > 0;

        return {
            allocations,
            playitAddress: tunnel,
            minecraftHostname:
                hostname?.routed && hostname.hostname && hostname.domain
                    ? {
                          hostname: hostname.hostname,
                          domain: hostname.domain,
                          address: hostname.address,
                          canEdit: rename,
                          rename: async (name: string) => {
                              await renameHostname(updateServerMinecraftHostnameInput(uuid, name));
                          },
                      }
                    : null,
            addressPending: pending,
            loading,
            limit,
            permissions: { create, update, delete: remove },
            canManage,
            canCreate: canManage && limit > allocations.length,
            actions: {
                create: async () => {
                    await createAllocation(createServerAllocationInput(uuid));
                },
                setPrimary: async (id) => {
                    await setPrimary(setPrimaryAllocationInput(uuid, id));
                },
                setNotes: async (id, notes) => {
                    await setNotes(updateAllocationNotesInput(uuid, id, notes));
                },
                remove: async (id) => {
                    await deleteAllocation(deleteServerAllocationInput(uuid, id));
                },
            },
        };
    }, [
        listed,
        tunnel,
        hostname,
        rename,
        renameHostname,
        pending,
        loading,
        limit,
        create,
        update,
        remove,
        createAllocation,
        setPrimary,
        setNotes,
        deleteAllocation,
        uuid,
    ]);
}

const resolved = async () => {};

const allocation = (id: number, port: number, options: Partial<ServerAllocationItem> = {}): ServerAllocationItem => ({
    id,
    ip: '203.0.113.10',
    alias: null,
    address: '203.0.113.10',
    port,
    notes: null,
    primary: false,
    ...options,
});

const allocationsFixture = (options: Partial<ServerAllocationsModel> = {}): ServerAllocationsModel => ({
    allocations: [
        allocation(1, 25565, { primary: true, notes: 'Game port' }),
        allocation(2, 25575, { notes: 'RCON' }),
        allocation(3, 8123, { alias: 'play.example.com', address: 'play.example.com' }),
    ],
    playitAddress: null,
    loading: false,
    limit: 5,
    permissions: { create: true, update: true, delete: true },
    canManage: true,
    canCreate: true,
    actions: { create: resolved, setPrimary: resolved, setNotes: resolved, remove: resolved },
    ...options,
});

implementViews({
    'page.server.network': {
        use: useNetworkPageModel,
        fixtures: pageStatusFixtures,
    },
    'server.allocations': {
        use: useServerAllocationsModel,
        fixtures: {
            allocations: () => allocationsFixture(),
            playit: () => allocationsFixture({ playitAddress: 'xyz.joinmc.link' }),
            entrance: () =>
                allocationsFixture({
                    playitAddress: 'survival.play.example.com',
                    minecraftHostname: {
                        hostname: 'survival',
                        domain: 'play.example.com',
                        address: 'survival.play.example.com',
                        canEdit: true,
                        rename: resolved,
                    },
                }),
            full: () => allocationsFixture({ limit: 3, canCreate: false }),
            unmanaged: () => allocationsFixture({ limit: 0, canManage: false, canCreate: false }),
            readOnly: () =>
                allocationsFixture({
                    permissions: { create: false, update: false, delete: false },
                    canManage: false,
                    canCreate: false,
                }),
            empty: () => allocationsFixture({ allocations: [] }),
            loading: () => allocationsFixture({ allocations: [], loading: true }),
        },
    },
});
