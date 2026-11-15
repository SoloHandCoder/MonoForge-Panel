import { Coins } from 'lucide-react';
import { useState } from 'react';
import { rememberCheckout } from '@/api/account/wallet/purchases';
import { httpErrorToHuman } from '@/api/http';
import { isTebexCheckoutUrl, useTokenPackCheckout, useTokenPacks, type TokenPack } from '@/api/store/tokenPacks';
import Button from '@/components/elements/Button';
import Card from '@/components/elements/Card';
import Spinner from '@/components/elements/Spinner';
import PurchaseAgreement from '@/components/legal/PurchaseAgreement';

const formatTokens = (tokens: number) => tokens.toLocaleString();

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
 * Wallet page; it renders nothing while no Tebex store is connected. The return from Tebex is
 * shown by `TokenPurchaseReturn`.
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
            const { url, checkout: reference } = await checkout.mutateAsync(pack.id);

            if (isTebexCheckoutUrl(url)) {
                rememberCheckout(reference);
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
            <PurchaseAgreement className='mt-1' />
        </Card>
    );
}

export { TokenPacks };
