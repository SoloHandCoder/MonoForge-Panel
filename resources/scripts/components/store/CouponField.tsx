import { useState } from 'react';
import { TicketPercent, X } from 'lucide-react';
import type { StoreQuoteCoupon } from '@/api/store/types';
import Button from '@/components/elements/Button';
import Icon from '@/components/elements/Icon';
import { TextInput } from '@/components/form/controls';
import { tokens } from '@/components/store/storeFormat';
import { normalizeCoupon, validateCoupon } from '@/components/store/useCheckout';

export interface CouponControl {
    /** The applied code, upper case, or null. */
    applied: string | null;
    /** The server's check of the applied code, once the quote has it. */
    result: StoreQuoteCoupon | null;
    onApply: (code: string) => void;
    onRemove: () => void;
}

/**
 * "Have a coupon?" in the order summary. A code is only applied here: the quote checks it and
 * the purchase redeems it.
 */
export default function CouponField({ coupon }: { coupon: CouponControl }) {
    const [open, setOpen] = useState(false);
    const [code, setCode] = useState('');
    const [error, setError] = useState<string | null>(null);

    if (coupon.applied) {
        const { result } = coupon;

        return (
            <div className='mt-4 rounded-xl border border-border bg-(--sunken) p-3 text-sm' aria-live='polite'>
                <div className='flex items-center gap-2'>
                    <Icon icon={TicketPercent} className='size-4 shrink-0 text-(--accent-soft)' aria-hidden />
                    <span className='min-w-0 flex-1 truncate font-mono font-medium text-foreground'>
                        {coupon.applied}
                    </span>
                    {result?.valid && (
                        <span className='shrink-0 text-(--status-online) tabular-nums'>−{tokens(result.discount)}</span>
                    )}
                    <button
                        type='button'
                        className='grid size-7 shrink-0 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-(--hover-row) hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring'
                        aria-label={`Remove the coupon ${coupon.applied}`}
                        onClick={() => {
                            coupon.onRemove();
                            setCode('');
                        }}
                    >
                        <Icon icon={X} className='size-4' aria-hidden />
                    </button>
                </div>
                {result && !result.valid && (
                    <p role='alert' className='mt-1.5 text-destructive'>
                        {result.message} Remove it to buy without a coupon.
                    </p>
                )}
                {result?.valid && (
                    <p className='mt-1.5 text-muted-foreground'>
                        Applies to the first period. Renewals are full price.
                    </p>
                )}
            </div>
        );
    }

    if (!open) {
        return (
            <button
                type='button'
                className='mt-4 text-sm font-medium text-accent underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-ring'
                onClick={() => setOpen(true)}
            >
                Have a coupon?
            </button>
        );
    }

    const apply = () => {
        const problem = validateCoupon(code);

        setError(problem);

        if (problem === null) {
            coupon.onApply(normalizeCoupon(code));
        }
    };

    return (
        <form
            className='mt-4'
            onSubmit={(event) => {
                event.preventDefault();
                apply();
            }}
        >
            <label htmlFor='checkout-coupon' className='mb-1.5 block text-sm text-muted-foreground'>
                Coupon code
            </label>
            <div className='flex gap-2'>
                <TextInput
                    id='checkout-coupon'
                    value={code}
                    maxLength={32}
                    autoComplete='off'
                    autoCapitalize='characters'
                    spellCheck={false}
                    className='font-mono uppercase'
                    $hasError={!!error}
                    aria-invalid={!!error}
                    aria-describedby={error ? 'checkout-coupon-error' : undefined}
                    onChange={(event) => setCode(event.target.value)}
                />
                <Button.Text type='submit' isSecondary className='shrink-0'>
                    Apply
                </Button.Text>
            </div>
            {error && (
                <p id='checkout-coupon-error' className='mt-1.5 text-sm text-destructive'>
                    {error}
                </p>
            )}
        </form>
    );
}
