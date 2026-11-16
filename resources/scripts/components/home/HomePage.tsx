/* oxlint-disable rules/design-tokens -- the page backdrop and the hero glow are the brand's fixed sign-in look */
import './home.css';
import { Link } from '@tanstack/react-router';
import { useEffect, useRef, type CSSProperties, type ReactNode } from 'react';
import {
    Archive,
    ArrowRight,
    ChevronDown,
    FileCode2,
    Globe,
    MessagesSquare,
    Sparkles,
    SquareTerminal,
    Zap,
    type LucideIcon,
} from 'lucide-react';
import { usePublicStorePlans, type PublicCategory, type PublicPlan } from '@/api/public/storePlans';
import { useSiteSettings } from '@/api/settings/queries';
import Icon from '@/components/elements/Icon';
import LegalLinks, { LegalLink } from '@/components/legal/LegalLinks';
import { storeCardClass } from '@/components/store/PlanCard';
import { cn } from '@/lib/cn';
import MonoForgeMark from '@/themes/classic/views/brand/MonoForgeMark';
import { DISCORD_INVITE_URL } from '@/themes/classic/views/brand/links';
import { Main } from '@/views/a11y';
import { setPageTitle } from '@/views/pageTitle';
import { BlockArt, TreeArt } from './GameArt';
import HomeBackdrop, { Sparks } from './HomeBackdrop';
import HomePlans, { cheapestMonthly } from './HomePlans';

export const HOME_TITLE = 'MonoForge | Game server hosting for Minecraft and Terraria';

const primaryButton =
    'inline-flex items-center justify-center gap-2 rounded-xl bg-primary px-5 py-3 text-sm font-semibold text-primary-foreground no-underline shadow-lg shadow-primary/30 transition-[background-color,translate,box-shadow] duration-200 hover:-translate-y-0.5 hover:bg-primary/90 hover:shadow-xl hover:shadow-primary/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring';
const secondaryButton =
    'inline-flex items-center justify-center gap-2 rounded-xl border border-border bg-card/60 px-5 py-3 text-sm font-semibold text-foreground no-underline backdrop-blur transition-colors duration-200 hover:border-primary/50 hover:bg-card focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring';

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

/** Lifts a section in once it scrolls into view; shown at once where IntersectionObserver is missing. */
function useReveal<T extends HTMLElement>() {
    const ref = useRef<T>(null);

    useEffect(() => {
        const element = ref.current;

        if (!element) {
            return;
        }

        if (!('IntersectionObserver' in window)) {
            element.dataset.shown = '';

            return;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    element.dataset.shown = '';
                    observer.disconnect();
                }
            },
            { rootMargin: '0px 0px -10% 0px' }
        );

        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    return ref;
}

function Section({
    id,
    eyebrow,
    title,
    lead,
    children,
}: {
    id: string;
    eyebrow: string;
    title: string;
    lead?: string;
    children: ReactNode;
}) {
    const ref = useReveal<HTMLElement>();

    return (
        <section
            ref={ref}
            id={id}
            aria-labelledby={`${id}-title`}
            className='mx-auto w-full max-w-6xl scroll-mt-24 translate-y-6 px-4 py-16 opacity-0 transition-[opacity,translate] duration-700 ease-out data-shown:translate-y-0 data-shown:opacity-100 sm:px-6 sm:py-20'
        >
            <p className='text-xs font-semibold tracking-[0.2em] text-(--accent-soft) uppercase'>{eyebrow}</p>
            <h2 id={`${id}-title`} className='mt-2 font-header text-3xl font-bold text-foreground sm:text-4xl'>
                {title}
            </h2>
            {lead && <p className='mt-3 max-w-2xl text-base text-muted-foreground'>{lead}</p>}
            <div className='mt-10'>{children}</div>
        </section>
    );
}

/**
 * The hero: the anvil with its ember glowing and sparks rising, the tagline and the way in. The
 * backdrop pauses while the hero is off screen, and a soft glow follows the pointer.
 */
