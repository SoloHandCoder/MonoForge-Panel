import { Coins } from 'lucide-react';
import {
    Badge,
    cn,
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
    formatDate,
    Table,
    type TableColumn,
    type TablePagination,
} from '@/sdk';
import type { UiTone } from '@/sdk/theme';
import {
    formatTokens,
    tokenTransactionLabels,
    type TokenLedgerPage,
    type TokenTransaction,
} from '@/api/account/wallet/queries';

type Row = TokenLedgerPage['data'][number];

/** "+500" in green or "−200" in red. */
export function TokenAmount({ amount }: { amount: number }) {
    return (
        <span
            className={cn(
                'whitespace-nowrap font-mono text-sm tabular-nums',
                amount > 0 ? 'text-(--status-online)' : 'text-destructive'
            )}
        >
            {amount > 0 ? '+' : '−'}
            {formatTokens(Math.abs(amount))}
        </span>
    );
}

const tones = {
    purchase: 'success',
    refund: 'danger',
    chargeback: 'danger',
    server_charge: 'neutral',
    addon_charge: 'neutral',
    ai_usage: 'neutral',
    admin_adjust: 'info',
    correction: 'info',
    coupon: 'success',
    referral: 'success',
} as const satisfies Record<TokenTransaction['type'], UiTone>;

const columns: TableColumn<Row>[] = [
    {
        id: 'description',
        label: 'Description',
        cell: ({ attributes }) => (
            <div className='min-w-40'>
                <p className='truncate text-sm text-foreground'>{attributes.description}</p>
                <time dateTime={attributes.created_at} className='mt-0.5 block text-xs text-muted-foreground'>
                    {formatDate(attributes.created_at, 'MMM D, YYYY HH:mm')}
                </time>
            </div>
        ),
    },
    {
        id: 'type',
        label: 'Type',
        cell: ({ attributes }) => (
            <Badge size='sm' variant='soft' tone={tones[attributes.type]}>
                {tokenTransactionLabels[attributes.type]}
            </Badge>
        ),
        headerClassName: 'hidden sm:table-cell w-32',
        cellClassName: 'hidden sm:table-cell w-32',
    },
    {
        id: 'amount',
        label: 'Amount',
        cell: ({ attributes }) => <TokenAmount amount={attributes.amount} />,
        headerClassName: 'w-28 text-right',
        cellClassName: 'w-28 text-right',
    },
    {
        id: 'balance',
        label: 'Balance',
        cell: ({ attributes }) => (
            <span className='font-mono text-sm tabular-nums text-muted-foreground'>
                {formatTokens(attributes.balance_after)}
            </span>
        ),
        headerClassName: 'hidden md:table-cell w-28 text-right',
        cellClassName: 'hidden md:table-cell w-28 text-right',
    },
];

/** A page of a token ledger, newest first, with a pager when there is more than one page. */
export default function TokenLedgerTable({
    ledger,
    isFetching,
    onPageSelect,
}: {
    ledger: TokenLedgerPage;
    isFetching: boolean;
    onPageSelect: TablePagination['onPageSelect'];
}) {
    const { pagination } = ledger.meta;

    return (
        <Table
            rows={ledger.data}
            columns={columns}
            keyOf={(row) => String(row.attributes.id)}
            isFetching={isFetching}
            pagination={
                pagination.total_pages > 1
                    ? {
                          page: pagination.current_page,
                          perPage: pagination.per_page,
                          total: pagination.total,
                          count: pagination.count,
                          itemLabel: 'transactions',
                          onPageSelect,
                      }
                    : undefined
            }
            emptyState={
                <Empty size='compact'>
                    <EmptyHeader>
                        <EmptyMedia variant='icon'>
                            <Coins />
                        </EmptyMedia>
                        <EmptyTitle>No transactions yet</EmptyTitle>
                        <EmptyDescription>Token purchases and charges show up here.</EmptyDescription>
                    </EmptyHeader>
                </Empty>
            }
        />
    );
}
