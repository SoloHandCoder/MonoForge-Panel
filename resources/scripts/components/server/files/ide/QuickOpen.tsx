import { useId, useMemo, useState, type KeyboardEvent } from 'react';
import { Dialog as BaseDialog } from '@base-ui/react/dialog';
import { useQueryClient } from '@tanstack/react-query';
import { FileCode, Search } from 'lucide-react';
import { FILE_SEARCH_MIN_LENGTH, listedServerFilePaths, useServerFileSearch } from '@/api/server/files/queries';
import { cn } from '@/lib/cn';
import { useDebouncedCallback } from '@/plugins/useDebouncedCallback';
import { fuzzyFilter } from './fuzzy';

/** At most this many results are listed. */
export const QUICK_OPEN_LIMIT = 50;

export interface QuickOpenProps {
    readonly open: boolean;
    readonly onClose: () => void;
    readonly uuid: string;
    /** May search the server's folders by name, beyond those already loaded. */
    readonly canSearch: boolean;
    readonly onPick: (path: string) => void;
}

const splitPath = (path: string) => {
    const slash = path.lastIndexOf('/');

    return { name: path.slice(slash + 1), directory: path.slice(0, slash) || '/' };
};

/** How long typing must pause before quick open asks the server. */
export const QUICK_OPEN_SEARCH_DELAY_MS = 300;

function QuickOpenBody({ uuid, canSearch, onPick, onClose }: Omit<QuickOpenProps, 'open'>) {
    const queryClient = useQueryClient();
    const listId = useId();
    const [query, setQuery] = useState('');
    const [searched, setSearched] = useState('');
    const [selected, setSelected] = useState(0);
    // One server search per pause in typing: the search walks the tree on Wings and has its own rate limit.
    const search = useDebouncedCallback(setSearched, QUICK_OPEN_SEARCH_DELAY_MS);
    const listed = useMemo(() => listedServerFilePaths(queryClient, uuid), [queryClient, uuid]);
    const remote = useServerFileSearch(uuid, searched, canSearch);
    const remoteMatches = searched.trim().length >= FILE_SEARCH_MIN_LENGTH ? remote.data : undefined;

    const results = useMemo(() => {
        const local = fuzzyFilter(query, listed, QUICK_OPEN_LIMIT);
        const seen = new Set(local);
        const fromServer = (remoteMatches?.data ?? [])
            .map((match) => match.attributes.path)
            .filter((path) => !seen.has(path));

        return [...local, ...fromServer].slice(0, QUICK_OPEN_LIMIT);
    }, [query, listed, remoteMatches]);

    const current = Math.min(selected, Math.max(results.length - 1, 0));
    const pick = (path: string | undefined) => {
        if (path) {
            onPick(path);
            onClose();
        }
    };

    const keyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;

            setSelected((current + step + results.length) % Math.max(results.length, 1));
        } else if (event.key === 'Enter') {
            event.preventDefault();
            pick(results[current]);
        }
    };

    const searching = canSearch && query.trim().length >= FILE_SEARCH_MIN_LENGTH && remote.isFetching;
    let status = '';

    if (searching) {
        status = 'Searching the server…';
    } else if (results.length === 0) {
        status = query ? 'No matching files.' : 'Open a folder in the tree, or type to search.';
    } else if (remoteMatches?.meta?.truncated) {
        status = 'The server search covers part of the server; type more to narrow it down.';
    }

    return (
        <>
            <div className='flex items-center gap-2 border-b border-border px-3'>
                <Search aria-hidden className='size-4 shrink-0 text-muted-foreground' />
                <input
                    autoFocus
                    role='combobox'
                    aria-label='Search files by name'
                    aria-expanded
                    aria-controls={listId}
                    aria-activedescendant={results[current] ? `${listId}-${current}` : undefined}
                    aria-autocomplete='list'
                    placeholder='Search files by name'
                    className='h-11 w-full min-w-0 border-0 bg-transparent px-0 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-0'
                    value={query}
                    onChange={(event) => {
                        setQuery(event.target.value);
                        setSelected(0);
                        search(event.target.value);
                    }}
                    onKeyDown={keyDown}
                />
                <kbd className='hidden shrink-0 rounded-xs border border-border px-1.5 py-0.5 font-mono text-xs text-muted-foreground sm:inline'>
                    Esc
                </kbd>
            </div>
            <ul id={listId} role='listbox' aria-label='Files' className='max-h-[min(24rem,60vh)] overflow-y-auto p-1'>
                {results.map((path, index) => {
                    const { name, directory } = splitPath(path);

                    return (
                        <li
                            key={path}
                            id={`${listId}-${index}`}
                            role='option'
                            aria-selected={index === current}
                            className={cn(
                                'flex cursor-pointer items-center gap-2 rounded-sm px-2 py-1.5 text-sm',
                                index === current
                                    ? 'bg-(--active-item) text-(--active-item-foreground)'
                                    : 'text-foreground hover:bg-(--hover-row)'
                            )}
                            onMouseMove={() => index !== current && setSelected(index)}
                            onClick={() => pick(path)}
                        >
                            <FileCode aria-hidden className='size-4 shrink-0 text-accent' />
                            <span className='truncate'>{name}</span>
                            <span className='ms-auto truncate ps-2 text-xs text-muted-foreground'>{directory}</span>
                        </li>
                    );
                })}
            </ul>
            <p role='status' className='border-t border-border px-3 py-2 text-xs text-muted-foreground empty:hidden'>
                {status}
            </p>
        </>
    );
}

/** Ctrl+P: open a file by name, from the folders already loaded and a search of the server. */
export default function QuickOpen({ open, onClose, ...props }: QuickOpenProps) {
    return (
        <BaseDialog.Root open={open} onOpenChange={(next) => !next && onClose()}>
            <BaseDialog.Portal>
                <BaseDialog.Backdrop className='fixed inset-0 z-50 bg-background/70 transition-opacity duration-150 data-ending-style:opacity-0 data-starting-style:opacity-0 motion-reduce:transition-none' />
                <BaseDialog.Popup
                    aria-label='Quick open'
                    className={cn(
                        'fixed inset-x-4 top-16 z-50 mx-auto max-w-xl overflow-hidden rounded-md border border-border bg-popover shadow-2xl',
                        'transition-[opacity,transform] duration-150 data-ending-style:-translate-y-1 data-ending-style:opacity-0',
                        'data-starting-style:-translate-y-1 data-starting-style:opacity-0 motion-reduce:transition-none'
                    )}
                >
                    <BaseDialog.Title className='sr-only'>Quick open</BaseDialog.Title>
                    <QuickOpenBody onClose={onClose} {...props} />
                </BaseDialog.Popup>
            </BaseDialog.Portal>
        </BaseDialog.Root>
    );
}
