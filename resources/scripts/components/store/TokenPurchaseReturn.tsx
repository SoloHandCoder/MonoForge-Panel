import { useEffect, useRef, useState, type CSSProperties, type ReactNode } from 'react';
import { Link, useNavigate } from '@tanstack/react-router';
import {
    Check,
    CircleSlash,
    Clock,
    Coins,
    Hourglass,
    ShoppingBag,
    Sparkles,
    Wallet,
    XCircle,
    type LucideIcon,
} from 'lucide-react';
import {
    forgetCheckout,
    PURCHASE_SLOW_AFTER_MS,
    readCheckoutReturn,
    useTokenPurchase,
    type CheckoutReturn,
    type TokenPurchase,
} from '@/api/account/wallet/purchases';
import { formatTokens } from '@/api/account/wallet/queries';
import Button, { buttonClassName } from '@/components/elements/Button';
import Icon from '@/components/elements/Icon';
import Portal from '@/components/elements/Portal';
import { Orb } from '@/components/store/PurchaseOverlay';
import { cn } from '@/lib/cn';

type Tone = 'calm' | 'danger' | 'warning';

const toneClasses = {
    calm: { glow: 'bg-primary/15', badge: 'bg-(--sunken) text-primary border border-primary/30' },
    danger: { glow: 'bg-destructive/15', badge: 'bg-destructive/10 text-destructive border border-destructive/40' },
    warning: { glow: 'bg-warning/15', badge: 'bg-warning/10 text-warning border border-warning/40' },
} satisfies Record<Tone, { glow: string; badge: string }>;

/** Whether the first minute has passed since the page started confirming. */
function useSlow(startedAt: number): boolean {
    const [slow, setSlow] = useState(() => Date.now() - startedAt >= PURCHASE_SLOW_AFTER_MS);

    useEffect(() => {
        if (slow) {
            return;
        }

        const timer = window.setTimeout(() => setSlow(true), PURCHASE_SLOW_AFTER_MS - (Date.now() - startedAt));

        return () => window.clearTimeout(timer);
    }, [slow, startedAt]);

    return slow;
}

function Actions({ onClose, primary = 'wallet' }: { onClose: () => void; primary?: 'wallet' | 'store' }) {
    const storeRef = useRef<HTMLAnchorElement>(null);
    const walletRef = useRef<HTMLButtonElement>(null);

    useEffect(() => (primary === 'store' ? storeRef : walletRef).current?.focus(), [primary]);

    const store = (
        <Link
            key='store'
            ref={storeRef}
            to='/store'
            className={buttonClassName({
                size: 'large',
                color: primary === 'store' ? 'primary' : 'grey',
                isSecondary: primary !== 'store',
                className: 'inline-flex flex-1 items-center justify-center gap-2 no-underline',
            })}
        >
            <Icon icon={ShoppingBag} className='size-4' aria-hidden />
            Go to store
        </Link>
    );
    const wallet = (
        <Button
            key='wallet'
            ref={walletRef}
            size='large'
            color={primary === 'wallet' ? 'primary' : 'grey'}
            isSecondary={primary !== 'wallet'}
            className='flex-1 justify-center gap-2'
            onClick={onClose}
        >
            <Icon icon={Wallet} className='size-4' aria-hidden />
            Wallet
        </Button>
    );

    return (
        <div className='mt-8 flex w-full max-w-sm flex-col gap-2 sm:flex-row'>
            {primary === 'store' ? [store, wallet] : [wallet, store]}
        </div>
    );
}

function Confirming() {
    const [filled, setFilled] = useState(false);

    // The bar creeps across the first minute; it says "working", not how far Tebex has got.
    useEffect(() => {
        const frame = window.requestAnimationFrame(() => setFilled(true));

        return () => window.cancelAnimationFrame(frame);
    }, []);

    return (
        <div className='flex flex-col items-center text-center'>
            <Orb icon={Coins} />
            <h2 className='mt-8 font-header text-2xl font-semibold text-foreground'>Confirming your payment…</h2>
            <p className='mt-1 text-sm text-muted-foreground'>
                Tebex is confirming your payment. This usually takes a few seconds.
            </p>
            <div className='mt-7 h-1 w-full max-w-xs overflow-hidden rounded-full bg-(--sunken)'>
                <div
                    className='h-full rounded-full bg-linear-to-r from-(--usage-from) to-(--usage-to) transition-[width] duration-[60000ms] ease-out'
                    style={{ width: filled ? '90%' : '8%' }}
                />
            </div>
        </div>
    );
}

