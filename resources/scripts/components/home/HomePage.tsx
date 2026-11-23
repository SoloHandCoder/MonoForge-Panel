import './home.css';
import { Link } from '@tanstack/react-router';
import { useEffect, type ReactNode } from 'react';
import { LEGAL_DOCUMENT_IDS, useLegalDocuments } from '@/api/legal';
import { usePublicStorePlans, type PublicPlan } from '@/api/public/storePlans';
import { useSiteSettings } from '@/api/settings/queries';
import { LegalLink } from '@/components/legal/LegalLinks';
import { cn } from '@/lib/cn';
import MonoForgeMark from '@/themes/classic/views/brand/MonoForgeMark';
import { DISCORD_INVITE_URL } from '@/themes/classic/views/brand/links';
import { Main } from '@/views/a11y';
import { setPageTitle } from '@/views/pageTitle';
import HomeAddress from './HomeAddress';
import HomeHero from './HomeHero';
import HomePanel from './HomePanel';
import HomePaying from './HomePaying';
import blacksmith from './images/durand-blacksmith-vignette.jpg';
import HomePlans from './HomePlans';
import { rise, useReveal } from './useReveal';

export const HOME_TITLE = 'MonoForge | Game server hosting for Minecraft and Terraria';

const discordLink = { href: DISCORD_INVITE_URL, target: '_blank', rel: 'noopener noreferrer' } as const;

/** Where "Get started" goes: sign-up while the panel allows it, the sign-in page otherwise. */
function GetStarted({ className, children, plan }: { className: string; children: ReactNode; plan?: PublicPlan }) {
    const registration = useSiteSettings()?.registration?.enabled ?? false;

    if (registration) {
        return (
            <Link to='/auth/register' className={className}>
                {children}
            </Link>
        );
    }

    return (
        <Link to='/auth/login' search={plan ? { redirect: `/store/${plan.id}` } : {}} className={className}>
            {children}
        </Link>
    );
}

const NAV = [
    ['#games', 'Games'],
    ['#plans', 'Plans'],
    ['#panel', 'Panel'],
    ['#faq', 'FAQ'],
    ['/servers', 'Servers'],
] as const;

/** The plain top row: the wordmark, the sections, sign in and the one action. */
function Header() {
    return (
        <header className='home-wrap flex h-18 items-center justify-between gap-6'>
            <a href='#top' className='flex items-center gap-2.5 text-lg font-semibold text-(--home-ink) no-underline'>
                <MonoForgeMark className='size-8' />
                <span className='sr-only sm:not-sr-only'>MonoForge</span>
            </a>
            <nav aria-label='Homepage' className='hidden items-center gap-7 lg:flex'>
                {NAV.map(([href, label]) => (
                    <a key={href} href={href} className='home-link home-link--muted'>
                        {label}
                    </a>
                ))}
            </nav>
            <div className='flex items-center gap-5'>
                <Link to='/auth/login' className='home-link whitespace-nowrap'>
                    Sign in
                </Link>
                <GetStarted className='home-button home-button--small'>Get started</GetStarted>
            </div>
        </header>
    );
}

const FAQ: { question: string; answer: ReactNode }[] = [
    {
        question: 'How do tokens work?',
        answer: 'Tokens are the panel currency. Buy a pack in your wallet, then spend them on a server for the period you choose. Tokens never expire.',
    },
    {
        question: 'How fast is my server ready?',
        answer: 'Usually within a minute of checking out. The game is installed for you, so you only have to press start.',
    },
    {
        question: 'How do my friends join?',
        answer: 'Minecraft servers get an address of their own on play.monoforge.net, and every server has a playit.gg address. Share it and your friends connect straight away, with no port forwarding on your side.',
    },
    {
        question: 'Can I install mods and plugins?',
        answer: 'Yes. Install them from Modrinth and Hangar in a click, or upload your own and edit your files in the web file editor.',
    },
    {
        question: 'What happens when my period ends?',
        answer: 'The server renews from your token balance. If you run low you get a warning first, and you can top up at any time.',
    },
    {
        question: 'Where do the servers run?',
        answer: 'On our own machines in Switzerland. The status page shows how they are doing right now.',
    },
    {
        question: 'Can I get a refund?',
        answer: (
            <>
                Token purchases are final, but the tokens never expire. The details are in our{' '}
                <LegalLink document='refunds'>refund policy</LegalLink>.
            </>
        ),
    },
];

