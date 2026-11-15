import type { ShopAddonResource, ShopResources } from '@/api/admin/shop/queries';
import { bytesToString, mbToBytes } from '@/lib/formatters';

export const formatMegabytes = (megabytes: number): string => bytesToString(mbToBytes(megabytes));

/** 100% is one thread. */
export const formatCpu = (percent: number): string => {
    const threads = percent / 100;

    return `${percent}% (${Number(threads.toFixed(2))} ${threads === 1 ? 'thread' : 'threads'})`;
};

export const addonResourceLabels = {
    memory: 'Memory',
    disk: 'Disk',
    cpu: 'CPU',
    backups: 'Backups',
    databases: 'Databases',
    allocations: 'Extra ports',
} satisfies Record<ShopAddonResource, string>;

/** "+1 GB memory", "+2 backups". */
export const formatAddonAmount = (resource: ShopAddonResource, amount: number): string => {
    switch (resource) {
        case 'memory':
            return `+${formatMegabytes(amount)} memory`;
        case 'disk':
            return `+${formatMegabytes(amount)} disk`;
        case 'cpu':
            return `+${amount}% CPU`;
        case 'allocations':
            return `+${amount} ${amount === 1 ? 'port' : 'ports'}`;
        case 'backups':
            return `+${amount} ${amount === 1 ? 'backup' : 'backups'}`;
        case 'databases':
            return `+${amount} ${amount === 1 ? 'database' : 'databases'}`;
    }
};

/** A plan's resources as short chips: "2 GB RAM", "10 GB disk", "100% CPU", "2 backups". */
export const resourceSummary = (resources: ShopResources): string[] => [
    `${formatMegabytes(resources.memory)} RAM`,
    `${formatMegabytes(resources.disk)} disk`,
    `${resources.cpu}% CPU`,
    ...(resources.backups > 0 ? [`${resources.backups} ${resources.backups === 1 ? 'backup' : 'backups'}`] : []),
    ...(resources.databases > 0
        ? [`${resources.databases} ${resources.databases === 1 ? 'database' : 'databases'}`]
        : []),
    ...(resources.allocations > 0
        ? [`+${resources.allocations} ${resources.allocations === 1 ? 'port' : 'ports'}`]
        : []),
];

/** Lower-case, dash-separated slug of a name. */
export const slugify = (name: string): string =>
    name
        .toLowerCase()
        .normalize('NFKD')
        .replaceAll(/[^a-z0-9]+/g, '-')
        .replaceAll(/^-+|-+$/g, '')
        .slice(0, 191);
