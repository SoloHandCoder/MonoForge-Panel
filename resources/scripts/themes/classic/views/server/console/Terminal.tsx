import { useDeferredValue, useLayoutEffect, useMemo, useRef, useState, useSyncExternalStore } from 'react';
import { ArrowDown, Copy, Download, ListFilter, Pause, Play, Search, X } from 'lucide-react';
import { cn, toast } from '@/sdk';
import type { ConsoleLine, ConsoleOutput, ViewProps } from '@/sdk/theme';
import CommandBar from './CommandBar';
import LogLine from './LogLine';

// How close to the end counts as following the output, in pixels.
const FOLLOW_SLACK = 32;

const toolButtonClass = [
    'inline-flex size-8 shrink-0 items-center justify-center rounded-lg text-muted-foreground transition-colors',
    'hover:bg-secondary hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring',
    'aria-pressed:bg-primary/20 aria-pressed:text-accent disabled:pointer-events-none disabled:opacity-40',
].join(' ');

const plural = (count: number, word: string) => (count === 1 ? `1 ${word}` : `${count} ${word}s`);

function ConnectionDot({ connected }: { connected: boolean }) {
    return (
        <span
            role='status'
            className={cn(
                'inline-flex shrink-0 items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium',
                connected ? 'bg-success/10 text-success' : 'bg-warning/10 text-warning'
            )}
        >
            <span
                aria-hidden='true'
                className={cn('size-1.5 rounded-full', connected ? 'bg-success' : 'bg-warning animate-pulse')}
            />
            {connected ? 'Live' : 'Connecting…'}
        </span>
    );
}

/** Lines after the last one that was showing when the console was paused. */
function countNewer(lines: readonly ConsoleLine[], last: ConsoleLine | undefined): number {
    if (!last) {
        return lines.length;
    }

    let count = 0;

    for (let index = lines.length - 1; index >= 0 && lines[index].id > last.id; index--) {
        count++;
    }

    return count;
}

function SearchBox({ query, onChange }: { query: string; onChange: (query: string) => void }) {
    return (
        <div className='relative flex min-w-0 flex-1 basis-48 items-center'>
            <Search aria-hidden='true' className='pointer-events-none absolute left-2.5 size-4 text-muted-foreground' />
            <input
                type='search'
                value={query}
                onChange={(event) => onChange(event.currentTarget.value)}
                onKeyDown={(event) => {
                    if (event.key === 'Escape') {
                        onChange('');
                    }
                }}
                placeholder='Search the log'
                aria-label='Search the console output'
                className={cn(
                    'h-8 w-full min-w-0 rounded-lg border border-border bg-background/60 pr-8 pl-8 text-sm text-foreground',
                    'placeholder:text-muted-foreground focus:border-accent focus:outline-hidden [&::-webkit-search-cancel-button]:hidden'
                )}
            />
            {query !== '' && (
                <button
                    type='button'
                    onClick={() => onChange('')}
                    aria-label='Clear the search'
                    className='absolute right-1 inline-flex size-6 items-center justify-center rounded-md text-muted-foreground hover:text-foreground'
                >
                    <X className='size-3.5' />
                </button>
            )}
        </div>
    );
}

interface ToolbarProps {
    output: ConsoleOutput;
    empty: boolean;
    filtering: boolean;
    paused: boolean;
    onFilter: () => void;
    onPause: () => void;
}

function ToolButtons({ output, empty, filtering, paused, onFilter, onPause }: ToolbarProps) {
    const copy = () => {
        void output.copy().then((copied) => {
            if (copied) {
                toast.success('Console output copied.');
            } else {
                toast.error('The console output could not be copied.');
            }
        });
    };

    return (
        <div className='flex items-center gap-1'>
            <button
                type='button'
                aria-pressed={filtering}
                aria-label='Only show matching lines'
                title='Only show matching lines'
                onClick={onFilter}
                className={toolButtonClass}
            >
                <ListFilter className='size-4' />
            </button>
            <button
                type='button'
                aria-pressed={paused}
                aria-label={paused ? 'Resume the console' : 'Pause the console'}
                title={paused ? 'Resume' : 'Pause'}
                onClick={onPause}
                className={toolButtonClass}
            >
                {paused ? <Play className='size-4' /> : <Pause className='size-4' />}
            </button>
            <button
                type='button'
                aria-label='Copy the console output'
                title='Copy all'
                onClick={copy}
                disabled={empty}
                className={toolButtonClass}
            >
                <Copy className='size-4' />
            </button>
            <button
                type='button'
                aria-label='Download the console output'
                title='Download log'
                onClick={output.download}
                disabled={empty}
                className={toolButtonClass}
            >
                <Download className='size-4' />
            </button>
        </div>
    );
}

