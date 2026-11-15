import { Server } from 'lucide-react';
import { httpErrorToHuman } from '@/api/http';
import { useAdminShopNodes, useSetShopNodeEnabled, type ShopNode } from '@/api/admin/shop/queries';
import Badge from '@/components/elements/Badge';
import Card from '@/components/elements/Card';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import Switch from '@/components/ui/Switch';

const locationLabel = (node: ShopNode): string =>
    node.location.long ? `${node.location.short} · ${node.location.long}` : node.location.short;

/** One node with its "Available in the shop" switch. */
export function ShopNodeRow({ node }: { node: ShopNode }) {
    const setEnabled = useSetShopNodeEnabled();

    return (
        <li className='flex flex-col gap-3 py-3 sm:flex-row sm:items-center'>
            <div className='min-w-0 flex-1'>
                <div className='flex flex-wrap items-center gap-2'>
                    <p className='font-medium text-foreground'>{node.name}</p>
                    {!node.public && (
                        <Badge tone='warning' variant='soft' size='sm'>
                            Private
                        </Badge>
                    )}
                    {node.maintenance && (
                        <Badge tone='warning' variant='soft' size='sm'>
                            Maintenance
                        </Badge>
                    )}
                </div>
                <p className='mt-1 text-xs text-muted-foreground'>
                    {locationLabel(node)} · {node.shop_servers} shop {node.shop_servers === 1 ? 'server' : 'servers'}
                </p>
            </div>
            <div className='flex items-center gap-3'>
                {/* Each switch is named after its node; the visible text is the same on every row. */}
                <span className='text-sm text-muted-foreground' aria-hidden>
                    Available in the shop
                </span>
                <Switch
                    checked={node.shop_enabled}
                    disabled={setEnabled.isPending}
                    aria-label={`Available in the shop: ${node.name}`}
                    onChange={(enabled) => setEnabled.mutate({ id: node.id, enabled })}
                />
            </div>
        </li>
    );
}

/**
 * The admin Shop "Nodes" sub-tab: which nodes the store may sell new servers on. Nodes are out of
 * the shop until the owner opts them in; servers already sold keep running when a node is taken out.
 */
export default function NodesTab() {
    const { data, error, refetch } = useAdminShopNodes();

    if (error) {
        return <ServerError message={httpErrorToHuman(error)} onRetry={() => void refetch()} />;
    }

    if (!data) {
        return <Spinner size='large' centered />;
    }

    if (data.length === 0) {
        return (
            <Empty>
                <EmptyHeader>
                    <EmptyMedia variant='icon'>
                        <Server />
                    </EmptyMedia>
                    <EmptyTitle>No nodes yet</EmptyTitle>
                    <EmptyDescription>Add a node first, then make it available in the shop here.</EmptyDescription>
                </EmptyHeader>
            </Empty>
        );
    }

    return (
        <Card title='Nodes'>
            <p className='text-sm text-muted-foreground'>
                The store only sells new servers on nodes that are available here and public. Taking a node out stops
                new sales on it; servers already bought there keep running and renewing.
            </p>
            <ul className='mt-3 divide-y divide-border'>
                {data.map((node) => (
                    <ShopNodeRow key={node.id} node={node} />
                ))}
            </ul>
        </Card>
    );
}
