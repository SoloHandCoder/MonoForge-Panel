import type { AdminNodeUsage } from '@/api/admin/insights/queries';

export interface OverviewTotals {
    nodesOnline: number;
    nodesTotal: number;
    serversOnline: number;
    serversTotal: number;
    /** CPU in use across reachable nodes, 100 per thread. */
    cpuUsed: number;
    /** Threads times 100 on reachable nodes that report them; null when none do. */
    cpuCapacity: number | null;
    memoryAllocated: number;
    memoryCapacity: number;
    diskAllocated: number;
    diskCapacity: number;
}

/** Sums the live usage of every node into the overview's headline numbers. */
export function overviewTotals(nodes: readonly AdminNodeUsage[]): OverviewTotals {
    const online = nodes.filter((node) => node.status === 'online');
    const capacities = online.map((node) => node.cpu.capacity).filter((capacity) => capacity !== null);
    const sum = (values: number[]) => values.reduce((total, value) => total + value, 0);

    return {
        nodesOnline: online.length,
        nodesTotal: nodes.length,
        serversOnline: sum(nodes.map((node) => node.servers_online)),
        serversTotal: sum(nodes.map((node) => node.servers_total)),
        cpuUsed: sum(online.map((node) => node.cpu.used)),
        cpuCapacity: capacities.length > 0 ? sum(capacities) : null,
        memoryAllocated: sum(nodes.map((node) => node.memory.allocated_bytes)),
        memoryCapacity: sum(nodes.map((node) => node.memory.capacity_bytes)),
        diskAllocated: sum(nodes.map((node) => node.disk.allocated_bytes)),
        diskCapacity: sum(nodes.map((node) => node.disk.capacity_bytes)),
    };
}