function LatestPill({ paused, newer, onClick }: { paused: boolean; newer: number; onClick: () => void }) {
    return (
        <button
            type='button'
            onClick={onClick}
            className={cn(
                'absolute bottom-3 left-1/2 z-10 inline-flex -translate-x-1/2 items-center gap-1.5 rounded-full',
                'border border-primary/40 bg-popover/95 px-3 py-1.5 text-xs font-medium text-foreground shadow-lg',
                'animate-fade-in transition-colors hover:bg-primary hover:text-primary-foreground'
            )}
        >
            <ArrowDown aria-hidden='true' className='size-3.5' />
            {paused ? `Paused · ${plural(newer, 'new line')}` : 'Jump to latest'}
        </button>
    );
}

/** What the log shows: the live or paused lines, narrowed to the matches while filtering. */
function useVisibleLines(
    output: ConsoleOutput,
    frozen: readonly ConsoleLine[] | null,
    search: string,
    filtering: boolean
) {
    const live = useSyncExternalStore(output.subscribe, output.getSnapshot);
    const shown = frozen ?? live;
    const matching = useMemo(
        () => (search === '' ? shown : shown.filter((line) => line.text.toLowerCase().includes(search))),
        [shown, search]
    );

    return { live, matching, visible: filtering && search !== '' ? matching : shown };
}

/**
 * The console: the output with its log levels, colours and links, a search that highlights or
 * filters, following new output (paused or scrolled up, a pill jumps back to the latest), copy
 * and download, and the command line.
 */
export default function Terminal({ model, className }: ViewProps<'server.terminal'>) {
    const { output, command } = model;
    const [query, setQuery] = useState('');
    const [filtering, setFiltering] = useState(false);
    const [frozen, setFrozen] = useState<readonly ConsoleLine[] | null>(null);
    const [following, setFollowing] = useState(true);
    const scroller = useRef<HTMLDivElement>(null);
    const search = useDeferredValue(query.trim().toLowerCase());
    const { live, matching, visible } = useVisibleLines(output, frozen, search, filtering);
    const paused = frozen !== null;

    // Follows new output while the reader is at the end; reading further up stays put.
    useLayoutEffect(() => {
        const element = scroller.current;

        if (element && following && !paused) {
            element.scrollTop = element.scrollHeight;
        }
    }, [visible, following, paused]);

    const onScroll = () => {
        const element = scroller.current;

        if (element) {
            const atEnd = element.scrollHeight - element.scrollTop - element.clientHeight <= FOLLOW_SLACK;

            setFollowing((current) => (current === atEnd ? current : atEnd));
        }
    };

    const jumpToLatest = () => {
        setFrozen(null);
        setFollowing(true);
        if (scroller.current) {
            scroller.current.scrollTop = scroller.current.scrollHeight;
        }
    };

    return (
        <section
            aria-label='Console'
            className={cn(
                'relative flex w-full min-w-0 flex-col overflow-hidden rounded-xl border border-border bg-sidebar shadow-sm',
                className
            )}
        >
            <div className='flex flex-wrap items-center gap-2 border-b border-border px-2 py-2 sm:px-3'>
                <SearchBox query={query} onChange={setQuery} />
                {search !== '' && (
                    <span aria-live='polite' className='shrink-0 text-xs text-muted-foreground tabular-nums'>
                        {plural(matching.length, 'line')}
                    </span>
                )}
                <ToolButtons
                    output={output}
                    empty={live.length === 0}
                    filtering={filtering}
                    paused={paused}
                    onFilter={() => setFiltering((current) => !current)}
                    onPause={() => (paused ? jumpToLatest() : setFrozen(live))}
                />
                <ConnectionDot connected={model.connected} />
            </div>
            <div className='relative min-h-0 flex-1'>
                <div
                    ref={scroller}
                    onScroll={onScroll}
                    role='log'
                    aria-live='off'
                    aria-label='Console output'
                    tabIndex={0}
                    className={cn(
                        'h-[55vh] min-h-72 overflow-y-auto overscroll-contain py-2 font-mono text-xs leading-relaxed text-foreground lg:h-[min(66vh,44rem)]',
                        'focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring'
                    )}
                >
                    {visible.length === 0 && (
                        <p className='px-4 py-2 text-muted-foreground'>
                            {search !== '' && filtering ? 'No lines match your search.' : 'No output yet.'}
                        </p>
                    )}
                    {visible.map((line) => (
                        <LogLine key={line.id} line={line} query={search} />
                    ))}
                </div>
                {(paused || !following) && (
                    <LatestPill
                        paused={paused}
                        newer={paused ? countNewer(live, frozen.at(-1)) : 0}
                        onClick={jumpToLatest}
                    />
                )}
            </div>
            {command.allowed && <CommandBar command={command} />}
        </section>
    );
}
