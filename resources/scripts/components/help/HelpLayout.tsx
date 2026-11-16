/* oxlint-disable rules/design-tokens -- the page backdrop is the brand's fixed public-page look, as on /status and /legal */
import { Link } from '@tanstack/react-router';
import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { Main } from '@/views/a11y';

export const DISCORD_URL = 'https://discord.gg/B3fd45pgvC';

/** The public help pages' shell, in the violet sign-in look of /status and /legal. */
export default function HelpLayout({ children, wide = false }: { children: ReactNode; wide?: boolean }) {
    return (
        <div className='relative isolate min-h-screen bg-[radial-gradient(120%_120%_at_80%_0%,#241a44,#0e0d15_60%)] px-4 py-8 sm:py-12'>
            <div className={cn('mx-auto flex w-full flex-col gap-6', wide ? 'max-w-5xl' : 'max-w-3xl')}>
                <header className='flex flex-wrap items-center justify-between gap-4'>
                    <a
                        href='/'
                        className='flex items-center gap-2.5 text-lg font-semibold tracking-wide text-foreground no-underline'
                    >
                        <img src='/favicons/favicon.svg' alt='' className='size-8' />
                        MonoForge
                    </a>
                    <nav aria-label='Public pages' className='flex flex-wrap items-center gap-2'>
                        <Link
                            to='/servers'
                            className='rounded-full border border-border bg-card/70 px-3 py-1.5 text-sm text-muted-foreground no-underline transition-colors hover:text-foreground'
                        >
                            Discover servers
                        </Link>
                        <Link
                            to='/help'
                            className='rounded-full border border-border bg-card/70 px-3 py-1.5 text-sm text-muted-foreground no-underline transition-colors hover:text-foreground'
                        >
                            Help center
                        </Link>
                    </nav>
                </header>
                <Main>{children}</Main>
                <p className='text-center text-xs text-muted-foreground'>
                    &copy; <span suppressHydrationWarning>{new Date().getFullYear()}</span> MonoForge
                    <span aria-hidden> · </span>
                    <a
                        href={DISCORD_URL}
                        target='_blank'
                        rel='noopener noreferrer'
                        className='no-underline hover:text-foreground'
                    >
                        Discord
                    </a>
                    <span aria-hidden> · </span>
                    <a href='/legal/terms' className='no-underline hover:text-foreground'>
                        Terms
                    </a>
                    <span aria-hidden> · </span>
                    <a href='/' className='no-underline hover:text-foreground'>
                        Back to the panel
                    </a>
                </p>
            </div>
        </div>
    );
}

/** The card the help pages put their content in. */
export function HelpCard({ className, children }: { className?: string; children: ReactNode }) {
    return (
        <div
            className={cn(
                'rounded-[18px] border border-border bg-card p-6 text-card-foreground shadow-lg sm:p-8',
                className
            )}
        >
            {children}
        </div>
    );
}
