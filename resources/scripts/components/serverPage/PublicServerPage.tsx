/* oxlint-disable rules/design-tokens -- the page backdrop, card radius and owner-chosen banner colour are the public pages' fixed look */
import { useParams } from '@tanstack/react-router';
import { isAxiosError } from 'axios';
import { Check, Copy, ExternalLink, Gamepad2, Globe, Heart, MessageCircle, Moon, Power, Users } from 'lucide-react';
import { type CSSProperties, useEffect, useState } from 'react';
import {
    publicServerPageInput,
    retryAfterSeconds,
    usePublicServerPage,
    useVoteForServer,
    useWakeServer,
    type PublicServerPage as PublicPage,
} from '@/api/public/serverPage';
import Icon from '@/components/elements/Icon';
import Spinner from '@/components/elements/Spinner';
import copyToClipboard from '@/lib/clipboard';
import { cn } from '@/lib/cn';
import { formatDate } from '@/lib/dates';
import Markdown from '@/lib/markdown';
import { accentButtonStyle, accentVars, themeStyle } from '@/lib/serverPageTheme';
import { statusStyles } from '@/components/serverPage/status';
import { useCaptcha } from '@/views/auth/captcha';
import { Main } from '@/views/a11y';
import { setPageTitle } from '@/views/pageTitle';

/** Only https links reach the page; the panel checked them when they were saved and again when sent. */
const safeLink = (url: string | null): string | null => (url && /^https:\/\/[^\s"'<>]+$/.test(url) ? url : null);

function StatusPill({ page }: { page: PublicPage }) {
    const style = statusStyles[page.status];

    return (
        <span
            role='status'
            className='inline-flex items-center gap-2 rounded-full border border-border bg-card/80 px-3 py-1.5 text-sm font-medium backdrop-blur'
        >
            <span className='relative flex size-2.5'>
                {style.ping && (
                    <span
                        className={cn(
                            'absolute inline-flex size-full rounded-full opacity-60 motion-safe:animate-ping',
                            style.dot
                        )}
                    />
                )}
                <span className={cn('relative inline-flex size-2.5 rounded-full', style.dot)} />
            </span>
            <span className={style.text}>{style.label}</span>
            {page.players && (
                <span className='text-muted-foreground'>
                    <span aria-hidden> · </span>
                    {page.players.online} / {page.players.max} players
                </span>
            )}
        </span>
    );
}

function JoinCard({ page }: { page: PublicPage }) {
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        if (!copied) {
            return;
        }

        const timer = window.setTimeout(() => setCopied(false), 2000);

        return () => window.clearTimeout(timer);
    }, [copied]);

    if (!page.address) {
        return (
            <section aria-labelledby='join-heading' className='rounded-[18px] border border-border bg-card p-5 sm:p-6'>
                <h2 id='join-heading' className='text-sm font-semibold tracking-wide text-muted-foreground uppercase'>
                    How to join
                </h2>
                <p className='mt-2 text-sm text-muted-foreground'>
                    This server has no public address yet. Ask the owner how to join.
                </p>
            </section>
        );
    }

    const address = page.address;

    return (
        <section aria-labelledby='join-heading' className='rounded-[18px] border border-border bg-card p-5 sm:p-6'>
            <h2 id='join-heading' className='text-sm font-semibold tracking-wide text-muted-foreground uppercase'>
                How to join
            </h2>
            <div className='mt-3 flex flex-col gap-3 sm:flex-row sm:items-center'>
                <code className='min-w-0 flex-1 truncate rounded-lg border border-border bg-background/60 px-3 py-2.5 font-mono text-sm text-foreground'>
                    {address}
                </code>
                <div className='flex gap-2'>
                    <button
                        type='button'
                        onClick={async () => setCopied(await copyToClipboard(address))}
                        style={accentButtonStyle}
                        className='inline-flex flex-1 items-center justify-center gap-2 px-4 py-2.5 text-sm font-semibold transition-opacity hover:opacity-90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:flex-none'
                    >
                        <Icon icon={copied ? Check : Copy} aria-hidden className='size-4' />
                        {copied ? 'Copied' : 'Copy address'}
                    </button>
                    {page.join_url && (
                        <a
                            href={page.join_url}
                            style={{ borderRadius: 'var(--page-radius)' }}
                            className='inline-flex flex-1 items-center justify-center gap-2 border border-border px-4 py-2.5 text-sm font-semibold text-foreground no-underline transition-colors hover:bg-muted sm:flex-none'
                        >
                            <Icon icon={Gamepad2} aria-hidden className='size-4' />
                            Add in game
                        </a>
                    )}
                </div>
            </div>
            <span aria-live='polite' className='sr-only'>
                {copied ? 'Address copied' : ''}
            </span>
        </section>
    );
}

