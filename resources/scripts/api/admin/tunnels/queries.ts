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
    /** playit refused to create a tunnel with this key: tunnels are made in the playit dashboard and linked. */
    read_only: boolean;
}

/** The text shown wherever a node's agent key is read-only. */
export const READ_ONLY_KEY_MESSAGE =
    'This agent key is read-only: create tunnels in the playit dashboard; the panel links them automatically.';

export interface UnlinkedTunnelServer {
    server_id: number;
    server_name: string;
    server_identifier: string;
    node_id: number;
    node_name: string;
    /** The local IP and port the playit tunnel must point at. */
    local_ip: string;
    local_port: number;
    tunnel_status: TunnelStatus | null;
}

export interface UnlinkedTunnels {
    nodes: { id: number; name: string; read_only: boolean }[];
    servers: UnlinkedTunnelServer[];
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

/** Servers on playit.gg nodes that still need a tunnel, with the local port to point one at. */
export const adminUnlinkedTunnelsQueryOptions = () =>
    queryOptions({
        queryKey: [...tunnelsKey, 'unlinked'],
        queryFn: async ({ signal }): Promise<UnlinkedTunnels> =>
            (await http.get<UnlinkedTunnels>('/api/admin/tunnels/unlinked', { signal })).data,
    });

export const useUnlinkedTunnels = () => useQuery({ ...adminUnlinkedTunnelsQueryOptions(), refetchInterval: 60_000 });

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

// "Set up automatic tunnels" (phase 7 Task A): the node moves to a self-managed playit.gg agent.

export type PlayitClaimStatus =
    | 'waiting_for_visit'
    | 'waiting_for_user'
    | 'accepted'
    | 'rejected'
    | 'expired'
    | 'ready'
    | 'switched';

export interface PlayitOldTunnel {
    id: number;
    playit_tunnel_id: string;
    name: string | null;
    address: string | null;
    local_port: number | null;
    server_id: number | null;
    server_name: string | null;
}

/** Where the switch stands. Never contains the agent secret or the switch token. */
export interface PlayitSwitchState {
    mode: 'read_only' | 'self_managed' | null;
    self_managed: boolean;
    switched_at: string | null;
    can_undo: boolean;
    claim: {
        status: PlayitClaimStatus;
        /** Only while the claim waits on playit.gg. */
        claim_url: string | null;
        expires_at: string | null;
        agent_id: string | null;
        /** Set while a switch token was created and not used yet. */
        token_expires_at: string | null;
        fetched_at: string | null;
        report: 'switched' | 'rolled_back' | null;
        reported_at: string | null;
        last_error: string | null;
    } | null;
    old_tunnels: PlayitOldTunnel[];
    old_tunnels_removed: number;
    script: { url: string; sha256: string | null };
}

export interface PlayitSwitchToken {
    token: string;
    expires_at: string;
}

export interface PlayitOldTunnelRemoval {
    removed: number;
    remaining: number;
    /** playit only lets the old key read: delete the rest in the playit dashboard. */
    manual: boolean;
    message: string;
}

const playitSwitchKey = (nodeId: number) => ['admin', 'nodes', nodeId, 'playit', 'switch'] as const;

const isWaiting = (state: PlayitSwitchState | undefined): boolean =>
    state?.claim?.status === 'waiting_for_visit' ||
    state?.claim?.status === 'waiting_for_user' ||
    state?.claim?.status === 'accepted';

/** True while the node's script may be running: a token was made, or the secret was fetched and nothing reported yet. */
const awaitingScript = (state: PlayitSwitchState | undefined): boolean =>
    state?.claim?.status === 'ready' &&
    !state.self_managed &&
    (state.claim.token_expires_at !== null || (state.claim.fetched_at !== null && state.claim.report === null));

export const playitSwitchWaits = { isWaiting, awaitingScript };

export const adminPlayitSwitchQueryOptions = (nodeId: number) =>
    queryOptions({
        queryKey: playitSwitchKey(nodeId),
        queryFn: async ({ signal }): Promise<PlayitSwitchState> =>
            (await http.get<PlayitSwitchState>(`/api/admin/nodes/${nodeId}/playit/switch`, { signal })).data,
    });

/** The switch state; refreshed every 5 s while the node's script may be reporting. */
export const usePlayitSwitch = (nodeId: number) =>
    useQuery({
        ...adminPlayitSwitchQueryOptions(nodeId),
        refetchInterval: (query) => (awaitingScript(query.state.data) ? 5_000 : false),
    });

/** Polls playit.gg (through the panel) every 3 s while the claim waits to be accepted. */
export const usePlayitClaimPolling = (nodeId: number, state: PlayitSwitchState | undefined) => {
    const queryClient = useQueryClient();

    return useQuery({
        queryKey: [...playitSwitchKey(nodeId), 'check'],
        queryFn: async (): Promise<PlayitSwitchState> => {
            const next = (await http.post<PlayitSwitchState>(`/api/admin/nodes/${nodeId}/playit/switch/check`)).data;

            queryClient.setQueryData(playitSwitchKey(nodeId), next);

            return next;
        },
        enabled: isWaiting(state),
        refetchInterval: 3_000,
        refetchOnWindowFocus: false,
        retry: false,
    });
};

const usePlayitSwitchAction = (nodeId: number, path: string, done: string | null, failed: string) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async () => (await http.post<PlayitSwitchState>(`/api/admin/nodes/${nodeId}/${path}`)).data,
        onSuccess: async (state) => {
            queryClient.setQueryData(playitSwitchKey(nodeId), state);
            await queryClient.invalidateQueries({ queryKey: nodePlayitKey(nodeId) });
            await queryClient.invalidateQueries({ queryKey: tunnelsKey });
            if (done !== null) {
                toast.success(done);
            }
        },
        onError: (error) => notifyHttpError(error, failed),
    });
};

