import { Coins, Sparkles } from 'lucide-react';
import type { AssistantStatus, AssistantWarning } from '@/api/assistant';
import { formatTokens } from '@/api/account/wallet/queries';
import { Alert } from '@/components/elements/alert';
import Icon from '@/components/elements/Icon';
import ProgressBar from '@/components/elements/ProgressBar';
import { PanelLink } from '@/sdk/navigation';
import type { UiTone } from '@/themes/skins';

const banners = {
    near_limit: {
        type: 'warning',
        title: 'Your free AI tokens are running low',
        body: 'Once they run out, the assistant is paid from your token balance.',
    },
    free_exhausted: {
        type: 'warning',
        title: "You've used this month's free AI tokens",
        body: 'The assistant is now paid from your token balance.',
    },
    no_balance: {
        type: 'danger',
        title: 'Out of AI tokens',
        body: 'Your free AI tokens for this month are used up and your balance is empty. Buy tokens to keep chatting.',
    },
} as const satisfies Record<AssistantWarning, { type: 'warning' | 'danger'; title: string; body: string }>;

/** The allowance warning above the chat, with a link to the wallet. Renders nothing without a warning. */
export function AssistantWarningBanner({ warning }: { warning: AssistantWarning | null }) {
    if (warning === null) {
        return null;
    }

    const banner = banners[warning];

    return (
        <div data-testid='assistant-warning' data-warning={warning} className='animate-fade-in'>
            <Alert type={banner.type} title={banner.title}>
                {banner.body}{' '}
                <PanelLink destination={{ to: '/account/wallet' }} className='font-medium text-primary underline'>
                    Open your wallet
                </PanelLink>
            </Alert>
        </div>
    );
}

const meterTone = (percent: number): UiTone => {
    if (percent >= 100) {
        return 'danger';
    }

    return percent >= 80 ? 'warning' : 'accent';
};

/** Free tokens used this month against the limit, the AI-to-panel token ratio, and the balance. */
export function AssistantUsageMeter({ status }: { status: AssistantStatus }) {
    const percent = status.free_limit > 0 ? Math.min(100, (status.free_used / status.free_limit) * 100) : 100;

    return (
        <div className='rounded-xl border border-border bg-card p-4' data-testid='assistant-usage'>
            <div className='flex items-center justify-between gap-2 text-sm'>
                <span className='flex items-center gap-2 font-medium text-foreground'>
                    <Icon icon={Sparkles} className='size-4 text-primary' aria-hidden />
                    Free this month
                </span>
                <span className='text-xs text-muted-foreground tabular-nums'>
                    {formatTokens(Math.min(status.free_used, status.free_limit))} / {formatTokens(status.free_limit)}
                </span>
            </div>
            <ProgressBar
                className='mt-3'
                value={percent}
                tone={meterTone(percent)}
                label='Free AI tokens used this month'
            />
            <dl className='mt-3 grid grid-cols-2 gap-2 text-xs'>
                <div>
                    <dt className='text-muted-foreground'>Then</dt>
                    <dd className='text-foreground tabular-nums'>{formatTokens(status.ratio)} AI tokens = 1 token</dd>
                </div>
                <div className='text-right'>
                    <dt className='text-muted-foreground'>Balance</dt>
                    <dd>
                        <PanelLink
                            destination={{ to: '/account/wallet' }}
                            className='inline-flex items-center gap-1 text-foreground tabular-nums no-underline hover:text-primary'
                        >
                            <Icon icon={Coins} className='size-3.5 text-(--accent-soft)' aria-hidden />
                            {formatTokens(status.balance)}
                        </PanelLink>
                    </dd>
                </div>
            </dl>
        </div>
    );
}
