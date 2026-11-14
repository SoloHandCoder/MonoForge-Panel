import type { AdminLiveUsage, AdminStorageUsage } from '@/api/admin/insights/queries';

const GIB = 1_073_741_824;

/** One busy node and one Wings never answered for, as the usage endpoint returns them. */
export const liveUsageFixture: AdminLiveUsage = {
    nodes: [
        {
            id: 1,
            name: 'Alpha',
            status: 'online',
            cpu: { used: 250, capacity: 2100 },
            memory: { used_bytes: 3 * GIB, allocated_bytes: 6 * GIB, capacity_bytes: 32 * GIB },
            disk: { used_bytes: 20 * GIB, allocated_bytes: 50 * GIB, capacity_bytes: 500 * GIB },
            servers_online: 1,
            servers_total: 2,
            servers: [
                {
                    id: 11,
                    name: 'Idle',
                    state: 'offline',
                    cpu: 0,
                    cpu_limit: null,
                    memory_bytes: 0,
                    memory_limit_bytes: 2 * GIB,
                    disk_bytes: GIB,
                    disk_limit_bytes: null,
                },
                {
                    id: 12,
                    name: 'Busy',
                    state: 'running',
                    cpu: 250,
                    cpu_limit: 400,
                    memory_bytes: 3 * GIB,
                    memory_limit_bytes: 4 * GIB,
                    disk_bytes: 19 * GIB,
                    disk_limit_bytes: 40 * GIB,
                },
            ],
        },
        {
            id: 2,
            name: 'Bravo',
            status: 'offline',
            cpu: { used: 0, capacity: null },
            memory: { used_bytes: 0, allocated_bytes: GIB, capacity_bytes: 16 * GIB },
            disk: { used_bytes: null, allocated_bytes: 10 * GIB, capacity_bytes: 250 * GIB },
            servers_online: 0,
            servers_total: 1,
            servers: [
                {
                    id: 21,
                    name: 'Stranded',
                    state: 'offline',
                    cpu: 0,
                    cpu_limit: 100,
                    memory_bytes: 0,
                    memory_limit_bytes: GIB,
                    disk_bytes: null,
                    disk_limit_bytes: 10 * GIB,
                },
            ],
        },
    ],
};

export const storageUsageFixture: AdminStorageUsage = {
    nodes: [
        {
            id: 1,
            name: 'Alpha',
            status: 'online',
            used_bytes: 20 * GIB,
            allocated_bytes: 50 * GIB,
            capacity_bytes: 500 * GIB,
            servers: [{ id: 12, name: 'Busy', used_bytes: 19 * GIB, limit_bytes: 40 * GIB }],
        },
        {
            id: 2,
            name: 'Bravo',
            status: 'offline',
            used_bytes: null,
            allocated_bytes: 10 * GIB,
            capacity_bytes: 250 * GIB,
            servers: [{ id: 21, name: 'Stranded', used_bytes: null, limit_bytes: 10 * GIB }],
        },
    ],
    website: {
        database_bytes: 50 * 1_048_576,
        storage_bytes: null,
        local_backups_bytes: 2 * GIB,
        local_backups_count: 1,
        remote_backups_bytes: 0,
        remote_backups_count: 0,
    },
};