function Hero() {
    const ref = useRef<HTMLElement>(null);

    useEffect(() => {
        const element = ref.current;

        if (!element) {
            return;
        }

        const observer =
            'IntersectionObserver' in window
                ? new IntersectionObserver(([entry]) => {
                      if (entry.isIntersecting) {
                          delete element.dataset.paused;
                      } else {
                          element.dataset.paused = '';
                      }
                  })
                : null;

        observer?.observe(element);

        const still = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
        let frame = 0;
        const follow = (event: PointerEvent) => {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(() => {
                const box = element.getBoundingClientRect();

                element.style.setProperty('--home-x', `${event.clientX - box.left}px`);
                element.style.setProperty('--home-y', `${event.clientY - box.top}px`);
            });
        };

        if (!still) {
            element.addEventListener('pointermove', follow, { passive: true });
        }

        return () => {
            observer?.disconnect();
            cancelAnimationFrame(frame);
            element.removeEventListener('pointermove', follow);
        };
    }, []);

    return (
        <section
            ref={ref}
            aria-labelledby='home-title'
            className='relative isolate overflow-hidden px-4 pt-28 pb-20 sm:px-6 sm:pt-36 sm:pb-28'
        >
            <HomeBackdrop />
            <div className='relative z-10 mx-auto flex max-w-3xl flex-col items-center text-center'>
                <div className='animate-rise'>
                    <div className='home-bob relative'>
                        <div className='home-ember-glow absolute inset-0 -z-10 rounded-full bg-[radial-gradient(circle,rgba(255,138,31,.35),rgba(139,92,246,.18)_45%,transparent_70%)]' />
                        <Sparks />
                        <MonoForgeMark
                            title='MonoForge'
                            className='size-36 drop-shadow-[0_18px_40px_rgba(139,92,246,.45)] sm:size-48'
                        />
                    </div>
                </div>
                <p
                    className='mt-8 inline-flex animate-rise items-center gap-2 rounded-full border border-primary/40 bg-(--active-item) px-3 py-1 text-xs font-medium text-(--accent-soft)'
                    style={{ '--rise-delay': '0.1s' } as CSSProperties}
                >
                    <span
                        className='size-1.5 rounded-full bg-[#ffb02e] shadow-[0_0_6px_1px_rgba(255,176,46,.8)]'
                        aria-hidden
                    />
                    Minecraft Java and Terraria servers
                </p>
                <h1
                    id='home-title'
                    className='mt-5 animate-rise font-header text-4xl font-bold tracking-tight text-balance text-foreground sm:text-6xl'
                    style={{ '--rise-delay': '0.18s' } as CSSProperties}
                >
                    Forge your own{' '}
                    <span className='bg-linear-to-r from-[#c4b5fd] via-[#a78bfa] to-[#ffb02e] bg-clip-text text-transparent'>
                        game server
                    </span>
                </h1>
                <p
                    className='mt-5 max-w-xl animate-rise text-base text-pretty text-muted-foreground sm:text-lg'
                    style={{ '--rise-delay': '0.26s' } as CSSProperties}
                >
                    Pick a plan, hit create and play in about a minute. Share a ready-made address with your friends and
                    run everything from your browser.
                </p>
                <div
                    className='mt-8 flex w-full animate-rise flex-col justify-center gap-3 sm:w-auto sm:flex-row'
                    style={{ '--rise-delay': '0.34s' } as CSSProperties}
                >
                    <GetStarted className={primaryButton}>
                        Get started
                        <Icon icon={ArrowRight} className='size-4' aria-hidden />
                    </GetStarted>
                    <a href='#plans' className={secondaryButton}>
                        See plans
                    </a>
                </div>
            </div>
            <a
                href='#games'
                aria-label='Scroll to the games'
                className='absolute bottom-6 left-1/2 z-10 hidden -translate-x-1/2 text-muted-foreground no-underline hover:text-foreground sm:block'
            >
                <Icon icon={ChevronDown} className='home-bob size-6' aria-hidden />
            </a>
        </section>
    );
}

interface Game {
    name: string;
    match: string;
    blurb: string;
    art: ReactNode;
    tint: string;
}

