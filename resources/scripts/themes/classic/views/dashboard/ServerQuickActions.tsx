import { Dialog, PanelLink, cn } from '@/sdk';
import type { ServerListItem } from '@/sdk/theme';

const actionClass =
    'inline-flex items-center rounded-xl border border-border bg-secondary px-3 py-1.5 text-xs font-medium text-secondary-foreground transition-colors hover:bg-accent disabled:pointer-events-none disabled:opacity-50';

/** Shortcuts on a server card; the card reveals them on hover and keyboard focus. */
export default function ServerQuickActions({ item, className }: { item: ServerListItem; className?: string }) {
    const { identifier, permissions } = item;

    return (
        <div className={cn('flex flex-wrap gap-2', className)}>
            {permissions.console && (
                <PanelLink destination={{ to: '/server/$id', params: { id: identifier } }} className={actionClass}>
                    Console
                </PanelLink>
            )}
            {permissions.restart && (
                <Dialog.ConfirmTrigger
                    title={`Restart ${item.name}?`}
                    confirm='Restart'
                    trigger={({ onClick }) => (
                        <button type='button' className={actionClass} disabled={item.sending} onClick={onClick}>
                            Restart
                        </button>
                    )}
                    onConfirmed={(_event, close) => {
                        item.actions.restart();
                        close();
                    }}
                >
                    Restart {item.name}? Players will be disconnected.
                </Dialog.ConfirmTrigger>
            )}
            <PanelLink destination={{ to: '/server/$id/files', params: { id: identifier } }} className={actionClass}>
                Files
            </PanelLink>
        </div>
    );
}
