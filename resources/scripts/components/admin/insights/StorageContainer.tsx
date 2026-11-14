import { Link } from '@tanstack/react-router';
import { Archive, Database, FolderOpen, Globe, type LucideIcon } from 'lucide-react';
import { httpErrorToHuman } from '@/api/http';
import { useAdminStorageUsage, type AdminNodeStorage, type AdminWebsiteStorage } from '@/api/admin/insights/queries';
import AdminContentBlock from '@/components/admin/AdminContentBlock';
import UsageMeter from '@/components/admin/insights/UsageMeter';
import { bytesText, usagePercent } from '@/components/admin/insights/usageFormat';
import Badge from '@/components/elements/Badge';
import Card from '@/components/elements/Card';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import { bytesToString } from '@/lib/formatters';
import { cn } from '@/lib/cn';

const backupCount = (count: number) => `${count} ${count === 1 ? 'backup' : 'backups'}`;

function NodeStorageCard({ node }: { node: AdminNodeStorage }) {
    const online = node.status === 'online';

    return (
        <Card
            title={
                <Link
                    to='/panel/nodes/$id'
                    params={{ id: node.id }}
                    className='truncate font-medium text-foreground no-underline transition-colors hover:text-accent'
                >
                    {node.name}
                </Link>
            }
            actions={
                <Badge tone={online ? 'success' : 'neutral'} variant='soft' size='sm'>
                    {online ? 'Online' : 'Offline'}
                </Badge>
            }
            className={cn('transition-opacity', !online && 'opacity-60')}
        >
            <div className='grid gap-4 sm:grid-cols-2'>
                <UsageMeter
                    label='Used'
                    value={bytesText(node.used_bytes, node.capacity_bytes)}
                    percent={usagePercent(node.used_bytes, node.capacity_bytes)}
                    detail={online ? 'Measured by Wings' : 'No reading while the node is offline'}
                />
                <UsageMeter
                    label='Allocated'
                    value={bytesText(node.allocated_bytes, node.capacity_bytes)}
                    percent={usagePercent(node.allocated_bytes, node.capacity_bytes)}
                    detail='Disk limits of its servers against the node limit'
                />
            </div>
            <h3 className='mt-5 mb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase'>
                Largest servers
            </h3>
            {node.servers.length === 0 ? (
                <p className='text-sm text-muted-foreground'>No servers on this node.</p>
            ) : (
                <ul className='divide-y divide-border'>
                    {node.servers.map((server) => (
                        <li key={server.id} className='flex items-center justify-between gap-4 py-2 text-sm'>
                            <Link
                                to='/panel/servers/$id'
                                params={{ id: server.id }}
                                className='truncate text-foreground no-underline transition-colors hover:text-accent'
                                title={server.name}
                            >
                                {server.name}
                            </Link>
                            <span className='shrink-0 font-mono text-muted-foreground tabular-nums'>
                                {bytesText(server.used_bytes, server.limit_bytes)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

function WebsiteFact({
    icon: Icon,
    label,
    value,
    detail,
}: {
    icon: LucideIcon;
    label: string;
    value: string;
    detail: string;
}) {
    return (
        <div className='flex items-start gap-3'>
            <div className='rounded-sm bg-muted p-2 text-accent'>
                <Icon className='h-4 w-4' aria-hidden />
            </div>
            <div className='min-w-0'>
                <p className='text-sm text-muted-foreground'>{label}</p>
                <p className='font-mono text-lg text-foreground tabular-nums'>{value}</p>
                <p className='text-xs text-muted-foreground'>{detail}</p>
            </div>
        </div>
    );
}

const orUnknown = (bytes: number | null) => (bytes === null ? 'Unknown' : bytesToString(bytes));

function WebsiteCard({ website }: { website: AdminWebsiteStorage }) {
    return (
        <Card title='Website' icon={Globe} className='mb-6'>
            <div className='grid gap-5 sm:grid-cols-2 lg:grid-cols-4'>
                <WebsiteFact
                    icon={Database}
                    label='Database'
                    value={orUnknown(website.database_bytes)}
                    detail='Panel tables and indexes'
                />
                <WebsiteFact
                    icon={FolderOpen}
                    label='Storage folder'
                    value={`${website.storage_approximate ? 'At least ' : ''}${orUnknown(website.storage_bytes)}`}
                    detail={
                        website.storage_approximate
                            ? 'Logs, caches and uploads; too many files to count them all'
                            : 'Logs, caches and uploads'
                    }
                />
                <WebsiteFact
                    icon={Archive}
                    label='Backups on nodes'
                    value={bytesToString(website.local_backups_bytes)}
                    detail={backupCount(website.local_backups_count)}
                />
                <WebsiteFact
                    icon={Archive}
                    label='Backups in S3'
                    value={bytesToString(website.remote_backups_bytes)}
                    detail={backupCount(website.remote_backups_count)}
                />
            </div>
        </Card>
    );
}

function StorageBody() {
    const { data, error, refetch } = useAdminStorageUsage();

    if (!data) {
        return error ? (
            <ServerError message={httpErrorToHuman(error)} onRetry={() => refetch()} />
        ) : (
            <Spinner size='large' centered />
        );
    }

    return (
        <div className='animate-rise'>
            <WebsiteCard website={data.website} />
            {data.nodes.length === 0 ? (
                <p className='text-sm text-muted-foreground'>There are no nodes yet.</p>
            ) : (
                <div className='grid grid-cols-1 gap-4 xl:grid-cols-2'>
                    {data.nodes.map((node) => (
                        <NodeStorageCard key={node.id} node={node} />
                    ))}
                </div>
            )}
        </div>
    );
}

export default function StorageContainer() {
    return (
        <AdminContentBlock
            title='Admin · Storage'
            heading='Storage'
            description='Disk your servers use on each node, what is allocated to them, and what the panel itself stores.'
        >
            <StorageBody />
        </AdminContentBlock>
    );
}