const FEATURED_GAMES: Game[] = [
    {
        name: 'Minecraft: Java Edition',
        match: 'minecraft',
        blurb: 'Survival with friends, a modded world or a plugin-packed server, on the server type you pick.',
        art: <BlockArt className='size-20 drop-shadow-[0_10px_20px_rgba(95,191,58,.35)]' />,
        tint: 'from-[rgba(95,191,58,.18)]',
    },
    {
        name: 'Terraria',
        match: 'terraria',
        blurb: 'Dig, build and fight bosses together on a dedicated world that stays online when you log off.',
        art: <TreeArt className='size-20 drop-shadow-[0_10px_20px_rgba(255,176,46,.3)]' />,
        tint: 'from-[rgba(255,176,46,.16)]',
    },
];

function GameCard({ game, category, index }: { game: Game; category: PublicCategory | undefined; index: number }) {
    const from = category ? cheapestMonthly(category) : null;

    return (
        <article
            className={cn(storeCardClass, 'overflow-hidden p-6')}
            style={{ '--rise-delay': `${index * 0.1}s` } as CSSProperties}
        >
            <div
                className={cn(
                    'pointer-events-none absolute inset-0 bg-linear-to-br to-transparent opacity-80',
                    game.tint
                )}
                aria-hidden
            />
            <div className='relative flex items-start gap-5'>
                <div className='shrink-0 transition-transform duration-300 group-hover:-rotate-6 group-hover:scale-110'>
                    {game.art}
                </div>
                <div className='min-w-0'>
                    <h3 className='font-header text-xl font-semibold text-foreground'>{game.name}</h3>
                    <p className='mt-2 text-sm text-muted-foreground'>{game.blurb}</p>
                    <p className='mt-4 text-sm font-medium text-(--accent-soft)'>
                        {from === null ? 'Plans coming soon' : `From ${from.toLocaleString('en-US')} tokens a month`}
                    </p>
                </div>
            </div>
        </article>
    );
}

function Games({ categories }: { categories: PublicCategory[] }) {
    const find = (match: string) => categories.find((category) => category.slug.toLowerCase().includes(match));
    const others = categories.filter(
        (category) => !FEATURED_GAMES.some((game) => category.slug.toLowerCase().includes(game.match))
    );

    return (
        <Section
            id='games'
            eyebrow='Games we host'
            title='Start with the classics'
            lead='Minecraft Java and Terraria first, with more games on the way.'
        >
            <div className='grid gap-5 md:grid-cols-2'>
                {FEATURED_GAMES.map((game, index) => (
                    <GameCard key={game.match} game={game} category={find(game.match)} index={index} />
                ))}
            </div>
            {others.length > 0 && (
                <ul className='mt-5 flex flex-wrap gap-2' aria-label='Also available'>
                    {others.map((category) => (
                        <li
                            key={category.slug}
                            className='rounded-full border border-border bg-card px-3 py-1 text-sm text-foreground'
                        >
                            {category.name}
                        </li>
                    ))}
                </ul>
            )}
        </Section>
    );
}

interface Feature {
    icon: LucideIcon;
    title: string;
    text: string;
}

/**
 * The feature cards: six in either case, so the two and three column grids never end on a gap.
 * Without the AI assistant, the live console takes its place.
 */
function features(assistant: boolean): Feature[] {
    return [
        {
            icon: Zap,
            title: 'Instant setup',
            text: 'Your server is created the moment you check out, with the game installed and ready to start.',
        },
        {
            icon: Globe,
            title: 'Ready-made addresses',
            text: 'Every server gets a playit.gg address, so friends join without port forwarding or an IP to share.',
        },
        assistant
            ? {
                  icon: Sparkles,
                  title: 'AI assistant',
                  text: 'Ask for help in plain words, right inside the panel, when a config or a crash has you stuck.',
              }
            : {
                  icon: SquareTerminal,
                  title: 'Live console',
                  text: 'Colour-coded logs you can search, command history and live CPU, memory and network graphs.',
              },
        {
            icon: FileCode2,
            title: 'Web file editor',
            text: 'Edit configs, plugins and worlds in a code editor in your browser. No FTP client needed.',
        },
        {
            icon: Archive,
            title: 'Backups',
            text: 'Take a backup before a big change and roll back in a click if something goes wrong.',
        },
        {
            icon: MessagesSquare,
            title: 'Discord support',
            text: 'Real people on our Discord to help with setup, mods and anything else you get stuck on.',
        },
    ];
}

