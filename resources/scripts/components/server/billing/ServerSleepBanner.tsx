import { Moon } from 'lucide-react';
import { useServerSleep, useWakeServer, wakeServerInput } from '@/api/server/sleep/queries';
import Button from '@/components/elements/Button';
import { formatDate } from '@/lib/dates';

/** "Wake server" for anyone allowed to start the server; nothing for everyone else. */
export function WakeServerButton({ identifier, canWake }: { identifier: string; canWake: boolean }) {
    const wake = useWakeServer();

    if (!canWake) {
        return null;
    }

    return (
        <Button
            isLoading={wake.isPending}
            disabled={wake.isPending}
            onClick={() => wake.mutate(wakeServerInput(identifier))}
        >
            Wake server
        </Button>
    );
}

/**
 * Pay-as-you-play: shown on the console while auto-sleep keeps the server stopped, to everyone
 * with access. It wakes when anyone starts it; "Wake server" does that for viewers who may.
 */
export default function ServerSleepBanner({ identifier }: { identifier: string }) {
    const sleep = useServerSleep(identifier);

    if (!sleep?.sleeping) {
        return null;
    }

    return (
        <section
            aria-label='Sleeping'
            className='mb-4 flex flex-col gap-3 rounded-lg border border-border bg-card p-4 text-sm text-card-foreground sm:flex-row sm:items-center'
        >
            <Moon className='h-5 w-5 shrink-0 text-muted-foreground' aria-hidden />
            <div className='min-w-0 flex-1'>
                <p className='font-semibold'>This server is sleeping</p>
                <p className='text-muted-foreground'>
                    Nobody was playing, so it was stopped
                    {sleep.sleep_started_at ? ` on ${formatDate(sleep.sleep_started_at, 'MMM D, YYYY HH:mm')}` : ''}.
                    Starting it in any way wakes it up.
                </p>
            </div>
            <WakeServerButton identifier={identifier} canWake={sleep.can_wake} />
        </section>
    );
}