function Message({
    icon,
    tone,
    title,
    children,
    onClose,
}: {
    icon: LucideIcon;
    tone: Tone;
    title: string;
    children: ReactNode;
    onClose: () => void;
}) {
    const classes = toneClasses[tone];

    return (
        <div className='flex animate-rise flex-col items-center text-center'>
            <div className='relative grid size-28 place-items-center' aria-hidden>
                <div className={cn('absolute inset-0 rounded-full blur-2xl', classes.glow)} />
                <div className={cn('grid size-20 place-items-center rounded-full', classes.badge)}>
                    <Icon icon={icon} className='size-9' />
                </div>
            </div>
            <h2 className='mt-8 font-header text-2xl font-semibold text-foreground'>{title}</h2>
            <div className='mt-2 max-w-sm space-y-2 text-sm text-muted-foreground'>{children}</div>
            <Actions onClose={onClose} />
        </div>
    );
}

/** A few sparkles that rise once around the badge; reduced motion shows them still. */
const SPARKLES = [
    { className: '-top-2 left-2 size-4', delay: '0.15s' },
    { className: 'top-3 -right-3 size-5', delay: '0.3s' },
    { className: '-bottom-1 -left-3 size-3', delay: '0.45s' },
    { className: 'bottom-4 -right-1 size-3', delay: '0.6s' },
];

function Success({ purchase, onClose }: { purchase: TokenPurchase; onClose: () => void }) {
    return (
        <div className='flex animate-rise flex-col items-center text-center'>
            <div className='relative grid size-28 place-items-center' aria-hidden>
                <div className='absolute inset-0 rounded-full bg-(--status-online)/20 blur-2xl' />
                <div className='absolute inset-0 rounded-full border-2 border-(--status-online)/40 motion-safe:animate-ping motion-safe:[animation-iteration-count:2]' />
                <div className='absolute inset-0 rounded-full border-2 border-(--status-online)/40' />
                <div className='grid size-20 place-items-center rounded-full bg-success text-success-foreground shadow-2xl shadow-success/50'>
                    <Icon icon={Check} className='size-10' strokeWidth={3} />
                </div>
                {SPARKLES.map(({ className, delay }) => (
                    <Icon
                        key={className}
                        icon={Sparkles}
                        className={cn('absolute animate-rise text-(--accent-soft)', className)}
                        style={{ '--rise-delay': delay } as CSSProperties}
                    />
                ))}
            </div>
            <h2 className='mt-8 font-header text-3xl font-bold text-foreground'>
                +{formatTokens(purchase.tokens)} tokens added
            </h2>
            <p className='mt-2 text-muted-foreground'>
                New balance:{' '}
                <span data-testid='purchase-balance' className='font-medium text-foreground tabular-nums'>
                    {formatTokens(purchase.balance)} tokens
                </span>
            </p>
            {purchase.pack_name && (
                <p className='mt-1 text-sm text-muted-foreground'>Thanks for buying {purchase.pack_name}!</p>
            )}
            <Actions onClose={onClose} primary='store' />
        </div>
    );
}

