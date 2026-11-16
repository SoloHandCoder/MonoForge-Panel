/* oxlint-disable rules/design-tokens -- the card wears the owner's validated page colours */
import { Check, Copy, ExternalLink, Heart, Users } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { DiscoveredServer } from '@/api/public/serverDiscovery';
import Icon from '@/components/elements/Icon';
import copyToClipboard from '@/lib/clipboard';
import { cn } from '@/lib/cn';
import { accentButtonStyle, accentVars, isHexColor, themeStyle } from '@/lib/serverPageTheme';
import { statusStyles } from '@/components/serverPage/status';

export type ServerCardData = Pick<
    DiscoveredServer,
    'name' | 'tagline' | 'description' | 'game' | 'status' | 'players' | 'votes' | 'address' | 'theme' | 'sections'
> & { url: string | null };

function CopyAddress({ address }: { address: string }) {
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        if (!copied) {
            return;
        }

        const timer = window.setTimeout(() => setCopied(false), 2000);

        return () => window.clearTimeout(timer);
    }, [copied]);

    return (
        <>
            <button
                type='button'
                onClick={async () => setCopied(await copyToClipboard(address))}
                style={accentButtonStyle}
                className='inline-flex min-w-0 flex-1 items-center justify-center gap-2 px-4 py-2 text-sm font-semibold transition-opacity hover:opacity-90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none'
            >
                <Icon icon={copied ? Check : Copy} aria-hidden className='size-4 shrink-0' />
                {copied ? 'Copied' : 'Copy address'}
            </button>
            <span aria-live='polite' className='sr-only'>
                {copied ? `Address ${address} copied` : ''}
            </span>
        </>
    );
}

function CardStats({ server }: { server: ServerCardData }) {
    return (
        <dl className='mt-auto flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground'>
            {server.sections.players && server.players && (
                <div className='flex items-center gap-1.5'>
                    <dt>
                        <Icon icon={Users} aria-hidden className='size-4' />
                        <span className='sr-only'>Players</span>
                    </dt>
                    <dd className='tabular-nums'>
                        {server.players.online} / {server.players.max} online
                    </dd>
                </div>
            )}
            {server.sections.votes && (
                <div className='flex items-center gap-1.5'>
                    <dt>
                        <Icon icon={Heart} aria-hidden className='size-4' />
                        <span className='sr-only'>Votes</span>
                    </dt>
                    <dd className='tabular-nums'>{server.votes === 1 ? '1 vote' : `${server.votes} votes`}</dd>
                </div>
            )}
        </dl>
    );
}

/**
 * A server on the discovery page, and the live preview of one on the share settings page: the
 * banner in the owner's colours, the game, status and players, the start of the description,
 * votes, and buttons to copy the playit address and open the page. Every text is the owner's and
 * is shown as text, never as HTML.
 */
export default function ServerCard({
    server,
    headingLevel = 'h2',
}: {
    server: ServerCardData;
    headingLevel?: 'h2' | 'h3';
}) {
    const status = statusStyles[server.status];
    const Heading = headingLevel;
    const gameColor = server.game && isHexColor(server.game.color) ? server.game.color : undefined;

    return (
        <article className='flex h-full min-w-0 flex-col overflow-hidden rounded-[18px] border border-border bg-card shadow-lg motion-safe:transition-transform motion-safe:duration-200 motion-safe:hover:-translate-y-0.5'>
            <div
                data-testid='server-card-banner'
                className='relative flex min-h-32 flex-col justify-end gap-1 bg-cover bg-center px-4 pt-10 pb-4'
                style={themeStyle(server.theme)}
            >
                <div className='absolute inset-x-4 top-3 flex flex-wrap items-center justify-between gap-2'>
                    {server.game ? (
                        <span className='inline-flex max-w-full items-center gap-1.5 rounded-full bg-black/55 px-2.5 py-1 text-xs font-semibold text-white'>
                            <span
                                aria-hidden
                                className='size-2 shrink-0 rounded-full bg-white'
                                style={gameColor ? { backgroundColor: gameColor } : undefined}
                            />
                            <span className='truncate'>{server.game.name}</span>
                        </span>
                    ) : (
                        <span />
                    )}
                    <span className='inline-flex items-center gap-1.5 rounded-full bg-black/55 px-2.5 py-1 text-xs font-semibold text-white'>
                        <span className={cn('size-2 rounded-full', status.dot)} aria-hidden />
                        {status.label}
                    </span>
                </div>
                <Heading className='text-lg leading-tight font-bold break-words'>{server.name}</Heading>
                {server.tagline !== '' && <p className='text-sm break-words opacity-90'>{server.tagline}</p>}
            </div>
            <div className='flex flex-1 flex-col gap-3 p-4'>
                {server.sections.description && server.description !== '' && (
                    <p className='line-clamp-3 text-sm break-words text-muted-foreground'>{server.description}</p>
                )}
                <CardStats server={server} />
                <div className='flex flex-wrap gap-2' style={accentVars(server.theme)}>
                    {server.sections.address && server.address && <CopyAddress address={server.address} />}
                    {server.url && (
                        <a
                            href={server.url}
                            style={{ borderRadius: 'var(--page-radius)' }}
                            className='inline-flex min-w-0 flex-1 items-center justify-center gap-2 border border-border px-4 py-2 text-sm font-semibold text-foreground no-underline transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none'
                        >
                            View page
                            <Icon icon={ExternalLink} aria-hidden className='size-3.5 shrink-0' />
                            <span className='sr-only'> of {server.name}</span>
                        </a>
                    )}
                </div>
            </div>
        </article>
    );
}
