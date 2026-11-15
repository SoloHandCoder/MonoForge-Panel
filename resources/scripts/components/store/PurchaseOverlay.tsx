import { useEffect, useRef, useState } from 'react';
import { Link } from '@tanstack/react-router';
import { Check, LayoutDashboard, Server, SquareTerminal } from 'lucide-react';
import type { PurchaseResult } from '@/api/store/types';
import { buttonClassName } from '@/components/elements/Button';
import Icon from '@/components/elements/Icon';
import Portal from '@/components/elements/Portal';
import { everyDays, tokens } from '@/components/store/storeFormat';
import { cn } from '@/lib/cn';

export const PURCHASE_STEPS = [
    'Reserving your resources',
    'Charging your tokens',
    'Creating your server',
    'Setting up the network',
] as const;

/** How long each step shows while the request runs; the last one waits for the answer. */
export const PURCHASE_STEP_MS = 900;

function Orb() {
    // Reduced motion stops every animation here (panel.css), leaving the rings and core still.
    return (
        <div className='relative grid size-36 place-items-center' aria-hidden>
            <div className='absolute inset-0 rounded-full bg-primary/20 blur-2xl motion-safe:animate-pulse' />
            <div className='absolute inset-0 rounded-full border-2 border-primary/15 border-t-primary motion-safe:animate-spin motion-safe:[animation-duration:1.4s]' />
            <div className='absolute inset-4 rounded-full border-2 border-(--accent-soft)/10 border-b-(--accent-soft) motion-safe:animate-spin motion-safe:[animation-direction:reverse] motion-safe:[animation-duration:2.2s]' />
            <div className='absolute inset-9 rounded-full bg-linear-to-br from-(--usage-from) to-(--usage-to) shadow-2xl shadow-primary/60' />
            <Icon icon={Server} className='relative size-7 text-primary-foreground' />
        </div>
    );
}

function Working() {
    const [step, setStep] = useState(0);

    useEffect(() => {
        const timer = window.setInterval(
            () => setStep((current) => Math.min(current + 1, PURCHASE_STEPS.length - 1)),
            PURCHASE_STEP_MS
        );

        return () => window.clearInterval(timer);
    }, []);

    return (
        <div className='flex flex-col items-center text-center'>
            <Orb />
            <h2 className='mt-8 font-header text-2xl font-semibold text-foreground'>Setting up your server</h2>
            <p className='mt-1 text-sm text-muted-foreground'>This only takes a moment. Please keep this page open.</p>
            <ol className='mt-7 grid w-full max-w-xs gap-2.5 text-left' aria-live='polite'>
                {PURCHASE_STEPS.map((label, index) => {
                    const done = index < step;
                    const current = index === step;

                    return (
                        <li
                            key={label}
                            aria-current={current ? 'step' : undefined}
                            className={cn(
                                'flex items-center gap-3 text-sm transition-colors duration-300',
                                done || current ? 'text-foreground' : 'text-muted-foreground/60'
                            )}
                        >
                            <span
                                className={cn(
                                    'grid size-5 shrink-0 place-items-center rounded-full border transition-colors duration-300',
                                    done && 'border-primary bg-primary text-primary-foreground',
                                    current && 'border-primary',
                                    !done && !current && 'border-border'
                                )}
                            >
                                {done ? (
                                    <Icon icon={Check} className='size-3' aria-hidden />
                                ) : (
                                    current && (
                                        <span className='size-1.5 rounded-full bg-primary motion-safe:animate-ping' />
                                    )
                                )}
                            </span>
                            {label}
                            {done && <span className='sr-only'> — done</span>}
                        </li>
                    );
                })}
            </ol>
            <div className='mt-7 h-1 w-full max-w-xs overflow-hidden rounded-full bg-(--sunken)'>
                <div
                    className='h-full rounded-full bg-linear-to-r from-(--usage-from) to-(--usage-to) transition-[width] duration-700 ease-out'
                    style={{ width: `${((step + 1) / (PURCHASE_STEPS.length + 1)) * 100}%` }}
                />
            </div>
        </div>
    );
}

function Done({ result }: { result: PurchaseResult }) {
    const goRef = useRef<HTMLAnchorElement>(null);

    useEffect(() => goRef.current?.focus(), []);

    return (
        <div className='flex animate-rise flex-col items-center text-center'>
            <div className='relative grid size-28 place-items-center' aria-hidden>
                <div className='absolute inset-0 rounded-full bg-(--status-online)/20 blur-2xl' />
                <div className='absolute inset-0 rounded-full border-2 border-(--status-online)/40' />
                <div className='grid size-20 place-items-center rounded-full bg-success text-success-foreground shadow-2xl shadow-success/50'>
                    <Icon icon={Check} className='size-10' strokeWidth={3} />
                </div>
            </div>
            <h2 className='mt-8 font-header text-3xl font-bold text-foreground'>Purchase complete!</h2>
            <p className='mt-2 text-muted-foreground'>
                Your server <span className='font-medium text-foreground'>{result.server.name}</span> is installing.
            </p>
            {result.tokens_charged > 0 && (
                <p className='mt-1 text-sm text-muted-foreground'>
                    {tokens(result.tokens_charged)} paid — it renews {everyDays(result.period_days)} from your balance.
                </p>
            )}
            {result.address && (
                <p className='mt-4 rounded-lg border border-border bg-(--sunken) px-3 py-1.5 font-mono text-sm text-foreground'>
                    {result.address}
                </p>
            )}
            <div className='mt-8 flex w-full max-w-sm flex-col gap-2 sm:flex-row'>
                <Link
                    ref={goRef}
                    to='/server/$id'
                    params={{ id: result.server.identifier }}
                    className={buttonClassName({
                        size: 'large',
                        className: 'flex-1 justify-center gap-2 no-underline',
                    })}
                >
                    <Icon icon={SquareTerminal} className='size-4' aria-hidden />
                    Go to server
                </Link>
                <Link
                    to='/'
                    className={buttonClassName({
                        size: 'large',
                        color: 'grey',
                        isSecondary: true,
                        className: 'flex-1 justify-center gap-2 no-underline',
                    })}
                >
                    <Icon icon={LayoutDashboard} className='size-4' aria-hidden />
                    Dashboard
                </Link>
            </div>
        </div>
    );
}

/** The full-screen state between confirming a purchase and its result. */
export default function PurchaseOverlay({ result }: { result: PurchaseResult | null }) {
    const rootRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const { overflow } = document.body.style;

        document.body.style.overflow = 'hidden';
        rootRef.current?.focus();

        return () => {
            document.body.style.overflow = overflow;
        };
    }, []);

    return (
        <Portal>
            <div
                ref={rootRef}
                role='dialog'
                aria-modal='true'
                aria-label={result ? 'Purchase complete' : 'Setting up your server'}
                tabIndex={-1}
                className='fixed inset-0 z-50 grid animate-fade-in place-items-center overflow-y-auto bg-background/95 px-4 py-10 outline-none backdrop-blur-md'
            >
                <div
                    aria-hidden
                    className='pointer-events-none absolute top-1/4 left-1/2 size-128 -translate-x-1/2 rounded-full bg-primary/10 blur-3xl'
                />
                <div className='relative w-full max-w-md'>{result ? <Done result={result} /> : <Working />}</div>
            </div>
        </Portal>
    );
}
