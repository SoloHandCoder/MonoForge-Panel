import { Lock, X } from 'lucide-react';
import { ContextDropdownMenu, DropdownMenuItem } from '@/components/elements/dropdown/DropdownMenu';
import { cn } from '@/lib/cn';
import type { EditorTab } from './useEditorTabs';

export interface EditorTabsBarProps {
    readonly tabs: readonly EditorTab[];
    readonly activePath: string | null;
    readonly readOnly: boolean;
    readonly onActivate: (path: string) => void;
    /** Asks first when the tab has unsaved changes. */
    readonly onClose: (path: string) => void;
    readonly onCloseOthers: (path: string) => void;
}

const tabId = (path: string) => `editor-tab-${encodeURIComponent(path)}`;

/** The open files, VS Code style: a dot marks unsaved changes and turns into the close button on hover. */
export default function EditorTabsBar({
    tabs,
    activePath,
    readOnly,
    onActivate,
    onClose,
    onCloseOthers,
}: EditorTabsBarProps) {
    if (tabs.length === 0) {
        return null;
    }

    return (
        <div
            role='tablist'
            aria-label='Open files'
            className='flex shrink-0 overflow-x-auto border-b border-border bg-background [scrollbar-width:thin]'
        >
            {tabs.map((tab) => {
                const active = tab.path === activePath;

                return (
                    <ContextDropdownMenu
                        key={tab.path}
                        className={cn(
                            'group relative flex shrink-0 items-center border-e border-border text-sm',
                            'transition-colors duration-150 motion-reduce:transition-none',
                            active
                                ? 'bg-card text-foreground'
                                : 'bg-background text-muted-foreground hover:bg-(--hover-row) hover:text-foreground'
                        )}
                        menuClassName='w-48'
                        items={
                            <>
                                <DropdownMenuItem onClick={() => onClose(tab.path)}>Close</DropdownMenuItem>
                                <DropdownMenuItem disabled={tabs.length < 2} onClick={() => onCloseOthers(tab.path)}>
                                    Close others
                                </DropdownMenuItem>
                            </>
                        }
                    >
                        {/* The accent line of the active tab. */}
                        <span
                            aria-hidden
                            className={cn(
                                'absolute inset-x-0 top-0 h-0.5 bg-primary transition-opacity duration-150 motion-reduce:transition-none',
                                active ? 'opacity-100' : 'opacity-0'
                            )}
                        />
                        <button
                            type='button'
                            role='tab'
                            id={tabId(tab.path)}
                            aria-selected={active}
                            title={tab.path}
                            className='flex max-w-56 items-center gap-1.5 py-2 ps-3 pe-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring'
                            onClick={() => onActivate(tab.path)}
                            onAuxClick={(event) => {
                                if (event.button === 1) {
                                    event.preventDefault();
                                    onClose(tab.path);
                                }
                            }}
                        >
                            {readOnly && (
                                <Lock aria-label='Read-only' className='size-3 shrink-0 text-muted-foreground' />
                            )}
                            <span className={cn('truncate', tab.status === 'error' && 'text-destructive')}>
                                {tab.name}
                            </span>
                        </button>
                        <button
                            type='button'
                            aria-label={tab.dirty ? `Close ${tab.name} (unsaved changes)` : `Close ${tab.name}`}
                            title={tab.dirty ? 'Unsaved changes' : 'Close'}
                            className='me-1.5 grid size-5 shrink-0 place-items-center rounded-xs text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring'
                            onClick={() => onClose(tab.path)}
                        >
                            {tab.dirty && (
                                <span
                                    aria-hidden
                                    data-testid='unsaved-dot'
                                    className='size-2 rounded-full bg-accent group-hover:hidden group-focus-within:hidden'
                                />
                            )}
                            <X
                                aria-hidden
                                className={cn(
                                    'size-3.5',
                                    tab.dirty
                                        ? 'hidden group-hover:block group-focus-within:block'
                                        : 'opacity-0 group-hover:opacity-100 group-focus-within:opacity-100',
                                    active && !tab.dirty && 'opacity-100'
                                )}
                            />
                        </button>
                    </ContextDropdownMenu>
                );
            })}
        </div>
    );
}
