/* oxlint-disable rules/design-tokens -- the status pill uses the brand's status tokens */
import { Server as ServerIcon } from 'lucide-react';
import { cn, ListRow, PanelLink } from '@/sdk';
import { Slot, type ServerCardState, type ViewProps } from '@/sdk/theme';

type Tone = 'online' | 'busy' | 'offline';

interface Pill {
    tone: Tone;
    label: string;
}

function pillOf(state: ServerCardState): Pill {
    if (state.kind !== 'ready') {
        return { tone: 'offline', label: state.kind === 'loading' ? 'Loading' : 'Unavailable' };
    }

    if (state.power === 'running') {
        return { tone: 'online', label: 'Online' };
    }

    if (state.power === 'offline') {
        return { tone: 'offline', label: 'Offline' };
    }

    return { tone: 'busy', label: state.power === 'starting' ? 'Starting' : 'Stopping' };
}

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

function StatusPill({ state }: { state: ServerCardState }) {
    const { tone, label } = pillOf(state);

    return (
        <span
            data-part='status-pill'
            data-tone={tone}
            className={cn(
                'inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium',
                pillClass[tone]
            )}
        >
            <span aria-hidden='true' className={cn('size-1.5 rounded-full', dotClass[tone])} />
            {label}
        </span>
    );
}

export default function ServerCardIdentity({ model, server, className }: ViewProps<'dashboard.serverCard.identity'>) {
    return (
        <div className={cn('flex items-center gap-3', className)}>
            <ListRow.Icon className='shrink-0'>
                <ServerIcon size='1em' className='inline-block' />
            </ListRow.Icon>
            <div className='min-w-0 flex-1'>
                <PanelLink
                    destination={{ to: '/server/$id', params: { id: model.identifier } }}
                    aria-label={model.name}
                    className="block truncate font-semibold text-foreground outline-none after:absolute after:inset-0 after:content-['']"
                >
                    {model.name}
                </PanelLink>
                {!!model.description && <p className='truncate text-xs text-muted-foreground'>{model.description}</p>}
            </div>
            <Slot name='dashboard.serverRow.name.after' data={server} />
            <StatusPill state={model.state} />
        </div>
    );
}