/** The questions, every answer open: large questions on the left, the answer level with each. */
function Faq() {
    const ref = useReveal<HTMLElement>();

    return (
        <section ref={ref} id='faq' data-reveal aria-labelledby='faq-title' className='home-section home-wrap'>
            <dl className='grid gap-y-16 sm:gap-y-20'>
                <div className='home-grid'>
                    <h2 id='faq-title' className='home-heading home-muted home-rise col-span-12 lg:col-span-6'>
                        Questions people ask
                    </h2>
                </div>
                {FAQ.map((item, index) => (
                    <div key={item.question} className='home-grid home-rise gap-y-3' style={rise(Math.min(index, 3))}>
                        <dt className='home-heading col-span-12 font-medium lg:col-span-6'>{item.question}</dt>
                        <dd className='home-body col-span-12 m-0 max-w-[44ch] lg:col-span-5 lg:col-start-8 lg:pt-2'>
                            {item.answer}
                        </dd>
                    </div>
                ))}
                <div className='home-grid'>
                    <p className='home-body col-span-12 lg:col-span-5 lg:col-start-8'>
                        More questions? The{' '}
                        <a href='/help' className='home-link underline'>
                            help center
                        </a>{' '}
                        has a guide for every part of the panel.
                    </p>
                </div>
            </dl>
        </section>
    );
}

function FooterColumn({ title, className, children }: { title: string; className: string; children: ReactNode }) {
    return (
        <div className={cn('flex flex-col items-start gap-2', className)}>
            <p className='home-body font-semibold'>{title}</p>
            {children}
        </div>
    );
}

/** The close and the footer on one turned-over field: the old promise once more, then the name at full width. */
function Close() {
    const documents = useLegalDocuments();

    return (
        <div className='home-paper'>
            <section
                aria-labelledby='home-cta-title'
                className='home-wrap flex min-h-[80svh] flex-col justify-between gap-16 pt-[clamp(4.5rem,10vw,9rem)] pb-16'
            >
                <h2 id='home-cta-title' className='home-monument home-close-title'>
                    Forge your own
                    <br />
                    game server
                </h2>
                <div className='home-grid items-end gap-y-10'>
                    <figure className='col-span-12 m-0 sm:col-span-6 lg:col-span-4'>
                        <img
                            src={blacksmith}
                            width={1080}
                            height={800}
                            alt='An engraving of a young smith at his anvil, a hot iron in the tongs, a locomotive passing outside.'
                            loading='lazy'
                            decoding='async'
                            className='block h-auto w-full mix-blend-multiply'
                        />
                        <figcaption className='home-body home-muted mt-3'>
                            A smith at the forge, banknote vignette by Asher B. Durand, about 1824.
                        </figcaption>
                    </figure>
                    <p className='home-lead col-span-12 max-w-[30ch] sm:col-span-6 lg:col-span-4 lg:col-start-6'>
                        Create an account, top up some tokens and your server is a minute away.
                    </p>
                    <div className='col-span-12 flex flex-wrap items-center gap-x-8 gap-y-4 lg:col-span-3 lg:col-start-10 lg:justify-end'>
                        <a {...discordLink} className='home-link underline'>
                            Ask us on Discord
                        </a>
                        <GetStarted className='home-button'>Get started</GetStarted>
                    </div>
                </div>
            </section>
            <footer className='home-wrap pt-16 pb-6'>
                <div className='home-grid gap-y-10'>
                    <FooterColumn title='MonoForge' className='col-span-6 md:col-span-3'>
                        <Link to='/auth/login' className='home-link home-link--muted'>
                            Sign in
                        </Link>
                        <a href='/status' className='home-link home-link--muted'>
                            Status
                        </a>
                        <a href='/help' className='home-link home-link--muted'>
                            Help center
                        </a>
                    </FooterColumn>
                    <FooterColumn title='Players' className='col-span-6 md:col-span-3'>
                        <a href='/servers' className='home-link home-link--muted'>
                            Discover servers
                        </a>
                        <a {...discordLink} className='home-link home-link--muted'>
                            Discord
                        </a>
                    </FooterColumn>
                    <FooterColumn title='Legal' className='col-span-6 md:col-span-3'>
                        {LEGAL_DOCUMENT_IDS.map((id) => (
                            <a
                                key={id}
                                href={documents[id].url}
                                target='_blank'
                                rel='noopener noreferrer'
                                className='home-link home-link--muted'
                            >
                                {{ terms: 'Terms', privacy: 'Privacy', refunds: 'Refunds' }[id]}
                            </a>
                        ))}
                    </FooterColumn>
                    <FooterColumn title='Pictures' className='col-span-6 md:col-span-3'>
                        <a
                            href='https://commons.wikimedia.org/wiki/File:Banknote_vignette_with_a_blacksmith_and_forge_MET_DP837932.jpg'
                            target='_blank'
                            rel='noopener noreferrer'
                            className='home-link home-link--muted'
                        >
                            Durand vignette, The Met, CC0
                        </a>
                    </FooterColumn>
                </div>
                <svg viewBox='0 0 1000 200' role='img' aria-label='MonoForge' className='mt-24 block w-full'>
                    <text
                        x='0'
                        y='150'
                        textLength='1000'
                        lengthAdjust='spacingAndGlyphs'
                        className='fill-current font-extrabold'
                        style={{ fontSize: 200, letterSpacing: '-0.05em' }}
                    >
                        MonoForge
                    </text>
                </svg>
                <p className='home-body home-muted mt-4'>
                    &copy; <span suppressHydrationWarning>{new Date().getFullYear()}</span> MonoForge. Game servers
                    hosted in Switzerland.
                </p>
            </footer>
        </div>
    );
}

