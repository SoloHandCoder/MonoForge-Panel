import type { ComponentType } from 'react';
import { Gift, ShieldCheck } from 'lucide-react';
import type { StoreResources } from '@/api/store/types';
import { Alert } from '@/components/elements/alert';
import Button from '@/components/elements/Button';
import Icon from '@/components/elements/Icon';
import { LegalLink } from '@/components/legal/LegalLinks';
import { ResourceList } from '@/components/store/OrderSummary';
import { CapacityNotice } from '@/components/store/StoreNotices';
import type { CheckoutError } from '@/components/store/useCheckout';
import { tryFreeLabel } from '@/components/store/useTrial';

/** The order card of a free trial: what the server gets, for how long, and the start button. */
export default function TrialSummary({
    hours,
    resources,
    soldOut,
    error,
    canStart,
    working,
    Captcha,
    onStart,
}: {
    hours: number;
    resources: StoreResources;
    soldOut: boolean;
    error: CheckoutError | null;
    canStart: boolean;
    working: boolean;
    Captcha: ComponentType | null;
    onStart: () => void;
}) {
    return (
        <aside
            className='animate-rise rounded-2xl border border-primary/50 bg-card p-5 sm:p-6 lg:sticky lg:top-6'
            aria-label='Free trial summary'
        >
            <h2 className='flex items-center gap-2 font-header text-base font-semibold text-foreground'>
                <Icon icon={Gift} className='size-4 text-(--accent-soft)' aria-hidden />
                Your free trial
            </h2>
            <ResourceList resources={resources} className='mt-4 text-muted-foreground' />
            <div className='mt-5 border-t border-border pt-4'>
                <p className='text-xs text-muted-foreground'>For {hours} hours</p>
                <p className='mt-0.5 font-header text-3xl font-bold text-foreground'>Free</p>
                <p className='mt-2 text-sm text-muted-foreground'>
                    Nothing is charged. Keep the server by picking a billing period on its page before the trial ends;
                    otherwise it is suspended and then deleted.
                </p>
            </div>
            {soldOut && <CapacityNotice className='mt-4' />}
            {error && (
                <Alert type='danger' className='mt-4'>
                    {error.message}
                </Alert>
            )}
            {Captcha && (
                <div className='mt-4'>
                    <Captcha />
                </div>
            )}
            <Button
                size='large'
                className='mt-5 w-full justify-center'
                disabled={!canStart}
                isLoading={working}
                onClick={onStart}
            >
                {tryFreeLabel(hours)}
            </Button>
            <p className='mt-3 text-xs text-muted-foreground'>
                By starting a trial you agree to the <LegalLink document='terms' />.
            </p>
            <p className='mt-2 flex items-center justify-center gap-1.5 text-xs text-muted-foreground'>
                <Icon icon={ShieldCheck} className='size-3.5' aria-hidden />
                One free trial per player.
            </p>
        </aside>
    );
}
