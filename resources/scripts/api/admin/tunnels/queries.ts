// playit.gg tunnels (phase 3 Task V), written by hand until the generated API client is regenerated
// with /api/admin/tunnels and /api/admin/nodes/{node}/playit.
/* oxlint-disable no-restricted-properties, rules/generated-api-mutations */
import { keepPreviousData, queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import http from '@/api/http';
import { notifyHttpError } from '@/plugins/notifications';

export type TunnelStatus = 'pending' | 'active' | 'failed' | 'deleted';

export interface ServerTunnel {
    object: 'server_tunnel';
    attributes: {
        id: number;
        server_id: number;
        server_name: string | null;
        server_identifier: string | null;
        node_id: number | null;
        node_name: string | null;
        playit_tunnel_id: string | null;
        address: string | null;
        subdomain: string | null;
        status: TunnelStatus;
        last_error: string | null;
        /** Set when the tunnel was made by hand on playit.gg and linked by an import. */
        imported_at: string | null;
        created_at: string;
        updated_at: string;
    };
}

export interface ServerTunnelList {
    object: 'list';
    data: ServerTunnel[];
    meta: { pagination: { total: number; per_page: number; current_page: number; total_pages: number } };
}

export interface NodePlayitSettings {
    agent_id: string | null;
    secret_set: boolean;
}

export interface NodePlayitInput {
    agent_id: string | null;
    /** Blank keeps the stored secret. */
    secret: string;
}

export interface NodePlayitTest {
    success: boolean;
    message: string;
    tunnel_count: number | null;
}

export type TunnelImportOutcome =
    | 'imported'
    | 'already_linked'
    | 'server_has_tunnel'
    | 'no_match'
    | 'ambiguous'
    | 'unsupported';

export interface TunnelImportResult {
    node_id: number;
    node_name: string;
    playit_tunnel_id: string | null;
    name: string | null;
    local_ip: string | null;
    local_port: number | null;
    address: string | null;
    result: TunnelImportOutcome;
    reason: string;
    server_ids: number[];
}

export interface TunnelImportSummary {
    imported: number;
    skipped: number;
    errors: { node_id: number; node_name: string; message: string }[];
    results: TunnelImportResult[];
}

export interface TunnelSettings {
    subdomains_enabled: boolean;
    base_domain: string;
    blocklist: string[];
}

const tunnelsKey = ['admin', 'tunnels'] as const;
const nodePlayitKey = (nodeId: number) => ['admin', 'nodes', nodeId, 'playit'] as const;

export const adminTunnelsQueryOptions = (page = 1, status?: TunnelStatus) =>
    queryOptions({
        queryKey: [...tunnelsKey, 'list', { page, status }],
        queryFn: async ({ signal }): Promise<ServerTunnelList> =>
            (
                await http.get<ServerTunnelList>('/api/admin/tunnels', {
                    // axios leaves out an undefined status.
                    params: { page, per_page: 50, status },
                    signal,
                })
            ).data,
        placeholderData: keepPreviousData,
    });

export const useAdminTunnels = (page = 1, status?: TunnelStatus) =>
    useQuery({ ...adminTunnelsQueryOptions(page, status), refetchInterval: 15_000 });

const useTunnelAction = (action: 'retry' | 'recreate', done: string) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (id: number) => (await http.post<ServerTunnel>(`/api/admin/tunnels/${id}/${action}`)).data,
        onSuccess: async (tunnel) => {
            await queryClient.invalidateQueries({ queryKey: tunnelsKey });
            const { status, last_error: error } = tunnel.attributes;

            if (status === 'failed') {
                toast.error('playit.gg tunnel failed', { description: error ?? undefined });
            } else {
                toast.success(done, {
                    description:
                        status === 'active' ? tunnel.attributes.address : 'Waiting for playit.gg to assign an address.',
                });
            }
        },
        onError: (error) => notifyHttpError(error, `Unable to ${action} the tunnel`),
    });
};

export const useRetryTunnel = () => useTunnelAction('retry', 'Tunnel checked');

export const useRecreateTunnel = () => useTunnelAction('recreate', 'Tunnel recreated');

export const useCreateTunnel = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (serverId: number) =>
            (await http.post<ServerTunnel>('/api/admin/tunnels', { server_id: serverId })).data,
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: tunnelsKey });
            toast.success('Tunnel created');
        },
        onError: (error) => notifyHttpError(error, 'Unable to create the tunnel'),
    });
};

/** Links tunnels made by hand on playit.gg to the servers on their ports. */
export const useImportTunnels = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (nodeId?: number) =>
            (
                await http.post<TunnelImportSummary>('/api/admin/tunnels/import', undefined, {
                    params: nodeId === undefined ? undefined : { node: nodeId },
                })
            ).data,
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: tunnelsKey });
        },
        onError: (error) => notifyHttpError(error, 'Unable to import the tunnels'),
    });
};

const tunnelSettingsKey = [...tunnelsKey, 'settings'] as const;

export const adminTunnelSettingsQueryOptions = () =>
    queryOptions({
        queryKey: tunnelSettingsKey,
        queryFn: async ({ signal }): Promise<TunnelSettings> =>
            (await http.get<TunnelSettings>('/api/admin/tunnels/settings', { signal })).data,
    });

export const useAdminTunnelSettings = () => useQuery(adminTunnelSettingsQueryOptions());

export const useUpdateTunnelSettings = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (body: TunnelSettings) =>
            (await http.put<TunnelSettings>('/api/admin/tunnels/settings', body)).data,
        onSuccess: (settings) => {
            queryClient.setQueryData(tunnelSettingsKey, settings);
            toast.success('Tunnel settings updated');
        },
        onError: (error) => notifyHttpError(error, 'Unable to update tunnel settings'),
    });
};

export const adminNodePlayitQueryOptions = (nodeId: number) =>
    queryOptions({
        queryKey: nodePlayitKey(nodeId),
        queryFn: async ({ signal }): Promise<NodePlayitSettings> =>
            (await http.get<NodePlayitSettings>(`/api/admin/nodes/${nodeId}/playit`, { signal })).data,
    });

export const useAdminNodePlayit = (nodeId: number) => useQuery(adminNodePlayitQueryOptions(nodeId));

export const useUpdateAdminNodePlayit = (nodeId: number) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (body: NodePlayitInput) =>
            (await http.put<NodePlayitSettings>(`/api/admin/nodes/${nodeId}/playit`, body)).data,
        onSuccess: (settings) => {
            queryClient.setQueryData(nodePlayitKey(nodeId), settings);
            toast.success('playit.gg agent saved');
        },
        onError: (error) => notifyHttpError(error, 'Unable to save the playit.gg agent'),
    });
};

export const useTestAdminNodePlayit = (nodeId: number) =>
    useMutation({
        mutationFn: async () => (await http.post<NodePlayitTest>(`/api/admin/nodes/${nodeId}/playit/test`)).data,
        onSuccess: (result) =>
            result.success
                ? toast.success('playit.gg connection works', { description: result.message })
                : toast.error('playit.gg connection failed', { description: result.message }),
        onError: (error) => notifyHttpError(error, 'Unable to test the playit.gg agent'),
    });