function PurchaseState({
    checkoutReturn,
    reference,
    onClose,
}: {
    checkoutReturn: CheckoutReturn;
    reference: string;
    onClose: () => void;
}) {
    const [startedAt] = useState(() => Date.now());
    const cancelled = checkoutReturn.outcome === 'cancelled';
    const { data, isError } = useTokenPurchase(reference, startedAt, !cancelled);
    const slow = useSlow(startedAt);

    if (isError) {
        return (
            <Message icon={Clock} tone='calm' title='We could not look up this purchase' onClose={onClose}>
                <p>If you paid, your tokens are added automatically as soon as Tebex confirms the payment.</p>
                <p>You can close this page.</p>
            </Message>
        );
    }

    switch (data?.status) {
        case 'completed':
            return <Success purchase={data} onClose={onClose} />;
        case 'refunded':
            return (
                <Message icon={XCircle} tone='danger' title='Payment refunded' onClose={onClose}>
                    <p>Tebex reports that this payment was refunded, so its tokens are not in your wallet.</p>
                </Message>
            );
        case 'declined':
            return (
                <Message icon={XCircle} tone='danger' title='Payment declined' onClose={onClose}>
                    <p>Tebex declined this payment, so no tokens were added. You can try again from your wallet.</p>
                </Message>
            );
        case 'review':
            return (
                <Message icon={Hourglass} tone='warning' title='Payment being checked' onClose={onClose}>
                    <p>
                        Tebex received your payment, but it needs a quick check by our team before the tokens are added.
                        You will get a notice when it is done.
                    </p>
                </Message>
            );
        case 'pending':
        case undefined:
            break;
    }

    if (cancelled && data) {
        return (
            <Message icon={CircleSlash} tone='calm' title='Checkout cancelled' onClose={onClose}>
                <p>You left the Tebex checkout before paying, so no tokens were added.</p>
                <p>If you did complete the payment, the tokens are added automatically once Tebex confirms it.</p>
            </Message>
        );
    }

    if (slow && data) {
        return (
            <Message icon={Clock} tone='calm' title='Still confirming' onClose={onClose}>
                <p>Your tokens will be added automatically; you can close this page.</p>
                <p>We keep checking in the background.</p>
            </Message>
        );
    }

    return <Confirming />;
}

function Overlay({ children, onClose }: { children: ReactNode; onClose: () => void }) {
    const rootRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const { overflow } = document.body.style;

        document.body.style.overflow = 'hidden';
        rootRef.current?.focus();

        return () => {
            document.body.style.overflow = overflow;
        };
    }, []);

    return (
        <Portal>
            <div
                ref={rootRef}
                role='dialog'
                aria-modal='true'
                aria-label='Token purchase'
                tabIndex={-1}
                onKeyDown={(event) => {
                    if (event.key === 'Escape') {
                        onClose();
                    }
                }}
                className='fixed inset-0 z-50 grid animate-fade-in place-items-center overflow-x-hidden overflow-y-auto bg-background/95 px-4 py-10 outline-none backdrop-blur-md'
            >
                <div
                    aria-hidden
                    className='pointer-events-none absolute top-1/4 left-1/2 size-128 -translate-x-1/2 rounded-full bg-primary/10 blur-3xl'
                />
                <div className='relative w-full max-w-md' role='status' aria-live='polite'>
                    {children}
                </div>
            </div>
        </Portal>
    );
}

/**
 * The screen a player lands on after the Tebex checkout (`/account/wallet?tebex=…&checkout=…`).
 * It shows only what the server confirmed for one of the player's own checkouts: confirming, the
 * tokens added and the new balance, a calm "still confirming" after a minute, or a refunded,
 * declined or cancelled state.
 */
export default function TokenPurchaseReturn() {
    const [checkoutReturn] = useState(() => readCheckoutReturn(window.location.search));
    const [open, setOpen] = useState(checkoutReturn !== null);
    const navigate = useNavigate();

    if (!open || checkoutReturn === null) {
        return null;
    }

    const close = () => {
        forgetCheckout();
        setOpen(false);
        void navigate({ to: '/account/wallet', replace: true });
    };

    return (
        <Overlay onClose={close}>
            {checkoutReturn.reference === null ? (
                // Without a reference there is nothing to ask the server about, so nothing is claimed.
                <Message icon={Clock} tone='calm' title='Back from Tebex' onClose={close}>
                    <p>
                        If you completed the payment, your tokens appear in your wallet as soon as Tebex confirms it,
                        usually within a minute.
                    </p>
                </Message>
            ) : (
                <PurchaseState checkoutReturn={checkoutReturn} reference={checkoutReturn.reference} onClose={close} />
            )}
        </Overlay>
    );
}

export { TokenPurchaseReturn };