function VoteCard({ page, slug }: { page: PublicPage; slug: string }) {
    const vote = useVoteForServer(slug);
    const result = vote.data;

    let message: string | null = null;

    if (result) {
        message = result.counted
            ? 'Thanks for your vote!'
            : `You already voted. Vote again ${formatDate(result.next_vote_at, 'D MMM, HH:mm')}.`;
    } else if (vote.isError) {
        message = 'Your vote could not be counted. Please try again later.';
    }

    return (
        <section
            aria-labelledby='vote-heading'
            className='flex flex-col gap-4 rounded-[18px] border border-border bg-card p-5 sm:flex-row sm:items-center sm:p-6'
        >
            <div className='min-w-0 flex-1'>
                <h2 id='vote-heading' className='text-sm font-semibold tracking-wide text-muted-foreground uppercase'>
                    Votes
                </h2>
                <p className='mt-1 text-2xl font-semibold text-foreground tabular-nums'>{page.votes}</p>
                <p aria-live='polite' className='mt-1 text-sm text-muted-foreground'>
                    {message ?? 'Like this server? You can vote once a day.'}
                </p>
            </div>
            <button
                type='button'
                disabled={vote.isPending || result !== undefined}
                onClick={() => vote.mutate({ ...publicServerPageInput(slug), body: { vote: true } })}
                style={{ borderRadius: 'var(--page-radius)' }}
                className='inline-flex items-center justify-center gap-2 border border-border px-4 py-2.5 text-sm font-semibold text-foreground transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60'
            >
                <Icon
                    icon={Heart}
                    aria-hidden
                    className={cn('size-4', result?.counted && 'fill-destructive text-destructive')}
                />
                {result?.counted ? 'Voted' : 'Vote'}
            </button>
        </section>
    );
}

function WakeCard({ slug, onWoken }: { slug: string; onWoken: () => void }) {
    const wake = useWakeServer();
    const { Captcha, token } = useCaptcha('wake');
    const [waitSeconds, setWaitSeconds] = useState<number | null>(null);

    const start = async () => {
        const captcha = await token();

        if (captcha === null) {
            return;
        }

        wake.mutate(
            { ...publicServerPageInput(slug), body: captcha === '' ? {} : { 'g-recaptcha-response': captcha } },
            {
                onSuccess: (result) => {
                    setWaitSeconds(null);
                    if (result.woken) {
                        onWoken();
                    }
                },
                onError: (error) => setWaitSeconds(retryAfterSeconds(error)),
            }
        );
    };

    let message = 'The server is asleep to save resources. Wake it up and it will be ready in a minute or two.';

    if (wake.data?.woken) {
        message = 'Waking up. The page updates by itself when the server is online.';
    } else if (waitSeconds !== null) {
        message = `It was woken a moment ago. Try again in ${waitSeconds} seconds.`;
    } else if (wake.isError) {
        message = 'The server could not be woken. Please try again later.';
    }

    return (
        <section
            aria-labelledby='wake-heading'
            className='flex flex-col gap-4 rounded-[18px] border border-primary/40 bg-primary/10 p-5 sm:flex-row sm:items-center sm:p-6'
        >
            <Icon icon={Moon} aria-hidden className='size-7 shrink-0 text-primary max-sm:hidden' />
            <div className='min-w-0 flex-1'>
                <h2 id='wake-heading' className='font-semibold text-foreground'>
                    Sleeping
                </h2>
                <p aria-live='polite' className='mt-1 text-sm text-muted-foreground'>
                    {message}
                </p>
            </div>
            <button
                type='button'
                disabled={wake.isPending || wake.data?.woken === true}
                onClick={() => void start()}
                style={accentButtonStyle}
                className='inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-semibold transition-opacity hover:opacity-90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60'
            >
                <Icon icon={Power} aria-hidden className='size-4' />
                {wake.isPending ? 'Waking…' : 'Wake server'}
            </button>
            {Captcha && <Captcha />}
        </section>
    );
}

