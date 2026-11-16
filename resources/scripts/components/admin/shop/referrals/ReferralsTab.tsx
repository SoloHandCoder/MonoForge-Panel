import { useState } from 'react';
import { useStore } from '@tanstack/react-form';
import {
    useAdminReferrals,
    useApproveReferral,
    useRejectReferral,
    useReferralSettings,
    useSaveReferralSettings,
    type AdminReferral,
    type ReferralSettings,
    type ReferralStatus,
} from '@/api/admin/shop/coupons';
import { httpErrorToHuman } from '@/api/http';
import Badge from '@/components/elements/Badge';
import Button from '@/components/elements/Button';
import Card from '@/components/elements/Card';
import { Dialog } from '@/components/elements/dialog';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import { Form, useAppForm } from '@/components/form';
import { formatDate } from '@/lib/dates';
import type { UiTone } from '@/sdk/theme';

const statusTones = {
    registered: 'neutral',
    held: 'info',
    paid: 'success',
    reversed: 'danger',
    rejected: 'danger',
    capped: 'warning',
} as const satisfies Record<ReferralStatus, UiTone>;

export const referralStatusText = {
    registered: 'Signed up',
    held: 'Held',
    paid: 'Paid',
    reversed: 'Reversed',
    rejected: 'Rejected',
    capped: 'Capped',
} satisfies Record<ReferralStatus, string>;

/** Whether an administrator can still approve or reject it. */
export const canReviewReferral = (referral: AdminReferral): boolean =>
    referral.status === 'registered' || referral.status === 'held';

function SettingsForm({ settings }: { settings: ReferralSettings }) {
    const save = useSaveReferralSettings();
    const form = useAppForm({
        defaultValues: {
            enabled: settings.enabled,
            reward: settings.reward_tokens as number | null,
            cap: settings.monthly_cap as number | null,
            hold: settings.hold_days as number | null,
            minimum: settings.min_purchase_tokens as number | null,
        },
        onSubmit: async ({ value }) => {
            try {
                await save.mutateAsync({
                    body: {
                        enabled: value.enabled,
                        reward_tokens: value.reward ?? 1,
                        monthly_cap: value.cap ?? 0,
                        hold_days: value.hold ?? 30,
                        min_purchase_tokens: value.minimum ?? 0,
                    },
                });
            } catch {
                // Error toast is handled by the mutation.
            }
        },
    });
    const enabled = useStore(form.store, (state) => state.values.enabled);

    return (
        <Card title='Referral programme'>
            <Form form={form} className='m-0'>
                <div className='grid gap-5'>
                    <form.AppField name='enabled'>
                        {(field) => (
                            <field.SwitchField
                                label='Pay referral rewards'
                                description='Every user gets a link. When someone signs up through it, has a linked Discord or Google account that never earned a reward, and their first Tebex token purchase stays paid for the hold period, both get the reward.'
                            />
                        )}
                    </form.AppField>
                    <div className='grid gap-5 sm:grid-cols-2'>
                        <form.AppField
                            name='reward'
                            validators={{
                                onChange: ({ value }) =>
                                    value === null || value < 1 || value > 1_000_000
                                        ? 'Between 1 and 1,000,000.'
                                        : undefined,
                            }}
                        >
                            {(field) => (
                                <field.NumberField
                                    label='Reward (tokens, each side)'
                                    min={1}
                                    max={1_000_000}
                                    disabled={!enabled}
                                />
                            )}
                        </form.AppField>
                        <form.AppField
                            name='cap'
                            validators={{
                                onChange: ({ value }) =>
                                    value === null || value < 0 || value > 1000 ? 'Between 0 and 1,000.' : undefined,
                            }}
                        >
                            {(field) => (
                                <field.NumberField
                                    label='Rewards per referrer a month'
                                    description='0 is no limit; over it a referral is capped and not paid.'
                                    min={0}
                                    max={1000}
                                    disabled={!enabled}
                                />
                            )}
                        </form.AppField>
                        <form.AppField
                            name='hold'
                            validators={{
                                onChange: ({ value }) =>
                                    value === null || value < 1 || value > 365 ? 'Between 1 and 365.' : undefined,
                            }}
                        >
                            {(field) => (
                                <field.NumberField
                                    label='Hold period (days)'
                                    description='How long the first purchase must stay paid. Disputes can come weeks later.'
                                    min={1}
                                    max={365}
                                    disabled={!enabled}
                                />
                            )}
                        </form.AppField>
                        <form.AppField
                            name='minimum'
                            validators={{
                                onChange: ({ value }) =>
                                    value === null || value < 0 || value > 100_000_000 ? 'Use 0 or more.' : undefined,
                            }}
                        >
                            {(field) => (
                                <field.NumberField
                                    label='Smallest first purchase (tokens)'
                                    description={`0 is the smallest token pack (now ${settings.effective_min_purchase_tokens.toLocaleString('en-US')} tokens).`}
                                    min={0}
                                    max={100_000_000}
                                    disabled={!enabled}
                                />
                            )}
                        </form.AppField>
                    </div>
                    <p className='text-xs text-muted-foreground'>
                        The first purchase is held for the hold period. A refund, chargeback or dispute before then
                        cancels the referral; one after the payout takes both rewards back. Sign-ups from the
                        referrer&apos;s own network are flagged below and wait for your approval.
                    </p>
                </div>
                <div className='mt-6 flex justify-end'>
                    <form.AppForm>
                        <form.SubmitButton>Save settings</form.SubmitButton>
                    </form.AppForm>
                </div>
            </Form>
        </Card>
    );
}

