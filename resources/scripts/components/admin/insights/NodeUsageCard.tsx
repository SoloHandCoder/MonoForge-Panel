import { useState } from 'react';
import { Link } from '@tanstack/react-router';
import { ChevronDown, Server } from 'lucide-react';
import type { AdminNodeUsage, AdminServerUsage } from '@/api/admin/insights/queries';
import UsageMeter from '@/components/admin/insights/UsageMeter';
import { bytesText, cpuText, stateBadge, usagePercent } from '@/components/admin/insights/usageFormat';
import Badge from '@/components/elements/Badge';
import Card from '@/components/elements/Card';
import { bytesToString } from '@/lib/formatters';
import { cn } from '@/lib/cn';

const byCpu = (a: AdminServerUsage, b: AdminServerUsage) => b.cpu - a.cpu || a.name.localeCompare(b.name);

function ServerUsageRow({ server }: { server: AdminServerUsage }) {
    const state = stateBadge(server.state);

    return (
        <li className='grid grid-cols-2 gap-x-4 gap-y-1 py-2.5 text-sm sm:grid-cols-[minmax(0,2fr)_auto_repeat(3,minmax(0,1fr))] sm:items-center'>
            <Link
                to='/panel/servers/$id'
                params={{ id: server.id }}
                className='col-span-2 truncate font-medium text-foreground no-underline transition-colors hover:text-accent sm:col-span-1'
                title={server.name}
            >
                {server.name}
            </Link>
            <Badge tone={state.tone} variant='soft' size='sm' className='justify-self-start'>
                {state.label}
            </Badge>
            <span className='font-mono text-muted-foreground tabular-nums'>
                <span className='sr-only'>CPU </span>
                {cpuText(server.cpu, server.cpu_limit)}
            </span>
            <span className='truncate font-mono text-muted-foreground tabular-nums'>
                <span className='sr-only'>Memory </span>
                {bytesText(server.memory_bytes, server.memory_limit_bytes)}
            </span>
            <span className='truncate font-mono text-muted-foreground tabular-nums'>
                <span className='sr-only'>Disk </span>
                {bytesText(server.disk_bytes, server.disk_limit_bytes)}
            </span>
        </li>
    );
}

/** A node's live CPU and memory, with its servers listed busiest first on demand. */
export default function NodeUsageCard({ node }: { node: AdminNodeUsage }) {
    const [expanded, setExpanded] = useState(false);
    const online = node.status === 'online';
    const servers = [...node.servers].sort(byCpu);
    const listId = `node-usage-${node.id}-servers`;

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
                    label='CPU'
                    value={online ? cpuText(node.cpu.used, node.cpu.capacity) : '—'}
                    percent={online ? usagePercent(node.cpu.used, node.cpu.capacity) : null}
                    detail={online && node.cpu.capacity === null ? 'Wings did not report its threads' : undefined}
                />
                <UsageMeter
                    label='Memory'
                    value={online ? bytesText(node.memory.used_bytes, node.memory.capacity_bytes) : '—'}
                    percent={online ? usagePercent(node.memory.used_bytes, node.memory.capacity_bytes) : null}
                    detail={`${bytesToString(node.memory.allocated_bytes)} allocated`}
                />
            </div>
            <button
                type='button'
                aria-expanded={expanded}
                aria-controls={listId}
                disabled={servers.length === 0}
                onClick={() => setExpanded((open) => !open)}
                className='mt-4 flex w-full items-center gap-2 rounded-sm text-left text-sm text-muted-foreground transition-colors hover:text-foreground disabled:cursor-default disabled:hover:text-muted-foreground'
            >
                <Server className='h-4 w-4' aria-hidden />
                <span className='flex-1'>
                    {online
                        ? `${node.servers_online} of ${node.servers_total} running`
                        : `${node.servers_total} ${node.servers_total === 1 ? 'server' : 'servers'}, unreachable`}
                </span>
                {servers.length > 0 && (
                    <ChevronDown className={cn('h-4 w-4 transition-transform', expanded && 'rotate-180')} aria-hidden />
                )}
            </button>
            {expanded && (
                <ul id={listId} className='mt-2 animate-fade-in divide-y divide-border border-t border-border'>
                    {servers.map((server) => (
                        <ServerUsageRow key={server.id} server={server} />
                    ))}
                </ul>
            )}
        </Card>
    );
}
