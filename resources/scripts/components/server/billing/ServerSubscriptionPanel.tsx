import {
    renewServerSubscriptionInput,
    timeUntil,
    updateServerSubscriptionInput,
    useRenewServerSubscription,
    useServerSubscription,
    useUpdateServerSubscription,
    type ServerSubscription,
} from '@/api/billing/subscriptions';
import { Alert } from '@/components/elements/alert';
import Button from '@/components/elements/Button';
import Switch from '@/components/ui/Switch';
import { formatDate } from '@/lib/dates';
import { useNow } from '@/plugins/useNow';

/** The client Wallet page (Task Q), where tokens are bought. */
export const WALLET_PATH = '/wallet';

const dateFormat = 'MMM D, YYYY HH:mm';

function RenewButton({ subscription }: { subscription: ServerSubscription }) {
    const renew = useRenewServerSubscription();

    if (!subscription.can_renew_now) {
        return null;
    }

    return (
        <Button
            color={subscription.status === 'suspended' ? 'red' : 'primary'}
            isLoading={renew.isPending}
            disabled={renew.isPending}
            onClick={() => renew.mutate(renewServerSubscriptionInput(subscription.server_identifier))}
        >
            {subscription.status === 'suspended' ? 'Renew and unsuspend' : 'Renew now'}
        </Button>
    );
}

/** The countdown to the deletion of a suspended shop server, with "Renew" and "Top up". */
function SuspendedBanner({ subscription, now }: { subscription: ServerSubscription; now: number }) {
    const deleteAt = subscription.delete_at;
    const left = deleteAt === null ? null : timeUntil(deleteAt, now);

    return (
        <Alert
            type='danger'
            title={left === null || left === '0m' ? 'Suspended — deleting now' : `Suspended — deletes in ${left}`}
        >
            <p>
                The renewal of {subscription.price_tokens} tokens could not be paid
                {deleteAt === null ? '' : `, so this server is deleted on ${formatDate(deleteAt, dateFormat)}`}. Top up
                and renew to keep it.
            </p>
            <div className='mt-3 flex flex-wrap items-center gap-2'>
                <RenewButton subscription={subscription} />
                <a href={WALLET_PATH} className='text-sm font-medium underline underline-offset-2'>
                    Top up
                </a>
            </div>
        </Alert>
    );
}

/** Price, period, next renewal, automatic renewal and "Renew now" of an active shop server. */
function SubscriptionSummary({ subscription }: { subscription: ServerSubscription }) {
    const update = useUpdateServerSubscription();

    return (
        <section
            aria-label='Subscription'
            className='flex flex-col gap-3 rounded-lg border border-border bg-card p-4 text-sm text-card-foreground sm:flex-row sm:items-center'
        >
            <div className='min-w-0 flex-1'>
                <p className='font-semibold'>
                    {subscription.price_tokens} tokens every {subscription.period_days} days
                </p>
                <p className='text-muted-foreground'>
                    {subscription.auto_renew ? 'Renews' : 'Ends'} on{' '}
                    {formatDate(subscription.next_renewal_at, dateFormat)}
                    {subscription.auto_renew ? '' : ' unless renewed'}
                </p>
            </div>
            <Switch
                checked={subscription.auto_renew}
                disabled={update.isPending}
                label='Auto-renew'
                onChange={(checked) =>
                    update.mutate(updateServerSubscriptionInput(subscription.server_identifier, checked))
                }
            />
            <RenewButton subscription={subscription} />
        </section>
    );
}

/**
 * A shop server's billing above its pages: the deletion countdown while it is suspended (on
 * every page, including the suspended notice), and the subscription summary on the console.
 */
export default function ServerSubscriptionPanel({ identifier, summary }: { identifier: string; summary: boolean }) {
    const subscription = useServerSubscription(identifier);
    const now = useNow();

    if (!subscription || subscription.status === 'cancelled') {
        return null;
    }

    if (subscription.status === 'suspended') {
        return (
            <div className='mb-4'>
                <SuspendedBanner subscription={subscription} now={now} />
            </div>
        );
    }

    return summary ? (
        <div className='mb-4'>
            <SubscriptionSummary subscription={subscription} />
        </div>
    ) : null;
}