function ReviewActions({ referral }: { referral: AdminReferral }) {
    const approve = useApproveReferral();
    const reject = useRejectReferral();
    const names = `${referral.referrer?.username ?? 'a deleted user'} → ${referral.referred?.username ?? 'a deleted user'}`;

    return (
        <div className='flex flex-wrap justify-end gap-2'>
            {referral.awaiting_review && (
                <Dialog.ConfirmTrigger
                    title='Approve referral'
                    confirm='Approve'
                    onConfirmed={async (_event, close) => {
                        try {
                            await approve.mutateAsync({ path: { referral_id: referral.id } });
                            close();
                        } catch {
                            // Error toast is handled by the mutation.
                        }
                    }}
                    trigger={({ onClick }) => (
                        <Button.Text type='button' size='xsmall' onClick={onClick}>
                            Approve
                        </Button.Text>
                    )}
                >
                    <p className='text-sm leading-relaxed text-muted-foreground'>
                        Referral <strong className='text-foreground'>{names}</strong> was flagged:{' '}
                        {referral.flag_reason ?? 'no reason recorded'}. Approved, both accounts get the reward once the
                        first purchase has passed the hold period.
                    </p>
                </Dialog.ConfirmTrigger>
            )}
            <Dialog.ConfirmTrigger
                title='Reject referral'
                confirm='Reject'
                onConfirmed={async (_event, close) => {
                    try {
                        await reject.mutateAsync({ path: { referral_id: referral.id } });
                        close();
                    } catch {
                        // Error toast is handled by the mutation.
                    }
                }}
                trigger={({ onClick }) => (
                    <Button.Danger type='button' size='xsmall' isSecondary onClick={onClick}>
                        Reject
                    </Button.Danger>
                )}
            >
                <p className='text-sm leading-relaxed text-muted-foreground'>
                    Referral <strong className='text-foreground'>{names}</strong> will never be paid. This cannot be
                    undone.
                </p>
            </Dialog.ConfirmTrigger>
        </div>
    );
}

/** The date that matters for a referral: when it was paid, when it is due, else when they joined. */
export function referralDate(referral: AdminReferral): string {
    if (referral.status === 'paid' && referral.paid_at) {
        return `Paid ${formatDate(referral.paid_at, 'MMM D, YYYY')}`;
    }

    if (referral.status === 'held' && referral.payout_after) {
        return `Due ${formatDate(referral.payout_after, 'MMM D, YYYY')}`;
    }

    return referral.created_at ? `Joined ${formatDate(referral.created_at, 'MMM D, YYYY')}` : '';
}