function Links({ page }: { page: PublicPage }) {
    const discord = safeLink(page.links.discord);
    const website = safeLink(page.links.website);

    if (!discord && !website) {
        return null;
    }

    const linkClass =
        'inline-flex items-center gap-2 border border-border bg-card/80 px-3 py-1.5 text-sm font-medium text-foreground no-underline transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';

    return (
        <div className='flex flex-wrap gap-2'>
            {discord && (
                <a
                    href={discord}
                    target='_blank'
                    rel='nofollow ugc noopener noreferrer'
                    style={{ borderRadius: 'var(--page-radius)' }}
                    className={linkClass}
                >
                    <Icon icon={MessageCircle} aria-hidden className='size-4' />
                    Discord
                    <Icon icon={ExternalLink} aria-hidden className='size-3.5 text-muted-foreground' />
                </a>
            )}
            {website && (
                <a
                    href={website}
                    target='_blank'
                    rel='nofollow ugc noopener noreferrer'
                    style={{ borderRadius: 'var(--page-radius)' }}
                    className={linkClass}
                >
                    <Icon icon={Globe} aria-hidden className='size-4' />
                    Website
                    <Icon icon={ExternalLink} aria-hidden className='size-3.5 text-muted-foreground' />
                </a>
            )}
        </div>
    );
}

/** Everything a published page shows, below the site header, in the owner's colours. */
export function PublicServerView({ page, slug, onWoken }: { page: PublicPage; slug: string; onWoken: () => void }) {
    const details = [page.game, page.software, page.version].filter((value): value is string => !!value);

    return (
        <div className='flex flex-col gap-5' style={accentVars(page.theme)}>
            <section className='overflow-hidden rounded-[18px] border border-border bg-card shadow-lg motion-safe:animate-rise'>
                <div
                    data-testid='server-banner'
                    className='flex min-h-40 flex-col justify-end gap-1 bg-cover bg-center px-5 pt-12 pb-5 sm:min-h-52 sm:px-6'
                    style={themeStyle(page.theme)}
                >
                    <h1 className='text-2xl font-bold break-words sm:text-3xl'>{page.name}</h1>
                    {page.tagline !== '' && <p className='text-base break-words opacity-90'>{page.tagline}</p>}
                </div>
                <div className='flex flex-col gap-3 px-5 py-4 sm:px-6'>
                    <div className='flex flex-wrap items-center gap-2'>
                        <StatusPill page={page} />
                        {details.map((detail) => (
                            <span
                                key={detail}
                                className='rounded-full border border-border px-3 py-1.5 text-sm text-muted-foreground'
                            >
                                {detail}
                            </span>
                        ))}
                    </div>
                    <Links page={page} />
                </div>
            </section>

            {page.can_wake && <WakeCard slug={slug} onWoken={onWoken} />}

            {page.sections.address && (
                <div className='motion-safe:animate-rise' style={{ '--rise-delay': '60ms' } as CSSProperties}>
                    <JoinCard page={page} />
                </div>
            )}

            {page.sections.description && page.description.trim() !== '' && (
                <section
                    aria-label='About this server'
                    className='rounded-[18px] border border-border bg-card p-5 motion-safe:animate-rise sm:p-6'
                    style={{ '--rise-delay': '120ms' } as CSSProperties}
                >
                    <Markdown source={page.description} className='break-words' />
                </section>
            )}

            {page.sections.votes && (
                <div className='motion-safe:animate-rise' style={{ '--rise-delay': '180ms' } as CSSProperties}>
                    <VoteCard page={page} slug={slug} />
                </div>
            )}
        </div>
    );
}