export const useStartPlayitClaim = (nodeId: number) =>
    usePlayitSwitchAction(nodeId, 'playit/switch', null, 'Unable to start the playit.gg claim');

export const useFinishPlayitSwitch = (nodeId: number) =>
    usePlayitSwitchAction(
        nodeId,
        'playit/switch/finish',
        'The panel now uses the new agent',
        'Unable to finish the switch'
    );

export const useUndoPlayitSwitch = (nodeId: number) =>
    usePlayitSwitchAction(nodeId, 'playit/switch/undo', 'The previous agent is back', 'Unable to undo the switch');

/** What "Check status" found and fixed. */
export interface PlayitReconcile {
    action: 'none' | 'finished' | 'undone' | 'agent_corrected';
    message: string;
    agent_online: boolean | null;
    tunnels: number;
    created: number;
    active: number;
    waiting_for_agent: number;
    last_error: string | null;
}

/** "Check status": brings the panel in line with the agent the node really runs. */
export const useReconcilePlayitSwitch = (nodeId: number) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async () =>
            (
                await http.post<PlayitSwitchState & { reconcile: PlayitReconcile }>(
                    `/api/admin/nodes/${nodeId}/playit/switch/reconcile`
                )
            ).data,
        onSuccess: async ({ reconcile, ...state }) => {
            queryClient.setQueryData(playitSwitchKey(nodeId), state);
            await queryClient.invalidateQueries({ queryKey: nodePlayitKey(nodeId) });
            await queryClient.invalidateQueries({ queryKey: tunnelsKey });
            if (reconcile.agent_online === false) {
                toast.warning(reconcile.message);
            } else {
                toast.success(reconcile.message);
            }
        },
        onError: (error) => notifyHttpError(error, 'Unable to check the playit.gg status'),
    });
};

export const useDismissOldTunnels = (nodeId: number) =>
    usePlayitSwitchAction(
        nodeId,
        'playit/old-tunnels/dismiss',
        'Old tunnels dismissed',
        'Unable to dismiss the old tunnels'
    );

/** A one-time token for the node's switch script, shown once. */
export const useCreatePlayitSwitchToken = (nodeId: number) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async () =>
            (await http.post<PlayitSwitchToken>(`/api/admin/nodes/${nodeId}/playit/switch/token`)).data,
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: playitSwitchKey(nodeId) });
        },
        onError: (error) => notifyHttpError(error, 'Unable to create the switch token'),
    });
};

export const useRemoveOldTunnels = (nodeId: number) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async () =>
            (await http.post<PlayitOldTunnelRemoval>(`/api/admin/nodes/${nodeId}/playit/old-tunnels/remove`)).data,
        onSuccess: async (result) => {
            await queryClient.invalidateQueries({ queryKey: playitSwitchKey(nodeId) });
            if (result.manual && result.remaining > 0) {
                toast.warning('Some old tunnels are left', { description: result.message });
            } else {
                toast.success('Old tunnels removed', { description: result.message });
            }
        },
        onError: (error) => notifyHttpError(error, 'Unable to remove the old tunnels'),
    });
};