function ReferralRow({ referral }: { referral: AdminReferral }) {
    const when = referralDate(referral);

    return (
        <tr className='border-t border-border align-top'>
            <td className='py-2 pr-3 text-sm'>
                <p className='truncate text-foreground'>{referral.referrer?.username ?? 'Deleted user'}</p>
                <p className='truncate text-xs text-muted-foreground'>{referral.referrer?.email}</p>
            </td>
            <td className='py-2 pr-3 text-sm'>
                <p className='truncate text-foreground'>{referral.referred?.username ?? 'Deleted user'}</p>
                <p className='truncate text-xs text-muted-foreground'>{referral.referred?.email}</p>
            </td>
            <td className='py-2 pr-3'>
                <div className='flex flex-wrap gap-1.5'>
                    <Badge tone={statusTones[referral.status]} variant='soft' size='sm'>
                        {referralStatusText[referral.status]}
                    </Badge>
                    {referral.awaiting_review && (
                        <Badge tone='warning' variant='soft' size='sm'>
                            Needs review
                        </Badge>
                    )}
                </div>
                {referral.flag_reason && (
                    <p className='mt-1 max-w-xs text-xs text-muted-foreground'>{referral.flag_reason}</p>
                )}
                <p className='mt-1 text-xs text-muted-foreground'>{when}</p>
            </td>
            <td className='py-2 pl-3 text-right'>
                {canReviewReferral(referral) && <ReviewActions referral={referral} />}
            </td>
        </tr>
    );
}

function ReferralList() {
    const [page, setPage] = useState(1);
    const [flagged, setFlagged] = useState(false);
    const { data, error, refetch } = useAdminReferrals(page, flagged);

    return (
        <Card
            title='Referrals'
            actions={
                <Button.Text
                    type='button'
                    size='xsmall'
                    isSecondary={!flagged}
                    aria-pressed={flagged}
                    onClick={() => {
                        setFlagged(!flagged);
                        setPage(1);
                    }}
                >
                    {flagged ? 'Showing flagged only' : 'Show flagged only'}
                </Button.Text>
            }
        >
            {error && <ServerError message={httpErrorToHuman(error)} onRetry={() => void refetch()} />}
            {!data && !error && <Spinner size='large' centered />}
            {data && data.data.length === 0 && <p className='text-sm text-muted-foreground'>No referrals yet.</p>}
            {data && data.data.length > 0 && (
                <div className='overflow-x-auto'>
                    <table className='w-full min-w-[36rem] text-left'>
                        <thead>
                            <tr className='text-xs text-muted-foreground'>
                                <th className='pb-2 font-medium'>Referrer</th>
                                <th className='pb-2 font-medium'>Referred</th>
                                <th className='pb-2 font-medium'>Status</th>
                                <th className='pb-2 font-medium'>
                                    <span className='sr-only'>Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.data.map((referral) => (
                                <ReferralRow key={referral.id} referral={referral} />
                            ))}
                        </tbody>
                    </table>
                    {data.meta.last_page > 1 && (
                        <div className='mt-3 flex items-center justify-end gap-2 text-sm'>
                            <Button.Text
                                type='button'
                                size='xsmall'
                                disabled={page <= 1}
                                onClick={() => setPage(page - 1)}
                            >
                                Previous
                            </Button.Text>
                            <span className='tabular-nums text-muted-foreground'>
                                {page} / {data.meta.last_page}
                            </span>
                            <Button.Text
                                type='button'
                                size='xsmall'
                                disabled={page >= data.meta.last_page}
                                onClick={() => setPage(page + 1)}
                            >
                                Next
                            </Button.Text>
                        </div>
                    )}
                </div>
            )}
        </Card>
    );
}

/** Shop → Referrals: the programme's settings and the referred sign-ups, with review of flagged ones. */
export default function ReferralsTab() {
    const { data, error, refetch } = useReferralSettings();

    return (
        <div className='grid gap-6'>
            {error && <ServerError message={httpErrorToHuman(error)} onRetry={() => void refetch()} />}
            {!data && !error && <Spinner size='large' centered />}
            {data && <SettingsForm settings={data} />}
            <ReferralList />
        </div>
    );
}
