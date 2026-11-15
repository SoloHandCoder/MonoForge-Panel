import { useState } from 'react';
import { Server } from 'lucide-react';
import { httpErrorToHuman } from '@/api/http';
import {
    useAdminShopNodes,
    useSetShopNodeEnabled,
    useSetShopNodeLimits,
    type ShopCapacity,
    type ShopNode,
} from '@/api/admin/shop/queries';
import { formatMegabytes } from '@/components/admin/shop/format';
import Badge from '@/components/elements/Badge';
import Button from '@/components/elements/Button';
import Card from '@/components/elements/Card';
import ProgressBar from '@/components/elements/ProgressBar';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import Switch from '@/components/ui/Switch';
import { NumberInput } from '@/components/form/controls';

const locationLabel = (node: ShopNode): string =>
    node.location.long ? `${node.location.short} · ${node.location.long}` : node.location.short;

const limitFields: {
    key: keyof ShopCapacity;
    name: string;
    label: string;
    max: number;
    format: (value: number) => string;
}[] = [
    { key: 'memory', name: 'Memory', label: 'Memory (MB)', max: 1_000_000_000, format: formatMegabytes },
    { key: 'disk', name: 'Disk', label: 'Disk (MB)', max: 1_000_000_000, format: formatMegabytes },
    { key: 'cpu', name: 'CPU', label: 'CPU (%)', max: 10_000_000, format: (value) => `${value}%` },
];

/** "used / limit" for one resource, as a bar that turns amber and red as the node fills up. */
function LimitMeter({
    label,
    used,
    limit,
    format,
}: {
    label: string;
    used: number;
    limit: number;
    format: (value: number) => string;
}) {
    const percent = limit > 0 ? Math.min(100, Math.round((used / limit) * 100)) : null;
    let tone: 'success' | 'warning' | 'danger' = 'success';

    if (percent !== null && percent >= 90) {
        tone = 'danger';
    } else if (percent !== null && percent >= 75) {
        tone = 'warning';
    }

    return (
        <div>
            <div className='mb-1 flex items-baseline justify-between gap-2 text-xs'>
                <span className='text-muted-foreground'>{label}</span>
                <span className='tabular-nums text-muted-foreground'>
                    {format(used)} / {limit > 0 ? format(limit) : 'no limit'}
                </span>
            </div>
            <ProgressBar value={percent ?? 0} tone={limit > 0 ? tone : 'neutral'} label={`${label} sold`} />
        </div>
    );
}

/** The node's own shop limits (0 = no limit) with what its shop servers use now. */
function NodeLimits({ node }: { node: ShopNode }) {
    const save = useSetShopNodeLimits();
    const [values, setValues] = useState<Record<keyof ShopCapacity, number | null>>(node.limits);
    const valid = limitFields.every(({ key, max }) => {
        const value = values[key];

        return value !== null && Number.isInteger(value) && value >= 0 && value <= max;
    });
    const changed = limitFields.some(({ key }) => values[key] !== node.limits[key]);

    return (
        <form
            className='grid gap-3 rounded-xl border border-border p-3'
            onSubmit={(event) => {
                event.preventDefault();

                if (valid) {
                    save.mutate({
                        id: node.id,
                        limits: { memory: values.memory ?? 0, disk: values.disk ?? 0, cpu: values.cpu ?? 0 },
                    });
                }
            }}
        >
            <div className='grid gap-3 sm:grid-cols-3'>
                {limitFields.map(({ key, name, label, max, format }) => (
                    <div key={key} className='grid gap-2'>
                        <label className='grid gap-1 text-xs font-medium text-foreground'>
                            {label}
                            <NumberInput
                                aria-label={`${label} limit: ${node.name}`}
                                value={values[key]}
                                min={0}
                                max={max}
                                onValueChange={(value) => setValues((current) => ({ ...current, [key]: value }))}
                            />
                        </label>
                        <LimitMeter label={name} used={node.used[key]} limit={node.limits[key]} format={format} />
                    </div>
                ))}
            </div>
            <div className='flex flex-wrap items-center justify-between gap-2'>
                <p className='text-xs text-muted-foreground'>
                    0 is no limit. The shop-wide limits apply as well; the node&apos;s own memory and disk do not.
                </p>
                <Button type='submit' disabled={!valid || !changed} isLoading={save.isPending}>
                    Save limits
                </Button>
            </div>
        </form>
    );
}

/** One node with its "Available in the shop" switch and its shop limits. */
export function ShopNodeRow({ node }: { node: ShopNode }) {
    const setEnabled = useSetShopNodeEnabled();

    return (
        <li className='grid gap-3 py-3'>
            <div className='flex flex-col gap-3 sm:flex-row sm:items-center'>
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
                        {locationLabel(node)} · {node.shop_servers} shop{' '}
                        {node.shop_servers === 1 ? 'server' : 'servers'}
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
            </div>
            <NodeLimits key={JSON.stringify(node.limits)} node={node} />
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
                new sales on it; servers already bought there keep running and renewing. Each node can also cap what the
                shop sells on it: when a purchase would pass a node&apos;s limit, the store greys that node out.
            </p>
            <ul className='mt-3 divide-y divide-border'>
                {data.map((node) => (
                    <ShopNodeRow key={node.id} node={node} />
                ))}
            </ul>
        </Card>
    );
}
