import { useState, type FormEvent } from 'react';
import TitledGreyBox from '@/components/elements/TitledGreyBox';
import Button from '@/components/elements/Button';
import Label from '@/components/elements/Label';
import Spinner from '@/components/elements/Spinner';
import { TextInput } from '@/components/form/controls';
import Switch from '@/components/ui/Switch';
import { formatTokens } from '@/api/account/wallet/queries';
import { useAdjustUserTokens, useAdminUserTokens } from '@/api/admin/users/tokens';
import TokenLedgerTable from '@/components/account/wallet/TokenLedgerTable';

/** The most tokens one adjustment can add or remove (the API refuses more). */
export const MAX_TOKEN_ADJUSTMENT = 1_000_000;

/** A whole, non-zero number of tokens such as "250" or "-50", at most MAX_TOKEN_ADJUSTMENT either way, or null. */
export const parseTokenAdjustment = (value: string): number | null => {
    const trimmed = value.trim();

    if (!/^-?\d{1,13}$/.test(trimmed)) {
        return null;
    }

    const amount = Number(trimmed);

    return amount === 0 || Math.abs(amount) > MAX_TOKEN_ADJUSTMENT ? null : amount;
};

/** The user's token balance and ledger, and a form to add or remove tokens with a reason. */
export default function UserTokensCard({ userId }: { userId: number }) {
    const [page, setPage] = useState(1);
    const [amount, setAmount] = useState('');
    const [reason, setReason] = useState('');
    const [allowNegative, setAllowNegative] = useState(false);
    const { data, isFetching } = useAdminUserTokens(userId, page);
    const adjust = useAdjustUserTokens(userId);

    const parsed = parseTokenAdjustment(amount);
    const canSubmit = parsed !== null && reason.trim().length >= 3 && !adjust.isPending;

    const onSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (parsed === null || !canSubmit) {
            return;
        }

        adjust.mutate(
            { amount: parsed, reason: reason.trim(), allow_negative: allowNegative },
            {
                onSuccess: () => {
                    setAmount('');
                    setReason('');
                    setAllowNegative(false);
                    setPage(1);
                },
            }
        );
    };

    return (
        <TitledGreyBox title='Tokens' className='mt-4'>
            <p className='text-sm text-muted-foreground'>
                Balance{' '}
                <span data-testid='admin-token-balance' className='font-mono text-foreground tabular-nums'>
                    {data ? formatTokens(data.meta.balance) : '…'}
                </span>{' '}
                tokens
            </p>
            <form onSubmit={onSubmit} className='mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3'>
                <div>
                    <Label htmlFor='token-adjust-amount'>Amount</Label>
                    <TextInput
                        id='token-adjust-amount'
                        inputMode='numeric'
                        placeholder='250 or -50'
                        value={amount}
                        onChange={(event) => setAmount(event.currentTarget.value)}
                    />
                </div>
                <div className='sm:col-span-2'>
                    <Label htmlFor='token-adjust-reason'>Reason</Label>
                    <TextInput
                        id='token-adjust-reason'
                        maxLength={191}
                        placeholder='Shown to the user in their history'
                        value={reason}
                        onChange={(event) => setReason(event.currentTarget.value)}
                    />
                </div>
                <div className='flex flex-wrap items-center justify-between gap-4 sm:col-span-3'>
                    <Switch
                        checked={allowNegative}
                        onChange={setAllowNegative}
                        label='Allow a negative balance'
                        description='Only needed when removing more tokens than the user has.'
                    />
                    <Button type='submit' disabled={!canSubmit}>
                        {parsed !== null && parsed < 0 ? 'Remove tokens' : 'Add tokens'}
                    </Button>
                </div>
            </form>
            <div className='mt-6'>
                {data ? (
                    <TokenLedgerTable ledger={data} isFetching={isFetching} onPageSelect={setPage} />
                ) : (
                    <Spinner size='large' centered />
                )}
            </div>
        </TitledGreyBox>
    );
}
