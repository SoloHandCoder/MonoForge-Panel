import { Coins } from 'lucide-react';
import { useState } from 'react';
import { httpErrorToHuman } from '@/api/http';
import { isTebexCheckoutUrl, useTokenPackCheckout, useTokenPacks, type TokenPack } from '@/api/store/tokenPacks';
import Button from '@/components/elements/Button';
import Card from '@/components/elements/Card';
import Spinner from '@/components/elements/Spinner';

const formatTokens = (tokens: number) => tokens.toLocaleString();

/** What Tebex sent the player back with: `?tebex=complete` or `?tebex=cancelled`. */
const checkoutOutcome = (): string | null => {
    try {
        return new URLSearchParams(window.location.search).get('tebex');
    } catch {
        return null;
    }
};

function CheckoutOutcome() {
    const outcome = checkoutOutcome();

    if (outcome === 'complete') {
        return (
            <p role='status' className='mb-4 rounded-md border border-success/40 bg-success/10 px-3 py-2 text-sm'>
                Thanks for your purchase! Your tokens appear here as soon as Tebex confirms the payment, usually within
                a minute.
            </p>
        );
    }

    if (outcome === 'cancelled') {
        return (
            <p role='status' className='mb-4 rounded-md border border-border bg-muted/40 px-3 py-2 text-sm'>
                The checkout was cancelled. Nothing was charged.
            </p>
        );
    }

    return null;
}

function PackCard({ pack, busy, onBuy }: { pack: TokenPack; busy: boolean; onBuy: () => void }) {
    return (
        <li className='flex flex-col justify-between gap-3 rounded-lg border border-border bg-card p-4'>
            <div>
                <p className='font-medium text-foreground'>{pack.name}</p>
                <p className='mt-1 text-2xl font-semibold tabular-nums text-foreground'>
                    {formatTokens(pack.tokens)}{' '}
                    <span className='text-sm font-normal text-muted-foreground'>tokens</span>
                </p>
                {pack.bonus_tokens > 0 && (
                    <p className='mt-1 text-sm text-success'>+{formatTokens(pack.bonus_tokens)} bonus tokens</p>
                )}
            </div>
            <Button onClick={onBuy} isLoading={busy} disabled={busy} aria-label={`Buy ${pack.name}`}>
                Buy
            </Button>
        </li>
    );
}

/**
 * The token packs on sale, each with a Buy button that opens the Tebex checkout. Rendered on the
 * Wallet page; it renders nothing while no Tebex store is connected.
 */
export default function TokenPacks() {
    const { data, error, isLoading } = useTokenPacks();
    const checkout = useTokenPackCheckout();
    const [buying, setBuying] = useState<number | null>(null);
    const [redirectError, setRedirectError] = useState(false);

    const buy = async (pack: TokenPack) => {
        setBuying(pack.id);
        setRedirectError(false);

        try {
            const url = await checkout.mutateAsync(pack.id);

            if (isTebexCheckoutUrl(url)) {
                window.location.assign(url);

                return;
            }

            setRedirectError(true);
        } catch {
            // Error toast is handled by the mutation.
        }

        setBuying(null);
    };

    if (isLoading) {
        return <Spinner size='large' centered />;
    }

    if (error) {
        return <p className='text-sm text-destructive'>{httpErrorToHuman(error)}</p>;
    }

    if (!data?.enabled || data.data.length === 0) {
        return null;
    }

    return (
        <Card title='Buy tokens' icon={Coins}>
            <CheckoutOutcome />
            {redirectError && (
                <p role='alert' className='mb-4 text-sm text-destructive'>
                    The store returned an unexpected checkout link, so it was not opened. Please try again.
                </p>
            )}
            <ul className='grid gap-3 sm:grid-cols-2 lg:grid-cols-3'>
                {data.data.map((pack) => (
                    <PackCard key={pack.id} pack={pack} busy={buying === pack.id} onBuy={() => void buy(pack)} />
                ))}
            </ul>
            <p className='mt-4 text-xs text-muted-foreground'>
                Payments are handled by Tebex, our payment partner. Tokens are added once the payment is confirmed.
            </p>
        </Card>
    );
}

export { TokenPacks };
