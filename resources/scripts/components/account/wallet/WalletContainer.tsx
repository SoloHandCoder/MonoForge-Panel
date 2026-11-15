import { useState } from 'react';
import { Wallet } from 'lucide-react';
import { Card, cn, ContentBox, Icon, PageContentBlock, PageHeading, ServerError, Spinner } from '@/sdk';
import { httpErrorToHuman } from '@/api/http';
import { formatTokens, useWallet } from '@/api/account/wallet/queries';
import TokenPacks from '@/components/store/TokenPacks';
import TokenLedgerTable from './TokenLedgerTable';

/** The user's token balance, the packs to buy more with, and their token history. */
export default function WalletContainer() {
    const [page, setPage] = useState(1);
    const { data, error, isFetching } = useWallet(page);

    if (error && !data) {
        return <ServerError message={httpErrorToHuman(error)} />;
    }

    return (
        <PageContentBlock title='Wallet'>
            <PageHeading
                title='Wallet'
                description='Tokens pay for your servers and are taken automatically each billing period.'
            />
            <div className='mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3'>
                <Card className='animate-rise'>
                    <div className='flex items-center gap-2 text-sm text-muted-foreground'>
                        <Icon icon={Wallet} className='size-4' aria-hidden />
                        Balance
                    </div>
                    {data ? (
                        <p
                            data-testid='wallet-balance'
                            className={cn(
                                'mt-3 font-header text-4xl font-semibold tabular-nums',
                                data.meta.balance < 0 ? 'text-destructive' : 'text-foreground'
                            )}
                        >
                            {formatTokens(data.meta.balance)}
                            <span className='ml-2 text-base font-normal text-muted-foreground'>tokens</span>
                        </p>
                    ) : (
                        <Spinner size='small' />
                    )}
                </Card>
                <ContentBox title='Buy tokens' className='lg:col-span-2'>
                    <TokenPacks />
                </ContentBox>
            </div>
            <ContentBox title='History' className='mt-8'>
                {data ? (
                    <TokenLedgerTable ledger={data} isFetching={isFetching} onPageSelect={setPage} />
                ) : (
                    <Spinner size='large' centered />
                )}
            </ContentBox>
        </PageContentBlock>
    );
}
