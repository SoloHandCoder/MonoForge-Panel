import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import {
    Activity,
    ArrowRight,
    Code2,
    Cpu,
    HardDrive,
    History,
    MemoryStick,
    Megaphone,
    Network,
    Server,
    Settings,
    Users,
    type LucideIcon,
} from 'lucide-react';
import { httpErrorToHuman } from '@/api/http';
import { adminActivityLogsQueryOptions } from '@/api/admin/activity/queries';
import { useAdminLiveUsage, type AdminNodeUsage } from '@/api/admin/insights/queries';
import { useAdminUsers } from '@/api/admin/users/queries';
import { useAdminVersion } from '@/api/admin/version/queries';
import { relationshipAttributes } from '@/api/relationships';
import AdminContentBlock from '@/components/admin/AdminContentBlock';
import { cpuText, usagePercent, usageTone } from '@/components/admin/insights/usageFormat';
import OverviewVersionCard from '@/components/admin/overview/OverviewVersionCard';
import { overviewTotals } from '@/components/admin/overview/overviewTotals';
import { REPOSITORY_URL } from '@/components/admin/overview/repository';
import Card from '@/components/elements/Card';
import Icon from '@/components/elements/Icon';
import ProgressBar from '@/components/elements/ProgressBar';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import Stat from '@/components/elements/Stat';
import { interactiveSurfaceClass } from '@/components/ui/styles';
import Slot from '@/extensions/Slot';
import { fromNow } from '@/lib/dates';
import { bytesRatioToString } from '@/lib/formatters';
import { cn } from '@/lib/cn';

const LATEST_ACTIVITY = 5;

const quickLinkClass = cn(
    'flex items-center gap-3 rounded-sm border border-border bg-card px-4 py-3 text-sm font-medium text-foreground no-underline hover:text-accent',
    interactiveSurfaceClass
);

function QuickLinkBody({ icon, label }: { icon: LucideIcon; label: string }) {
    return (
        <>
            <Icon icon={icon} className='h-4 w-4 text-accent' />
            <span className='flex-1'>{label}</span>
            <Icon icon={ArrowRight} className='h-4 w-4 text-muted-foreground' />
        </>
    );
}

function QuickLinks() {
    return (
        <div className='grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4'>
            {/* Announcements is built alongside this page; a plain link keeps it working before its route lands. */}
            <a href='/panel/announcements' className={quickLinkClass}>
                <QuickLinkBody icon={Megaphone} label='Announcements' />
            </a>
            <Link to='/panel/usage' className={quickLinkClass}>
                <QuickLinkBody icon={Activity} label='Live usage' />
            </Link>
            <Link to='/panel/storage' className={quickLinkClass}>
                <QuickLinkBody icon={HardDrive} label='Storage' />
            </Link>
            <Link to='/panel/settings' className={quickLinkClass}>
                <QuickLinkBody icon={Settings} label='Settings' />
            </Link>
        </div>
    );
}

function StatTiles({ nodes, users }: { nodes: AdminNodeUsage[] | undefined; users: number | undefined }) {
    const totals = nodes ? overviewTotals(nodes) : null;
    const loading = '…';

    const cpuPercent = totals ? usagePercent(totals.cpuUsed, totals.cpuCapacity) : null;
    const memoryPercent = totals ? usagePercent(totals.memoryAllocated, totals.memoryCapacity) : null;
    const diskPercent = totals ? usagePercent(totals.diskAllocated, totals.diskCapacity) : null;

    return (
        <div className='mb-6 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3'>
            <Stat label='Users' icon={Users} value={users ?? loading} />
            <Stat
                label='Servers online'
                icon={Server}
                value={totals ? totals.serversOnline : loading}
                limit={totals ? `/ ${totals.serversTotal}` : undefined}
            />
            <Stat
                label='Nodes online'
                icon={Network}
                value={totals ? totals.nodesOnline : loading}
                limit={totals ? `/ ${totals.nodesTotal}` : undefined}
                tone={totals && totals.nodesOnline < totals.nodesTotal ? 'warning' : 'neutral'}
            />
            <Stat
                label='CPU in use'
                icon={Cpu}
                value={totals ? cpuText(totals.cpuUsed, totals.cpuCapacity) : loading}
                meter={cpuPercent}
                tone={usageTone(cpuPercent)}
            />
            <Stat
                label='RAM allocated'
                icon={MemoryStick}
                value={totals ? bytesRatioToString(totals.memoryAllocated, totals.memoryCapacity) : loading}
                meter={memoryPercent}
                tone={usageTone(memoryPercent)}
            />
            <Stat
                label='Disk allocated'
                icon={HardDrive}
                value={totals ? bytesRatioToString(totals.diskAllocated, totals.diskCapacity) : loading}
                meter={diskPercent}
                tone={usageTone(diskPercent)}
            />
        </div>
    );
}