/** The plans, live from the shop. */
function Plans({ plans }: { plans: ReturnType<typeof usePublicStorePlans> }) {
    const ref = useReveal<HTMLElement>();

    return (
        <section ref={ref} id='plans' data-reveal aria-labelledby='plans-title' className='home-section home-wrap'>
            <div className='home-grid mb-12 gap-y-4'>
                <h2 id='plans-title' className='home-heading home-rise col-span-12 lg:col-span-6'>
                    Plans and prices
                </h2>
                <p className='home-body home-rise col-span-12 lg:col-span-5 lg:col-start-8' style={rise(1)}>
                    Live from our store, in tokens. Pick a plan to see what it includes.
                </p>
            </div>
            <div className='home-rise' style={rise(2)}>
                <HomePlans
                    data={plans.data}
                    loading={plans.isPending}
                    action={(plan) => (
                        <GetStarted
                            plan={plan}
                            className='home-button w-full py-3 text-center whitespace-normal sm:w-auto'
                        >
                            Get started with {plan.name}
                        </GetStarted>
                    )}
                    fallback={
                        <div className='flex flex-col gap-6 bg-(--home-surface) p-8 sm:flex-row sm:items-center sm:justify-between'>
                            <div>
                                <p className='home-lead font-semibold'>New plans are being forged</p>
                                <p className='home-body mt-1'>Join our Discord to hear the moment they open.</p>
                            </div>
                            <a {...discordLink} className='home-button home-button--quiet'>
                                Join the Discord
                            </a>
                        </div>
                    }
                />
            </div>
        </section>
    );
}

/**
 * The public homepage at "/" for visitors who are not signed in: the name set edge to edge over a
 * running server's console, the games and the address a server gets from its name, the live plans,
 * the panel, how paying works, the questions and the close. Signed-in users get the dashboard.
 */
export default function HomePage() {
    const plans = usePublicStorePlans();
    const categories = plans.data?.enabled ? plans.data.categories : [];

    useEffect(() => {
        setPageTitle(HOME_TITLE);
    }, []);

    return (
        <div id='top' className='home relative isolate min-h-svh overflow-x-clip'>
            <Header />
            <Main>
                <HomeHero action={<GetStarted className='home-button'>Get started</GetStarted>} />
                <HomeAddress
                    categories={categories}
                    primary={<GetStarted className='home-button'>Get started</GetStarted>}
                    secondary={
                        <a href='/servers' className='home-button home-button--quiet'>
                            Browse servers
                        </a>
                    }
                />
                <Plans plans={plans} />
                <HomePanel assistant={plans.data?.assistantEnabled ?? false} />
                <HomePaying />
                <Faq />
            </Main>
            <Close />
        </div>
    );
}
