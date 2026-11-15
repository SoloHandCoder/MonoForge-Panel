import type { ReactNode } from 'react';
import { Clock, Copy, Globe, Users } from 'lucide-react';
import { cn, CopyOnClick } from '@/sdk';
import type { ServerRunState, ViewProps } from '@/sdk/theme';

/** "1d 2h 3m", or "2h 3m 4s" under a day. */
export function formatUptime(milliseconds: number): string {
    const seconds = Math.floor(milliseconds / 1000);
    const days = Math.floor(seconds / 86_400);
    const hours = Math.floor((seconds % 86_400) / 3_600);
    const minutes = Math.floor((seconds % 3_600) / 60);

    return days > 0 ? `${days}d ${hours}h ${minutes}m` : `${hours}h ${minutes}m ${seconds % 60}s`;
}

function uptimeText(state: ServerRunState | null, uptime: number): string {
    if (state === null || state === 'offline') {
        return 'Offline';
    }

    if (uptime > 0) {
        return formatUptime(uptime);
    }

    return state.charAt(0).toUpperCase() + state.slice(1);
}

function Row({ icon, label, children }: { icon: ReactNode; label: string; children: ReactNode }) {
    return (
        <div className='flex items-center gap-3 py-2.5'>
            <span className='inline-flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/15 text-accent'>
                {icon}
            </span>
            <div className='min-w-0 flex-1'>
                <p className='text-xs font-medium tracking-wide text-muted-foreground uppercase'>{label}</p>
                <div className='truncate text-sm font-medium text-foreground'>{children}</div>
            </div>
        </div>
    );
}

function AddressCopy({ address }: { address: string }) {
    return (
        <CopyOnClick text={address} showInNotification>
            <button
                type='button'
                aria-label='Copy the address'
                title='Copy address'
                className={cn(
                    'inline-flex size-8 shrink-0 items-center justify-center rounded-lg text-muted-foreground transition-colors',
                    'hover:bg-secondary hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring'
                )}
            >
                <Copy className='size-4' />
            </button>
        </CopyOnClick>
    );
}

export default function Stats({ model, className }: ViewProps<'server.stats'>) {
    const { address, players } = model;

    return (
        <div className={cn('rounded-xl border border-border bg-card px-4 py-1.5 divide-y divide-border', className)}>
            <div className='flex items-center gap-1'>
                <div className='min-w-0 flex-1'>
                    <Row
                        icon={<Globe aria-hidden='true' className='size-4' />}
                        label={model.tunnel ? 'Address · playit.gg' : 'Address'}
                    >
                        <span className='font-mono'>
                            {address ?? (model.addressPending ? 'Address pending' : 'Not available')}
                        </span>
                    </Row>
                </div>
                {address && <AddressCopy address={address} />}
            </div>
            <Row icon={<Clock aria-hidden='true' className='size-4' />} label='Uptime'>
                <span className='tabular-nums'>{uptimeText(model.state, model.uptime)}</span>
            </Row>
            {players && (
                <Row icon={<Users aria-hidden='true' className='size-4' />} label='Players'>
                    <span className='tabular-nums'>
                        {players.max === null ? `${players.online} online` : `${players.online} / ${players.max}`}
                    </span>
                </Row>
            )}
        </div>
    );
}
