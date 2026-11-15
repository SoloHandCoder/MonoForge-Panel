import { useState } from 'react';
import {
    cancelAdminServerSubscriptionInput,
    extendAdminServerSubscriptionInput,
    timeUntil,
    useAdminServerSubscription,
    useCancelAdminServerSubscription,
    useExtendAdminServerSubscription,
} from '@/api/billing/subscriptions';
import TitledGreyBox from '@/components/elements/TitledGreyBox';
import Button from '@/components/elements/Button';
import { Dialog } from '@/components/elements/dialog';
import { formatDate } from '@/lib/dates';
import { useNow } from '@/plugins/useNow';

const dateFormat = 'MMM D, YYYY HH:mm';

const statusLabels = { active: 'Active', suspended: 'Suspended (unpaid)', cancelled: 'Cancelled' } as const;

const statusLabel = (status: string): string =>
    status === 'active' || status === 'suspended' || status === 'cancelled' ? statusLabels[status] : status;

/**
 * The shop billing of a server bought in the shop: what its owner pays, the next renewal or the
 * deletion countdown, and the admin's "Extend" and "Cancel". Renders nothing for other servers.
 */
export default function ServerSubscriptionCard({ serverId }: { serverId: number }) {
    const { data: subscription } = useAdminServerSubscription(serverId);
    const extend = useExtendAdminServerSubscription();
    const cancel = useCancelAdminServerSubscription();
    const [days, setDays] = useState(7);
    const now = useNow(60_000);

    if (!subscription) {
        return null;
    }

    const cancelled = subscription.status === 'cancelled';
    const rows: [string, string][] = [
        ['Status', statusLabel(subscription.status)],
        ['Price', `${subscription.price_tokens} tokens every ${subscription.period_days} days`],
        ['Automatic renewal', subscription.auto_renew ? 'On' : 'Off'],
        [cancelled ? 'Last renewal date' : 'Next renewal', formatDate(subscription.next_renewal_at, dateFormat)],
    ];

    if (subscription.status === 'suspended' && subscription.delete_at) {
        rows.push([
            'Deletion',
            `${formatDate(subscription.delete_at, dateFormat)} (in ${timeUntil(subscription.delete_at, now)})`,
        ]);
    }

    if (subscription.addons.length > 0) {
        rows.push(['Add-ons', subscription.addons.map((addon) => `${addon.key} × ${addon.units}`).join(', ')]);
    }

    return (
        <TitledGreyBox title='Shop Subscription' className='mb-6' contentClassName='flex flex-col gap-4 p-3'>
            <dl className='grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2'>
                {rows.map(([label, value]) => (
                    <div key={label} className='flex flex-col'>
                        <dt className='text-muted-foreground'>{label}</dt>
                        <dd className='text-card-foreground'>{value}</dd>
                    </div>
                ))}
            </dl>
            {!cancelled && (
                <div className='flex flex-wrap items-end justify-end gap-3 border-t border-border pt-3'>
                    <label className='flex flex-col gap-1 text-sm text-muted-foreground'>
                        Free days
                        <input
                            type='number'
                            min={1}
                            max={365}
                            value={days}
                            onChange={(event) => setDays(Number(event.currentTarget.value))}
                            className='w-24 rounded-md border border-border bg-background px-2 py-1 text-foreground'
                        />
                    </label>
                    <Button
                        color='green'
                        disabled={extend.isPending || days < 1 || days > 365}
                        isLoading={extend.isPending}
                        onClick={() => extend.mutate(extendAdminServerSubscriptionInput(serverId, days))}
                    >
                        Extend
                    </Button>
                    <Dialog.ConfirmTrigger
                        title='Cancel Subscription'
                        confirm='Cancel subscription'
                        pending={cancel.isPending}
                        trigger={({ onClick }) => (
                            <Button color='red' disabled={cancel.isPending} onClick={onClick}>
                                Cancel subscription
                            </Button>
                        )}
                        onConfirmed={(_event, close) =>
                            cancel.mutate(cancelAdminServerSubscriptionInput(serverId), { onSettled: close })
                        }
                    >
                        The server stops being billed: no more renewals, no suspension and no deletion. A server
                        suspended for an unpaid renewal is unsuspended. The server itself stays.
                    </Dialog.ConfirmTrigger>
                </div>
            )}
        </TitledGreyBox>
    );
}