function PageBody({ slug }: { slug: string }) {
    const [waking, setWaking] = useState(false);
    const { data, error, isPending, refetch } = usePublicServerPage(slug, waking);

    useEffect(() => {
        if (data) {
            setPageTitle(`${data.name} | MonoForge`);
        }

        if (data && data.status !== 'sleeping') {
            setWaking(false);
        }
    }, [data]);

    if (isPending) {
        return <Spinner size='large' centered />;
    }

    if (error && !data) {
        const missing = isAxiosError(error) && error.response?.status === 404;

        return (
            <div className='rounded-[18px] border border-border bg-card p-6 text-center text-sm text-muted-foreground'>
                {missing ? (
                    <>
                        <Icon icon={Users} aria-hidden className='mx-auto mb-3 size-8' />
                        <p className='font-semibold text-foreground'>This server page does not exist.</p>
                        <p className='mt-1'>It may have been unpublished, or the address is mistyped.</p>
                    </>
                ) : (
                    <>
                        <p>The server page could not be loaded.</p>
                        <button
                            type='button'
                            onClick={() => refetch()}
                            className='mt-3 font-medium text-accent underline underline-offset-2'
                        >
                            Try again
                        </button>
                    </>
                )}
            </div>
        );
    }

    return <PublicServerView page={data} slug={slug} onWoken={() => setWaking(true)} />;
}

/**
 * The shareable public page of a server at /s/$slug, in the violet sign-in look: the banner,
 * name, game and status, the join address (the playit address only), the description, votes
 * and, while it sleeps, a wake button. The server adds the Open Graph tags for link previews.
 */
export default function PublicServerPage() {
    const params: { slug?: string } = useParams({ strict: false });
    const slug = params.slug ?? '';

    useEffect(() => {
        setPageTitle('Server | MonoForge');
    }, []);

    return (
        <div className='relative isolate min-h-screen bg-[radial-gradient(120%_120%_at_80%_0%,#241a44,#0e0d15_60%)] px-4 py-8 sm:py-12'>
            <div className='mx-auto flex w-full max-w-3xl flex-col gap-6'>
                <header className='flex flex-wrap items-center justify-between gap-4'>
                    <a
                        href='/'
                        className='flex items-center gap-2.5 text-lg font-semibold tracking-wide text-foreground no-underline'
                    >
                        <img src='/favicons/favicon.svg' alt='' className='size-8' />
                        MonoForge
                    </a>
                    <nav aria-label='Public pages' className='flex flex-wrap items-center gap-2'>
                        <a
                            href='/servers'
                            className='rounded-full border border-border bg-card/70 px-3 py-1.5 text-sm text-muted-foreground no-underline hover:text-foreground'
                        >
                            More servers
                        </a>
                        <a
                            href='/'
                            className='rounded-full border border-border bg-card/70 px-3 py-1.5 text-sm text-muted-foreground no-underline hover:text-foreground'
                        >
                            Get your own server
                        </a>
                    </nav>
                </header>
                <Main>
                    <PageBody key={slug} slug={slug} />
                </Main>
                <p className='text-center text-xs text-muted-foreground'>
                    &copy; <span suppressHydrationWarning>{new Date().getFullYear()}</span> MonoForge
                    <span aria-hidden> · </span>
                    <a href='/status' className='no-underline hover:text-foreground'>
                        Status
                    </a>
                </p>
            </div>
        </div>
    );
}