function Features({ assistant }: { assistant: boolean }) {
    return (
        <Section
            id='features'
            eyebrow='Why MonoForge'
            title='Everything you need, nothing to install'
            lead='A panel built for players: fast to start, easy to run.'
        >
            <ul className='grid gap-5 sm:grid-cols-2 lg:grid-cols-3'>
                {features(assistant).map((feature, index) => (
                    <li
                        key={feature.title}
                        className={cn(storeCardClass, 'p-6')}
                        style={{ '--rise-delay': `${Math.min(index * 0.06, 0.4)}s` } as CSSProperties}
                    >
                        <span className='grid size-11 place-items-center rounded-xl bg-(--active-item) text-(--accent-soft) transition-transform duration-300 group-hover:scale-110 group-hover:rotate-6'>
                            <Icon icon={feature.icon} className='size-5' aria-hidden />
                        </span>
                        <h3 className='mt-4 font-header text-lg font-semibold text-foreground'>{feature.title}</h3>
                        <p className='mt-1.5 text-sm text-muted-foreground'>{feature.text}</p>
                    </li>
                ))}
            </ul>
        </Section>
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
        answer: 'Your server comes with a playit.gg address. Share it and your friends connect straight away, with no port forwarding on your side.',
    },
    {
        question: 'Can I install mods and plugins?',
        answer: 'Yes. Upload them or edit your files in the web file editor, all from your browser.',
    },
    {
        question: 'What happens when my period ends?',
        answer: 'The server renews from your token balance. If you run low you get a warning first, and you can top up at any time.',
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

function Faq() {
    return (
        <Section id='faq' eyebrow='FAQ' title='Questions, answered'>
            <div className='mx-auto grid max-w-3xl gap-3'>
                {FAQ.map((item) => (
                    <details
                        key={item.question}
                        className='group rounded-2xl border border-border bg-card px-5 py-4 transition-colors duration-200 open:border-primary/50'
                    >
                        <summary className='flex cursor-pointer list-none items-center justify-between gap-4 font-medium text-foreground [&::-webkit-details-marker]:hidden'>
                            {item.question}
                            <Icon
                                icon={ChevronDown}
                                className='size-4 shrink-0 text-muted-foreground transition-transform duration-200 group-open:rotate-180'
                                aria-hidden
                            />
                        </summary>
                        <p className='mt-3 text-sm text-muted-foreground'>{item.answer}</p>
                    </details>
                ))}
                <p className='mt-2 text-center text-sm text-muted-foreground'>
                    More questions? The{' '}
                    <a href='/help' className='font-medium text-(--accent-soft) underline-offset-2 hover:underline'>
                        help center
                    </a>{' '}
                    has a guide for every part of the panel.
                </p>
            </div>
        </Section>
    );
}

function Header() {
    return (
        <header className='fixed inset-x-0 top-0 z-30 border-b border-transparent bg-[rgba(14,13,21,.65)] backdrop-blur-md'>
            <div className='mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6'>
                <a
                    href='#top'
                    className='flex items-center gap-2.5 font-header text-lg font-semibold tracking-wide text-foreground no-underline'
                >
                    <span aria-hidden>
                        <MonoForgeMark className='size-8' />
                    </span>
                    MonoForge
                </a>
                <nav aria-label='Homepage' className='hidden items-center gap-6 text-sm md:flex'>
                    {[
                        ['#games', 'Games'],
                        ['#plans', 'Plans'],
                        ['#features', 'Features'],
                        ['#faq', 'FAQ'],
                        ['/servers', 'Servers'],
                    ].map(([href, label]) => (
                        <a key={href} href={href} className='text-muted-foreground no-underline hover:text-foreground'>
                            {label}
                        </a>
                    ))}
                </nav>
                <div className='flex items-center gap-2'>
                    <Link
                        to='/auth/login'
                        className='rounded-lg px-3 py-2 text-sm font-medium text-foreground no-underline hover:bg-muted'
                    >
                        Sign in
                    </Link>
                    <GetStarted className='hidden rounded-lg bg-primary px-3.5 py-2 text-sm font-semibold text-primary-foreground no-underline hover:bg-primary/90 sm:inline-flex'>
                        Get started
                    </GetStarted>
                </div>
            </div>
        </header>
    );
}

function Footer() {
    return (
        <footer className='border-t border-border'>
            <div className='mx-auto flex max-w-6xl flex-col items-center justify-between gap-4 px-4 py-8 text-sm text-muted-foreground sm:flex-row sm:px-6'>
                <p className='flex items-center gap-2'>
                    <span aria-hidden>
                        <MonoForgeMark className='size-6' />
                    </span>
                    &copy; <span suppressHydrationWarning>{new Date().getFullYear()}</span> MonoForge
                </p>
                <div className='flex flex-wrap items-center justify-center gap-x-4 gap-y-2'>
                    <a href='/help' className='text-muted-foreground no-underline hover:text-foreground'>
                        Help center
                    </a>
                    <a href='/servers' className='text-muted-foreground no-underline hover:text-foreground'>
                        Discover servers
                    </a>
                    <LegalLinks className='text-sm' />
                    <a {...discordLink} className='font-medium text-(--accent-soft) no-underline hover:text-foreground'>
                        Discord
                    </a>
                </div>
            </div>
        </footer>
    );
}

/**
 * The public homepage at "/" for visitors who are not signed in: the hero, the games, the live
 * plans from the shop, the features, the FAQ and the footer. Signed-in users get the dashboard.
 */
export default function HomePage() {
    const plans = usePublicStorePlans();
    const categories = plans.data?.enabled ? plans.data.categories : [];

    useEffect(() => {
        setPageTitle(HOME_TITLE);
    }, []);

    return (
        <div
            id='top'
            className='relative isolate min-h-screen overflow-x-clip bg-[radial-gradient(120%_80%_at_80%_0%,#241a44,#0e0d15_60%)] text-foreground'
        >
            <Header />
            <Main className='relative'>
                <Hero />
                <Games categories={categories} />
                <Section
                    id='plans'
                    eyebrow='Plans'
                    title='Pick your plan'
                    lead='Live prices from our store, in tokens. Longer periods cost less per month.'
                >
                    <HomePlans
                        data={plans.data}
                        loading={plans.isPending}
                        action={(plan) => (
                            <GetStarted plan={plan} className={cn(primaryButton, 'w-full py-2.5')}>
                                Get started
                                <Icon icon={ArrowRight} className='size-4' aria-hidden />
                                <span className='sr-only'> with {plan.name}</span>
                            </GetStarted>
                        )}
                        fallback={
                            <div className='rounded-2xl border border-border bg-card p-8 text-center'>
                                <p className='font-header text-lg font-semibold text-foreground'>
                                    New plans are being forged
                                </p>
                                <p className='mt-2 text-sm text-muted-foreground'>
                                    Join our Discord to hear the moment they open.
                                </p>
                                <a {...discordLink} className={cn(secondaryButton, 'mt-5')}>
                                    Join the Discord
                                </a>
                            </div>
                        }
                    />
                </Section>
                <Features assistant={plans.data?.assistantEnabled ?? false} />
                <Faq />
                <section className='px-4 pb-20 sm:px-6' aria-labelledby='home-cta-title'>
                    <div className='relative mx-auto max-w-4xl overflow-hidden rounded-3xl border border-primary/40 bg-[radial-gradient(120%_140%_at_50%_0%,#3b2a73,#17132a_70%)] px-6 py-12 text-center shadow-2xl shadow-primary/20'>
                        <HomeBackdrop count={12} className='opacity-70' />
                        <div className='relative z-10'>
                            <h2 id='home-cta-title' className='font-header text-3xl font-bold text-foreground'>
                                Ready to forge?
                            </h2>
                            <p className='mt-3 text-muted-foreground'>
                                Create an account, top up some tokens and your server is a minute away.
                            </p>
                            <div className='mt-7 flex flex-col justify-center gap-3 sm:flex-row'>
                                <GetStarted className={primaryButton}>
                                    Get started
                                    <Icon icon={ArrowRight} className='size-4' aria-hidden />
                                </GetStarted>
                                <a {...discordLink} className={secondaryButton}>
                                    Ask us on Discord
                                </a>
                            </div>
                        </div>
                    </div>
                </section>
            </Main>
            <Footer />
        </div>
    );
}