function NodeStrip() {
    const { data, error } = useAdminLiveUsage();

    if (!data) {
        return error ? (
            <p className='text-sm text-muted-foreground'>{httpErrorToHuman(error)}</p>
        ) : (
            <Spinner size='small' centered />
        );
    }

    const { nodes } = data;

    if (nodes.length === 0) {
        return <p className='text-sm text-muted-foreground'>There are no nodes yet.</p>;
    }

    return (
        <ul className='grid grid-cols-1 gap-3 sm:grid-cols-2'>
            {nodes.map((node) => {
                const online = node.status === 'online';
                const cpu = online ? usagePercent(node.cpu.used, node.cpu.capacity) : null;
                const memory = online ? usagePercent(node.memory.used_bytes, node.memory.capacity_bytes) : null;

                return (
                    <li key={node.id} className={cn('min-w-0 transition-opacity', !online && 'opacity-60')}>
                        <div className='mb-1.5 flex items-center justify-between gap-2 text-sm'>
                            <span className='truncate font-medium text-foreground'>{node.name}</span>
                            <span className='shrink-0 font-mono text-xs text-muted-foreground tabular-nums'>
                                {online ? cpuText(node.cpu.used, node.cpu.capacity) : 'Offline'}
                            </span>
                        </div>
                        <ProgressBar value={cpu} tone={usageTone(cpu)} label={`${node.name} CPU`} />
                        <ProgressBar
                            value={memory}
                            tone={usageTone(memory)}
                            label={`${node.name} memory`}
                            className='mt-1'
                        />
                    </li>
                );
            })}
        </ul>
    );
}

function LatestActivity() {
    const { data, error } = useQuery(adminActivityLogsQueryOptions({ page: 1, sorts: { timestamp: -1 } }));

    if (error && !data) {
        return <p className='text-sm text-muted-foreground'>{httpErrorToHuman(error)}</p>;
    }

    if (!data) {
        return <Spinner size='small' centered />;
    }

    const entries = data.data.slice(0, LATEST_ACTIVITY);

    if (entries.length === 0) {
        return <p className='text-sm text-muted-foreground'>Nothing has happened yet.</p>;
    }

    return (
        <ul className='divide-y divide-border'>
            {entries.map(({ attributes }) => (
                <li key={attributes.id} className='flex items-baseline justify-between gap-3 py-2 text-sm'>
                    <span className='min-w-0 truncate'>
                        <span className='text-foreground'>
                            {relationshipAttributes(attributes.relationships?.actor)?.username ?? 'System'}
                        </span>
                        <span className='text-muted-foreground'> · </span>
                        <span className='font-mono text-xs text-muted-foreground'>{attributes.event}</span>
                    </span>
                    <span className='shrink-0 text-xs text-muted-foreground'>{fromNow(attributes.timestamp)}</span>
                </li>
            ))}
        </ul>
    );
}

export default function OverviewContainer() {
    const { data: version, error, refetch } = useAdminVersion();
    const usage = useAdminLiveUsage();
    const users = useAdminUsers({ page: 1 });

    if (error && !version) {
        return <ServerError message={httpErrorToHuman(error)} onRetry={() => refetch()} />;
    }

    return (
        <AdminContentBlock
            title='Admin Overview'
            heading='Overview'
            description='How your panel, nodes and servers are doing right now.'
        >
            <Slot name='panel.overview.before' />
            {version ? <OverviewVersionCard version={version} /> : <Spinner size='large' centered />}
            <div className='animate-rise'>
                <StatTiles nodes={usage.data?.nodes} users={users.data?.meta.pagination.total} />
                <div className='mb-6 grid grid-cols-1 gap-4 lg:grid-cols-2'>
                    <Card
                        title='Nodes'
                        icon={Activity}
                        actions={
                            <Link
                                to='/panel/usage'
                                className='text-sm text-muted-foreground no-underline hover:text-accent'
                            >
                                Live usage
                            </Link>
                        }
                    >
                        <NodeStrip />
                    </Card>
                    <Card
                        title='Latest activity'
                        icon={History}
                        actions={
                            <Link
                                to='/panel/activity'
                                className='text-sm text-muted-foreground no-underline hover:text-accent'
                            >
                                View all
                            </Link>
                        }
                    >
                        <LatestActivity />
                    </Card>
                </div>
                <QuickLinks />
                <p className='mt-6 text-sm'>
                    <a
                        href={REPOSITORY_URL}
                        target='_blank'
                        rel='noreferrer'
                        className='inline-flex items-center gap-2 text-muted-foreground no-underline transition-colors hover:text-accent'
                    >
                        <Icon icon={Code2} className='h-4 w-4' />
                        MonoForge on GitHub
                    </a>
                </p>
            </div>
            <Slot name='panel.overview.after' />
        </AdminContentBlock>
    );
}
