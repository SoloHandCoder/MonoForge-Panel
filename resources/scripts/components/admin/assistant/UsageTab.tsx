import { useState } from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { formatTokens } from '@/api/account/wallet/queries';
import { useAssistantUsage } from '@/api/assistant';
import { httpErrorToHuman } from '@/api/http';
import { currentPeriod, formatPeriod, shiftPeriod } from '@/components/admin/assistant/assistantTabs';
import Icon from '@/components/elements/Icon';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import { cn } from '@/lib/cn';

function MonthPicker({ period, onChange }: { period: string; onChange: (period: string) => void }) {
    const latest = period >= currentPeriod();
    const buttonClass =
        'inline-flex size-8 items-center justify-center rounded-md text-muted-foreground transition-colors duration-150 hover:bg-(--hover-row) hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-40 disabled:hover:bg-transparent';

    return (
        <div className='flex items-center gap-1' role='group' aria-label='Month'>
            <button
                type='button'
                className={buttonClass}
                aria-label='Previous month'
                onClick={() => onChange(shiftPeriod(period, -1))}
            >
                <Icon icon={ChevronLeft} className='size-4' aria-hidden />
            </button>
            <span className='min-w-36 text-center text-sm font-medium text-foreground' aria-live='polite'>
                {formatPeriod(period)}
            </span>
            <button
                type='button'
                className={buttonClass}
                aria-label='Next month'
                disabled={latest}
                onClick={() => onChange(shiftPeriod(period, 1))}
            >
                <Icon icon={ChevronRight} className='size-4' aria-hidden />
            </button>
        </div>
    );
}

/** AI token use per user for a month: the total, the free part, and the panel tokens charged. */
export default function UsageTab() {
    const [period, setPeriod] = useState(currentPeriod);
    const { data, error, isFetching, refetch } = useAssistantUsage(period);

    if (error && !data) {
        return <ServerError message={httpErrorToHuman(error)} onRetry={() => void refetch()} />;
    }

    const rows = data?.data ?? [];
    const totals = rows.reduce(
        (sum, row) => ({
            ai: sum.ai + row.ai_tokens,
            free: sum.free + row.free_tokens,
            charged: sum.charged + row.panel_tokens_charged,
        }),
        { ai: 0, free: 0, charged: 0 }
    );

    return (
        <>
            <div className='mb-4 flex flex-wrap items-center justify-between gap-3'>
                <MonthPicker period={period} onChange={setPeriod} />
                {data && (
                    <p className='text-sm text-muted-foreground tabular-nums'>
                        {formatTokens(totals.ai)} AI tokens · {formatTokens(totals.charged)} tokens charged
                    </p>
                )}
            </div>
            {data ? (
                <div
                    className={cn(
                        'overflow-x-auto rounded-xl border border-border bg-card transition-opacity duration-150',
                        isFetching && 'opacity-60'
                    )}
                >
                    <table className='w-full text-sm'>
                        <thead>
                            <tr className='border-b border-border text-left text-xs text-muted-foreground'>
                                <th scope='col' className='px-4 py-2.5 font-medium'>
                                    User
                                </th>
                                <th scope='col' className='px-4 py-2.5 text-right font-medium'>
                                    AI tokens
                                </th>
                                <th scope='col' className='px-4 py-2.5 text-right font-medium'>
                                    Free
                                </th>
                                <th scope='col' className='px-4 py-2.5 text-right font-medium'>
                                    Charged
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr key={row.user_id} className='border-b border-border last:border-0'>
                                    <td className='px-4 py-2.5 text-foreground'>{row.username}</td>
                                    <td className='px-4 py-2.5 text-right tabular-nums'>
                                        {formatTokens(row.ai_tokens)}
                                    </td>
                                    <td className='px-4 py-2.5 text-right text-muted-foreground tabular-nums'>
                                        {formatTokens(row.free_tokens)}
                                    </td>
                                    <td className='px-4 py-2.5 text-right tabular-nums'>
                                        {formatTokens(row.panel_tokens_charged)}
                                    </td>
                                </tr>
                            ))}
                            {rows.length === 0 && (
                                <tr>
                                    <td colSpan={4} className='px-4 py-8 text-center text-muted-foreground'>
                                        Nobody used the assistant in {formatPeriod(period)}.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            ) : (
                <Spinner size='large' centered />
            )}
        </>
    );
}
