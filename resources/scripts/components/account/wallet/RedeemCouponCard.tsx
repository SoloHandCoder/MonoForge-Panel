import { useState } from 'react';
import { TicketPercent } from 'lucide-react';
import { useRedeemCoupon } from '@/api/account/wallet/coupons';
import Button from '@/components/elements/Button';
import Card from '@/components/elements/Card';
import Icon from '@/components/elements/Icon';
import { TextInput } from '@/components/form/controls';
import { normalizeCoupon, validateCoupon } from '@/components/store/useCheckout';

/** "Redeem a coupon": a free-tokens coupon adds its tokens to the wallet. */
export default function RedeemCouponCard() {
    const [code, setCode] = useState('');
    const [error, setError] = useState<string | null>(null);
    const redeem = useRedeemCoupon();

    return (
        <Card className='animate-rise'>
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    const problem = validateCoupon(code);

                    setError(problem);

                    if (problem === null) {
                        redeem.mutate({ body: { code: normalizeCoupon(code) } }, { onSuccess: () => setCode('') });
                    }
                }}
            >
                <label htmlFor='wallet-coupon' className='flex items-center gap-2 text-sm text-muted-foreground'>
                    <Icon icon={TicketPercent} className='size-4' aria-hidden />
                    Redeem a coupon
                </label>
                <div className='mt-3 flex gap-2'>
                    <TextInput
                        id='wallet-coupon'
                        value={code}
                        maxLength={32}
                        autoComplete='off'
                        autoCapitalize='characters'
                        spellCheck={false}
                        placeholder='CODE'
                        className='font-mono uppercase'
                        $hasError={!!error}
                        aria-invalid={!!error}
                        aria-describedby={error ? 'wallet-coupon-error' : 'wallet-coupon-help'}
                        onChange={(event) => setCode(event.target.value)}
                    />
                    <Button type='submit' className='shrink-0' disabled={redeem.isPending || code.trim() === ''}>
                        Redeem
                    </Button>
                </div>
                {error ? (
                    <p id='wallet-coupon-error' className='mt-1.5 text-sm text-destructive'>
                        {error}
                    </p>
                ) : (
                    <p id='wallet-coupon-help' className='mt-1.5 text-xs text-muted-foreground'>
                        Discount coupons go in at the store checkout.
                    </p>
                )}
            </form>
        </Card>
    );
}
