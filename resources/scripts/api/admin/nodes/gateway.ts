import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';

import {
    adminCheckNodeGameGatewayPortsMutation,
    adminGetNodeGameGatewayOptions,
    adminGetNodeGameGatewayQueryKey,
    adminUpdateNodeGameGatewayMutation,
} from '@/api/generated/@tanstack/react-query.gen';
import type {
    AdminCheckNodeGameGatewayPortsResponse,
    AdminGetNodeGameGatewayResponse,
    AdminUpdateNodeGameGatewayData,
    Options,
} from '@/api/generated';
import { notifyHttpError } from '@/plugins/notifications';

export type NodeGateway = AdminGetNodeGameGatewayResponse;
export type NodeGatewayCheck = AdminCheckNodeGameGatewayPortsResponse;
export type NodeGatewayValues = AdminUpdateNodeGameGatewayData['body'];

/** The script's limits, mirrored from GatewayPorts.php so the form can say what is wrong before saving. */
export const GATEWAY_MAX_RANGES = 7;
export const GATEWAY_MIN_PORT = 1024;
export const GATEWAY_MAX_PORT = 65535;
export const GATEWAY_WIREGUARD_PORT = 51820;

export type GatewayPortsResult = { ok: true; ranges: [number, number][] } | { ok: false; error: string };

const portsError = (error: string): GatewayPortsResult => ({ ok: false, error });

/** The ports as [from, to] pairs, or why they are refused: the same rules as gateway.sh --ports. */
export function parseGatewayPorts(list: string): GatewayPortsResult {
    const parts = list.trim() === '' ? [] : list.split(',').map((part) => part.trim());

    if (parts.length === 0 || parts.length > GATEWAY_MAX_RANGES) {
        return portsError(`Enter 1 to ${GATEWAY_MAX_RANGES} ports or ranges separated by commas, such as 25565-25700.`);
    }

    const ranges: [number, number][] = [];

    for (const part of parts) {
        const match = /^(\d{1,5})(?:-(\d{1,5}))?$/.exec(part);

        if (!match) {
            return portsError(`"${part}" is not a port or a range such as 25565-25700.`);
        }

        const from = Number(match[1]);
        const to = match[2] === undefined ? from : Number(match[2]);

        if (from < GATEWAY_MIN_PORT || to > GATEWAY_MAX_PORT || from > to) {
            return portsError(
                `Ports must be between ${GATEWAY_MIN_PORT} and ${GATEWAY_MAX_PORT}, the lower one first: ${part}.`
            );
        }

        if (from <= GATEWAY_WIREGUARD_PORT && to >= GATEWAY_WIREGUARD_PORT) {
            return portsError(`The range ${part} includes the WireGuard port ${GATEWAY_WIREGUARD_PORT}.`);
        }

        ranges.push([from, to]);
    }

    return { ok: true, ranges };
}

/** Whether a port is inside one of the ranges. */
export const isForwarded = (ranges: readonly (readonly number[])[], port: number): boolean =>
    ranges.some(([from, to]) => from !== undefined && to !== undefined && port >= from && port <= to);

/** The ranges of a node in gateway mode, or null when its ports are not forwarded by a gateway. */
export const activeGatewayRanges = (gateway: NodeGateway | undefined): NodeGateway['ranges'] | null =>
    gateway?.active ? gateway.ranges : null;

/**
 * Allocation choices with the ports a node's game gateway forwards first and the others marked
 * "not forwarded", so a new server there is reachable by players. Unchanged without a gateway.
 */
export function gatewayAllocationOptions<T extends { label: string; port: number }>(
    options: readonly T[],
    gateway: NodeGateway | undefined
): T[] {
    const ranges = activeGatewayRanges(gateway);

    if (!ranges) {
        return [...options];
    }

    const forwarded = options.filter((option) => isForwarded(ranges, option.port));
    const others = options
        .filter((option) => !isForwarded(ranges, option.port))
        .map((option) => Object.assign({}, option, { label: `${option.label} (not forwarded)` }));

    return [...forwarded, ...others];
}

const gatewayInput = (nodeId: number) => ({ path: { node_id: nodeId } });

export const adminNodeGatewayQueryOptions = (nodeId: number) => adminGetNodeGameGatewayOptions(gatewayInput(nodeId));

/** The node's gateway; quiet on errors, as it also marks allocations for administrators who may not manage nodes. */
export const useAdminNodeGateway = (nodeId: number, enabled = true) =>
    useQuery({ ...adminNodeGatewayQueryOptions(nodeId), enabled: enabled && nodeId > 0, retry: false });

export const updateAdminNodeGatewayInput = (
    nodeId: number,
    values: NodeGatewayValues
): Options<AdminUpdateNodeGameGatewayData> => ({ ...gatewayInput(nodeId), body: values });

export const useUpdateAdminNodeGateway = (nodeId: number) => {
    const queryClient = useQueryClient();

    return useMutation({
        ...adminUpdateNodeGameGatewayMutation(),
        onSuccess: (gateway) => {
            queryClient.setQueryData(adminGetNodeGameGatewayQueryKey(gatewayInput(nodeId)), gateway);
            toast.success(gateway.active ? 'Game gateway on' : 'Game gateway saved', {
                description: gateway.active
                    ? 'Players join through the gateway; playit.gg tunnels are paused on this node.'
                    : undefined,
            });
        },
        onError: (error) => notifyHttpError(error, 'Unable to save the game gateway'),
    });
};

export const useCheckAdminNodeGateway = () =>
    useMutation({
        ...adminCheckNodeGameGatewayPortsMutation(),
        onError: (error) => notifyHttpError(error, 'Unable to check the allocations'),
    });

export const checkAdminNodeGatewayInput = (nodeId: number, ports: string | null) => ({
    ...gatewayInput(nodeId),
    body: ports === null ? {} : { ports },
});
