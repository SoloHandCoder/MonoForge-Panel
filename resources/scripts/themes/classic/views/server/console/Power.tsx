/* oxlint-disable rules/design-tokens -- the state badge uses the brand's status tokens */
import type { ReactNode } from 'react';
import { Play, RotateCw, Square, Zap } from 'lucide-react';
import { cn, Dialog } from '@/sdk';
import { Slot, type ServerRunState, type ViewProps } from '@/sdk/theme';

type Tone = 'online' | 'busy' | 'offline';

const badges = {
    running: { tone: 'online', label: 'Running' },
    starting: { tone: 'busy', label: 'Starting' },
    stopping: { tone: 'busy', label: 'Stopping' },
    offline: { tone: 'offline', label: 'Offline' },
} satisfies Record<ServerRunState, { tone: Tone; label: string }>;

const pillClass = {
    online: 'bg-[var(--status-online-bg)] text-[var(--status-online-fg)]',
    busy: 'bg-[var(--status-busy-bg)] text-[var(--status-busy-fg)]',
    offline: 'bg-[var(--status-offline-bg)] text-[var(--status-offline-fg)]',
} satisfies Record<Tone, string>;

const dotClass = {
    online: 'bg-[var(--status-online)] animate-pulse [animation-duration:2s]',
    busy: 'bg-[var(--status-busy)] animate-pulse [animation-duration:0.8s]',
    offline: 'bg-[var(--status-offline)]',
} satisfies Record<Tone, string>;

/** The power state: running, starting, stopping or offline, or "Connecting" before the first report. */
export function StateBadge({ state, connected }: { state: ServerRunState | null; connected: boolean }) {
    const { tone, label } = state && connected ? badges[state] : { tone: 'offline' as const, label: 'Connecting' };

    return (
        <span
            role='status'
            data-state={state ?? 'unknown'}
            className={cn(
                'inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium transition-colors',
                pillClass[tone]
            )}
        >
            <span aria-hidden='true' className={cn('size-1.5 rounded-full', dotClass[tone])} />
            {label}
        </span>
    );
}

const buttonBase = [
    'inline-flex h-9 flex-1 items-center justify-center gap-1.5 rounded-lg px-3 text-sm font-medium transition-colors sm:flex-none',
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
    'disabled:pointer-events-none disabled:opacity-40',
].join(' ');

const buttonTone = {
    start: 'bg-primary text-primary-foreground hover:bg-primary/85',
    restart: 'border border-border bg-secondary text-secondary-foreground hover:bg-secondary/70',
    stop: 'border border-destructive/40 bg-destructive/10 text-destructive hover:bg-destructive/20',
    kill: 'bg-destructive text-destructive-foreground hover:bg-destructive/85',
};

function PowerButton({
    kind,
    icon,
    children,
    disabled,
    onClick,
}: {
    kind: keyof typeof buttonTone;
    icon: ReactNode;
    children: ReactNode;
    disabled: boolean;
    onClick: () => void;
}) {
    return (
        <button type='button' className={cn(buttonBase, buttonTone[kind])} disabled={disabled} onClick={onClick}>
            {icon}
            {children}
        </button>
    );
}

export default function Power({ model, className }: ViewProps<'server.power'>) {
    const { permissions, enabled, actions, state } = model;
    const any = permissions.start || permissions.stop || permissions.restart;

    return (
        <div className={cn('flex flex-col gap-2 sm:items-end', className)}>
            <Slot name='server.console.power.before' data={model.server} />
            <div className='flex flex-wrap items-center gap-2 sm:justify-end'>
                <StateBadge state={state} connected={model.connected} />
                {any && (
                    <div role='group' aria-label='Power' className='flex w-full flex-wrap gap-2 sm:w-auto'>
                        {permissions.start && (
                            <PowerButton
                                kind='start'
                                icon={<Play aria-hidden='true' className='size-4' />}
                                disabled={!enabled.start}
                                onClick={actions.start}
                            >
                                Start
                            </PowerButton>
                        )}
                        {permissions.restart && (
                            <PowerButton
                                kind='restart'
                                icon={<RotateCw aria-hidden='true' className='size-4' />}
                                disabled={!enabled.restart}
                                onClick={actions.restart}
                            >
                                Restart
                            </PowerButton>
                        )}
                        {permissions.stop && (
                            <PowerButton
                                kind='stop'
                                icon={<Square aria-hidden='true' className='size-3.5' />}
                                disabled={!enabled.stop}
                                onClick={actions.stop}
                            >
                                Stop
                            </PowerButton>
                        )}
                        {permissions.stop && (
                            <Dialog.ConfirmTrigger
                                hideCloseIcon
                                title='Forcibly stop the server?'
                                confirm='Kill'
                                trigger={({ onClick }) => (
                                    <PowerButton
                                        kind={model.killable ? 'kill' : 'stop'}
                                        icon={<Zap aria-hidden='true' className='size-4' />}
                                        disabled={!enabled.kill || state === 'offline'}
                                        onClick={onClick}
                                    >
                                        Kill
                                    </PowerButton>
                                )}
                                onConfirmed={(_event, close) => {
                                    close();
                                    actions.kill();
                                }}
                            >
                                Killing ends the process at once without saving, which can corrupt world or plugin data.
                                Use Stop unless the server is stuck.
                            </Dialog.ConfirmTrigger>
                        )}
                    </div>
                )}
            </div>
            <Slot name='server.console.power.after' data={model.server} />
        </div>
    );
}
