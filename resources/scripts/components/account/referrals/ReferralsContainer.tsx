import { useState } from 'react';
import { Check, Copy, Gift } from 'lucide-react';
import { toast } from 'sonner';
import {
    referralLink,
    referralStatusLabels,
    useReferrals,
    type Referral,
    type ReferralOverview,
} from '@/api/account/referrals';
import { formatTokens } from '@/api/account/wallet/queries';
import { httpErrorToHuman } from '@/api/http';
import Badge from '@/components/elements/Badge';
import Button from '@/components/elements/Button';
import ContentBox from '@/components/elements/ContentBox';
import Icon from '@/components/elements/Icon';
import PageContentBlock from '@/components/elements/PageContentBlock';
import PageHeading from '@/components/elements/PageHeading';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import { TextInput } from '@/components/form/controls';
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import copyToClipboard from '@/lib/clipboard';
import { formatDate } from '@/lib/dates';
import type { UiTone } from '@/sdk/theme';

const statusTones = {
    registered: 'neutral',
    held: 'info',
    paid: 'success',
    reversed: 'danger',
    rejected: 'danger',
    capped: 'warning',
} as const satisfies Record<Referral['status'], UiTone>;

/** What the referrer sees about one referral: its state in words. */
export function referralNote(referral: Referral): string {
    if (referral.underReview && (referral.status === 'registered' || referral.status === 'held')) {
        return 'Under review before it can be paid.';
    }

    switch (referral.status) {
        case 'registered':
            return 'Signed up; waiting for their first token purchase.';
        case 'held':
            return referral.payoutAfter
                ? `Bought tokens; paid out on ${formatDate(referral.payoutAfter, 'MMM D, YYYY')} unless refunded.`
                : 'Bought tokens; waiting for the hold period.';
        case 'paid':
            return `You got ${formatTokens(referral.rewardTokens ?? 0)} tokens${referral.paidAt ? ` on ${formatDate(referral.paidAt, 'MMM D, YYYY')}` : ''}.`;
        case 'reversed':
            return 'Their purchase was refunded, so there is no reward.';
        case 'rejected':
            return 'Not eligible for a reward.';
        case 'capped':
            return 'You reached the monthly limit of rewards.';
    }
}

function LinkBox({ overview }: { overview: ReferralOverview }) {
    const [copied, setCopied] = useState(false);
    const link = overview.path ? referralLink(overview.path) : '';

    return (
        <ContentBox title='Your link' className='animate-rise'>
            <p className='text-sm text-muted-foreground'>
                Share it with friends. When someone signs up through it and buys tokens for the first time, you both get{' '}
                <strong className='text-foreground'>{formatTokens(overview.rewardTokens)} tokens</strong> once the
                purchase has stayed paid for {overview.holdDays} days.
            </p>
            <div className='mt-4 flex flex-col gap-2 sm:flex-row'>
                <label htmlFor='referral-link' className='sr-only'>
                    Referral link
                </label>
                <TextInput
                    id='referral-link'
                    value={link}
                    readOnly
                    className='font-mono'
                    onFocus={(event) => event.currentTarget.select()}
                />
                <Button
                    type='button'
                    className='shrink-0 justify-center'
                    onClick={async () => {
                        if (await copyToClipboard(link)) {
                            setCopied(true);
                            toast.success('Link copied');
                            window.setTimeout(() => setCopied(false), 2000);
                        } else {
                            toast.error('Unable to copy the link');
                        }
                    }}
                >
                    <Icon icon={copied ? Check : Copy} className='size-4' aria-hidden />
                    <span className='ml-1.5'>{copied ? 'Copied' : 'Copy link'}</span>
                </Button>
            </div>
        </ContentBox>
    );
}

function Stats({ overview }: { overview: ReferralOverview }) {
    const items = [
        { label: 'Signed up', value: overview.stats.signedUp },
        { label: 'Pending', value: overview.stats.pending },
        { label: 'Paid', value: overview.stats.paid },
        { label: 'Tokens earned', value: overview.stats.tokensEarned },
    ];

    return (
        <dl className='grid grid-cols-2 gap-3 sm:grid-cols-4'>
            {items.map((item) => (
                <div key={item.label} className='animate-rise rounded-xl border border-border bg-card p-4'>
                    <dt className='text-xs text-muted-foreground'>{item.label}</dt>
                    <dd className='mt-1 font-header text-2xl font-semibold text-foreground tabular-nums'>
                        {formatTokens(item.value)}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

function ReferralList({ referrals }: { referrals: Referral[] }) {
    if (referrals.length === 0) {
        return (
            <Empty>
                <EmptyHeader>
                    <EmptyMedia variant='icon'>
                        <Gift />
                    </EmptyMedia>
                    <EmptyTitle>No referrals yet</EmptyTitle>
                    <EmptyDescription>People who sign up through your link show up here.</EmptyDescription>
                </EmptyHeader>
            </Empty>
        );
    }

    return (
        <ul className='divide-y divide-border'>
            {referrals.map((referral) => (
                <li key={referral.id} className='flex flex-col gap-1 py-3 sm:flex-row sm:items-center sm:gap-4'>
                    <div className='min-w-0 flex-1'>
                        <p className='font-medium text-foreground'>{referral.username}</p>
                        <p className='text-xs text-muted-foreground'>{referralNote(referral)}</p>
                    </div>
                    <div className='flex items-center gap-3 text-xs text-muted-foreground'>
                        <span>Joined {formatDate(referral.createdAt, 'MMM D, YYYY')}</span>
                        <Badge
                            size='sm'
                            variant='soft'
                            tone={
                                referral.underReview && referral.status !== 'paid'
                                    ? 'warning'
                                    : statusTones[referral.status]
                            }
                        >
                            {referral.underReview && (referral.status === 'registered' || referral.status === 'held')
                                ? 'Under review'
                                : referralStatusLabels[referral.status]}
                        </Badge>
                    </div>
                </li>
            ))}
        </ul>
    );
}

/** Account → Referrals: the user's link, what it earned and who signed up through it. */
export default function ReferralsContainer() {
    const { data, error, refetch } = useReferrals();

    return (
        <PageContentBlock title='Referrals'>
            <PageHeading
                title='Referrals'
                description='Invite friends to MonoForge and earn tokens when they make their first purchase.'
            />
            {error && !data && <ServerError message={httpErrorToHuman(error)} onRetry={() => void refetch()} />}
            {!data && !error && <Spinner size='large' centered />}
            {data && !data.enabled && (
                <div className='mt-6'>
                    <Empty>
                        <EmptyHeader>
                            <EmptyMedia variant='icon'>
                                <Gift />
                            </EmptyMedia>
                            <EmptyTitle>Referrals are paused</EmptyTitle>
                            <EmptyDescription>
                                The referral programme is not running right now. Rewards you already earned are kept.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                </div>
            )}
            {data && (
                <div className='mt-6 grid gap-6'>
                    {data.enabled && <LinkBox overview={data} />}
                    <Stats overview={data} />
                    <ContentBox title='People you referred'>
                        <ReferralList referrals={data.referrals} />
                    </ContentBox>
                </div>
            )}
        </PageContentBlock>
    );
}
