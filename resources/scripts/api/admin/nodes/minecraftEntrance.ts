import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';

import {
    adminApplyMinecraftEntranceProxyProtocolMutation,
    adminGetNodeMinecraftEntranceOptions,
    adminGetNodeMinecraftEntranceQueryKey,
    adminUpdateNodeMinecraftEntranceMutation,
} from '@/api/generated/@tanstack/react-query.gen';
import type {
    AdminApplyMinecraftEntranceProxyProtocolResponse,
    AdminGetNodeMinecraftEntranceResponse,
    AdminUpdateNodeMinecraftEntranceData,
    Options,
} from '@/api/generated';
import { notifyHttpError } from '@/plugins/notifications';

export type NodeMinecraftEntrance = AdminGetNodeMinecraftEntranceResponse;
export type NodeMinecraftEntranceValues = AdminUpdateNodeMinecraftEntranceData['body'];
export type ProxyProtocolResult = AdminApplyMinecraftEntranceProxyProtocolResponse;

const entranceInput = (nodeId: number) => ({ path: { node_id: nodeId } });

export const useAdminNodeMinecraftEntrance = (nodeId: number) =>
    useQuery({ ...adminGetNodeMinecraftEntranceOptions(entranceInput(nodeId)), retry: false });

export const updateAdminNodeMinecraftEntranceInput = (
    nodeId: number,
    values: NodeMinecraftEntranceValues
): Options<AdminUpdateNodeMinecraftEntranceData> => ({ ...entranceInput(nodeId), body: values });

export const useUpdateAdminNodeMinecraftEntrance = (nodeId: number) => {
    const queryClient = useQueryClient();

    return useMutation({
        ...adminUpdateNodeMinecraftEntranceMutation(),
        onSuccess: (entrance) => {
            queryClient.setQueryData(adminGetNodeMinecraftEntranceQueryKey(entranceInput(nodeId)), entrance);
            toast.success(entrance.active ? 'Minecraft entrance on' : 'Minecraft entrance saved', {
                description: entrance.example_address ? `Players join as ${entrance.example_address}` : undefined,
            });
        },
        onError: (error) => notifyHttpError(error, 'Unable to save the Minecraft entrance'),
    });
};

export const applyProxyProtocolInput = (nodeId: number) => entranceInput(nodeId);

export const useApplyProxyProtocol = () =>
    useMutation({
        ...adminApplyMinecraftEntranceProxyProtocolMutation(),
        onSuccess: (result) =>
            toast.success('PROXY protocol applied', {
                description: `${result.applied} now get player IPs, ${result.restart} need a restart, ${result.unsupported} cannot use it.`,
            }),
        onError: (error) => notifyHttpError(error, 'Unable to apply PROXY protocol'),
    });

/** A node-local port the proxy may listen on: 1024 to 65535, not WireGuard's. */
export function proxyPortError(port: number): string | undefined {
    if (!Number.isInteger(port) || port < 1024 || port > 65535 || port === 51820) {
        return 'Use a port between 1024 and 65535, not 51820.';
    }

    return undefined;
}
