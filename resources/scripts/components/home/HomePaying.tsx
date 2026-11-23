import type { ReactNode } from 'react';
import { EXAMPLE_SLUG } from './HomeHero';
import { rise, useReveal } from './useReveal';

/** The wallet: a token pack bought, a plan paid, and the balance they leave. */
function WalletFragment() {
    const lines = [
        { what: 'Token pack, paid through Tebex', amount: '+1,200' },
        { what: 'Starter, Minecraft, 30 days', amount: '−300' },
    ];

    return (
        <div
            data-ui
            role='img'
            aria-label='The wallet: 350 tokens, a 1,200 token pack bought, 300 spent on a plan, 1,250 left.'
        >
            <div className='ui-bar justify-between'>
                <span className='font-semibold'>Wallet</span>
                <span className='ui-muted'>Tokens never expire</span>
            </div>
            <div className='ui-row'>
                <span className='ui-muted flex-1'>Balance before</span>
                <span className='tabular-nums'>350</span>
            </div>
            {lines.map((line) => (
                <div key={line.what} className='ui-row'>
                    <span className='flex-1'>{line.what}</span>
                    <span className='tabular-nums'>{line.amount}</span>
                </div>
            ))}
            <div className='ui-row font-semibold'>
                <span className='flex-1'>Balance</span>
                <span className='tabular-nums'>1,250 tokens</span>
            </div>
        </div>
    );
}

/** A renewal in the ledger after a month of auto-sleep, in the wording the panel writes. */
function SleepFragment() {
    return (
        <div data-ui role='img' aria-label='A renewal in the wallet history: 30 days, 84 tokens saved by sleeping.'>
            <div className='ui-bar justify-between'>
                <span className='font-semibold'>{EXAMPLE_SLUG}</span>
                <span className='ui-pill ui-pill--sleep'>Asleep</span>
            </div>
            <div className='ui-row'>
                <span className='ui-muted flex-1'>Empty since 01:12, asleep since 01:27</span>
            </div>
            <div className='ui-row'>
                <span className='flex-1'>Renewal of {EXAMPLE_SLUG} (30 days), 84 tokens saved by sleeping</span>
                <span className='tabular-nums'>−216</span>
            </div>
            <div className='ui-row'>
                <span className='ui-muted flex-1'>Anyone can wake it from its page</span>
                <span className='ui-button'>Wake server</span>
            </div>
        </div>
    );
}

interface Stage {
    lead: string;
    text: ReactNode;
    evidence?: ReactNode;
}

const STAGES: Stage[] = [
    {
        lead: 'First, you buy tokens.',
        text: 'Token packs are in your wallet and paid through Tebex. Tokens never expire, so a pack bought in the holidays still works in spring. Token purchases are final.',
        evidence: <WalletFragment />,
    },
    {
        lead: 'Then you spend them on a plan,',
        text: 'for the period you choose. Longer periods cost less a month, some plans can be tried free first, and the server is made the moment you check out, usually ready within a minute.',
    },
    {
        lead: 'While nobody plays,',
        text: 'plans with auto-sleep put an empty server to sleep, and the time it sleeps costs less when the plan renews. It wakes when you start it, or when a friend presses wake on its page.',
        evidence: <SleepFragment />,
    },
    {
        lead: 'When the period ends,',
        text: 'it renews from your balance. If you run low you get a warning first, and you can top up any time.',
    },
    {
        lead: 'All of it runs',
        text: 'on our own machines in Switzerland.',
    },
];

/** How paying works, told in order in one column, each step with the screen it happens on. */
export default function HomePaying() {
    const ref = useReveal<HTMLElement>();

    return (
        <section ref={ref} id='tokens' data-reveal aria-labelledby='tokens-title' className='home-section home-wrap'>
            <div className='home-grid'>
                <div className='col-span-12 md:col-span-10 md:col-start-2 lg:col-span-7 lg:col-start-3'>
                    <h2 id='tokens-title' className='home-heading home-rise'>
                        How paying works
                    </h2>
                    <ol className='mt-12 grid gap-16'>
                        {STAGES.map((stage, index) => (
                            <li key={stage.lead} className='home-rise' style={rise(Math.min(index, 2))}>
                                <p className='home-lead'>
                                    <strong className='font-semibold'>{stage.lead}</strong> <span>{stage.text}</span>
                                </p>
                                {stage.evidence && <div className='mt-6'>{stage.evidence}</div>}
                            </li>
                        ))}
                    </ol>
                </div>
            </div>
        </section>
    );
}
