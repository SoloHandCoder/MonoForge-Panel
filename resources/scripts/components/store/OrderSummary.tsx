import { Check, Coins, ShieldCheck } from 'lucide-react';
import type { StoreResources } from '@/api/store/types';
import { Alert } from '@/components/elements/alert';
import Button from '@/components/elements/Button';
import { Dialog } from '@/components/elements/dialog';
import Icon from '@/components/elements/Icon';
import Spinner from '@/components/elements/Spinner';
import PurchaseAgreement from '@/components/legal/PurchaseAgreement';
import CouponField, { type CouponControl } from '@/components/store/CouponField';
import { CapacityNotice } from '@/components/store/StoreNotices';
import { everyDays, planResources, tokens } from '@/components/store/storeFormat';
import type { CheckoutError } from '@/components/store/useCheckout';
import { cn } from '@/lib/cn';
import { PanelLink } from '@/sdk/navigation';

export interface OrderPrice {
    /** The price of every period. */
    total: number;
    /** What is charged now (the first period after a coupon); null while the coupon is being checked. */
    firstPeriod?: number | null;
    days: number;
    lines: { label: string; tokens: number }[] | null;
    resources: StoreResources;
    updating: boolean;
    balance: number | null;
    short: boolean;
    soldOut: boolean;
}

export function ResourceList({ resources, className }: { resources: StoreResources; className?: string }) {
    return (
        <ul className={cn('grid gap-1.5 text-sm', className)}>
            {planResources(resources).map((line) => (
                <li key={line} className='flex items-center gap-2'>
                    <Icon icon={Check} className='size-3.5 text-(--accent-soft)' aria-hidden />
                    {line}
                </li>
            ))}
        </ul>
    );
}

function WalletLink() {
    return (
        <PanelLink
            destination={{ to: '/account/wallet' }}
            className='font-medium text-accent underline-offset-2 hover:underline'
        >
            Top up your wallet
        </PanelLink>
    );
}

/** What is charged now: the first period after the coupon, else the price of a period. */
export const chargedNow = (price: OrderPrice): number => price.firstPeriod ?? price.total;

function Total({ price }: { price: OrderPrice }) {
    const now = chargedNow(price);
    const discounted = now !== price.total;

    return (
        <div className='mt-5 border-t border-border pt-4'>
            {discounted && (
                <p className='mb-3 flex items-baseline justify-between gap-3 text-sm'>
                    <span className='text-muted-foreground'>First period</span>
                    <span className='font-header text-xl font-bold text-foreground tabular-nums'>{tokens(now)}</span>
                </p>
            )}
            <p className='text-xs text-muted-foreground'>
                {discounted ? 'Then' : 'Total'} {everyDays(price.days)}
            </p>
            <p className='mt-0.5 flex items-baseline gap-2' aria-live='polite'>
                <span
                    className={cn(
                        'font-header text-3xl font-bold text-foreground tabular-nums transition-opacity duration-200',
                        price.updating && 'opacity-60'
                    )}
                >
                    {price.total.toLocaleString('en-US')}
                </span>
                <span className='text-sm text-muted-foreground'>tokens</span>
                {price.updating && <Spinner size='small' />}
            </p>
            {price.balance !== null && (
                <p className='mt-2 flex flex-wrap items-center gap-x-1.5 text-sm text-muted-foreground'>
                    <Icon icon={Coins} className='size-3.5 shrink-0' aria-hidden />
                    <span className='whitespace-nowrap'>Balance {tokens(price.balance)}</span>
                    {!price.short && <span className='whitespace-nowrap'>· {tokens(price.balance - now)} after</span>}
                </p>
            )}
        </div>
    );
}

/** The sticky order card: what the server gets, the live price, the balance and the buy button. */
export default function OrderSummary({
    price,
    error,
    canBuy,
    onBuy,
    coupon,
}: {
    price: OrderPrice;
    error: CheckoutError | null;
    canBuy: boolean;
    onBuy: () => void;
    coupon?: CouponControl;
}) {
    const now = chargedNow(price);

    return (
        <aside
            className='animate-rise rounded-2xl border border-border bg-card p-5 sm:p-6 lg:sticky lg:top-6'
            aria-label='Order summary'
        >
            <h2 className='font-header text-base font-semibold text-foreground'>Your order</h2>
            <ResourceList resources={price.resources} className='mt-4 text-muted-foreground' />
            {price.lines && (
                <dl className='mt-5 grid grid-cols-1 gap-1.5 border-t border-border pt-4 text-sm'>
                    {price.lines.map((line) => (
                        <div key={line.label} className='flex justify-between gap-3'>
                            <dt className='min-w-0 truncate text-muted-foreground'>{line.label}</dt>
                            <dd className='shrink-0 text-foreground tabular-nums'>{tokens(line.tokens)}</dd>
                        </div>
                    ))}
                </dl>
            )}
            {coupon && <CouponField coupon={coupon} />}
            <Total price={price} />
            {price.soldOut && <CapacityNotice className='mt-4' />}
            {price.short && !price.soldOut && price.balance !== null && (
                <p role='alert' className='mt-4 text-sm text-destructive'>
                    You need {tokens(now - price.balance)} more. <WalletLink />
                </p>
            )}
            {error && (
                <Alert type='danger' className='mt-4'>
                    {error.message} {error.short && <WalletLink />}
                </Alert>
            )}
            <Button size='large' className='mt-5 w-full justify-center' disabled={!canBuy} onClick={onBuy}>
                Buy for {tokens(now)}
            </Button>
            <p className='mt-3 flex items-center justify-center gap-1.5 text-xs text-muted-foreground'>
                <Icon icon={ShieldCheck} className='size-3.5' aria-hidden />
                You confirm before anything is charged.
            </p>
        </aside>
    );
}

/** The last look before paying: exactly what is paid, and how often. */
export function ConfirmPurchaseDialog({
    open,
    title,
    subtitle,
    price,
    onClose,
    onConfirm,
}: {
    open: boolean;
    title: string;
    subtitle: string;
    price: OrderPrice;
    onClose: () => void;
    onConfirm: () => void;
}) {
    const now = chargedNow(price);

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title='Confirm your purchase'
            description={`${tokens(now)} now, then ${tokens(price.total)} ${everyDays(price.days)} from your balance until you cancel.`}
        >
            <div className='grid gap-4 text-sm'>
                <div className='rounded-xl border border-border bg-(--sunken) p-4'>
                    <p className='font-medium text-foreground'>{title}</p>
                    <p className='mt-0.5 text-muted-foreground'>{subtitle}</p>
                    <p className='mt-3 text-xs font-medium tracking-wide text-muted-foreground uppercase'>
                        {everyDays(price.days)} you get
                    </p>
                    <ResourceList resources={price.resources} className='mt-1.5 gap-1 text-foreground' />
                </div>
                {now !== price.total && (
                    <p className='flex items-baseline justify-between gap-3'>
                        <span className='text-muted-foreground'>You pay now (with the coupon)</span>
                        <span className='font-header text-xl font-bold text-foreground tabular-nums'>
                            {tokens(now)}
                        </span>
                    </p>
                )}
                <p className='flex items-baseline justify-between gap-3'>
                    <span className='text-muted-foreground'>
                        {now === price.total ? 'You pay' : 'Then you pay'} {everyDays(price.days)}
                    </span>
                    <span className='font-header text-xl font-bold text-foreground tabular-nums'>
                        {tokens(price.total)}
                    </span>
                </p>
                <PurchaseAgreement />
            </div>
            <Dialog.Footer>
                <Button.Text isSecondary onClick={onClose}>
                    Back
                </Button.Text>
                <Button onClick={onConfirm}>Pay {tokens(now)}</Button>
            </Dialog.Footer>
        </Dialog>
    );
}
