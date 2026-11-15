import { memo, type CSSProperties, type ReactNode } from 'react';
import { cn } from '@/sdk';
import type { ConsoleLine, ConsoleLineLevel, ConsoleSegment } from '@/sdk/theme';

// Server output is untrusted. Every run is rendered as a React text node and every link is an
// https URL the core already checked, so nothing in a line can become markup or script.

/** Where `query` (lower case) occurs in `text`, as [start, end) ranges that do not overlap. */
export function matchRanges(text: string, query: string): [number, number][] {
    if (query === '') {
        return [];
    }

    const haystack = text.toLowerCase();
    const ranges: [number, number][] = [];

    for (let index = haystack.indexOf(query); index !== -1; index = haystack.indexOf(query, index + query.length)) {
        ranges.push([index, index + query.length]);
    }

    return ranges;
}

/** `text` (starting at `offset` in its line) with the parts inside `ranges` marked. */
function highlight(text: string, offset: number, ranges: readonly [number, number][]): ReactNode {
    const end = offset + text.length;
    const pieces: ReactNode[] = [];
    let cursor = offset;

    for (const [from, to] of ranges) {
        if (to <= cursor || from >= end) {
            continue;
        }

        const start = Math.max(from, cursor);
        const stop = Math.min(to, end);

        if (start > cursor) {
            pieces.push(text.slice(cursor - offset, start - offset));
        }

        pieces.push(
            <mark key={start} className='rounded-sm bg-primary/45 text-foreground'>
                {text.slice(start - offset, stop - offset)}
            </mark>
        );
        cursor = stop;
    }

    if (pieces.length === 0) {
        return text;
    }

    if (cursor < end) {
        pieces.push(text.slice(cursor - offset));
    }

    return pieces;
}

const levelWordClass = {
    error: 'font-semibold text-destructive',
    warn: 'font-semibold text-warning',
    info: 'text-muted-foreground',
} satisfies Record<ConsoleLineLevel, string>;

const levelRowClass = {
    error: 'border-destructive bg-destructive/10 text-destructive',
    warn: 'border-warning bg-warning/10 text-warning',
    info: 'border-transparent',
} satisfies Record<ConsoleLineLevel, string>;

/** The colours are CSS values the core picked from its palette or built from numbers, never text from the output. */
function segmentStyle(segment: ConsoleSegment): CSSProperties | undefined {
    if (segment.fg === null && segment.bg === null) {
        return undefined;
    }

    return { color: segment.fg ?? undefined, backgroundColor: segment.bg ?? undefined };
}

function Segment({
    segment,
    offset,
    ranges,
}: {
    segment: ConsoleSegment;
    offset: number;
    ranges: readonly [number, number][];
}) {
    const className = cn(
        segment.bold && 'font-bold',
        segment.dim && 'opacity-70',
        segment.italic && 'italic',
        segment.underline && 'underline',
        segment.strike && 'line-through',
        segment.level && levelWordClass[segment.level]
    );
    const content = highlight(segment.text, offset, ranges);

    if (segment.href !== null) {
        return (
            <a
                href={segment.href}
                target='_blank'
                rel='noopener noreferrer nofollow'
                referrerPolicy='no-referrer'
                className={cn(
                    className,
                    'text-accent underline decoration-accent/40 underline-offset-2 hover:decoration-accent focus-visible:rounded-sm focus-visible:outline-2 focus-visible:outline-ring'
                )}
                style={segmentStyle(segment)}
            >
                {content}
            </a>
        );
    }

    return (
        <span className={className || undefined} style={segmentStyle(segment)}>
            {content}
        </span>
    );
}

/** One line of the console. Lines never change, so a line only renders again when the search does. */
const LogLine = memo(({ line, query }: { line: ConsoleLine; query: string }) => {
    const ranges = matchRanges(line.text, query);
    let offset = 0;

    return (
        <div
            data-level={line.level ?? undefined}
            className={cn(
                'border-l-2 px-3 py-px whitespace-pre-wrap [overflow-wrap:anywhere] sm:px-4',
                line.level ? levelRowClass[line.level] : 'border-transparent'
            )}
        >
            {line.text === ''
                ? ' '
                : line.segments.map((segment) => {
                      const start = offset;

                      offset += segment.text.length;

                      return <Segment key={start} segment={segment} offset={start} ranges={ranges} />;
                  })}
        </div>
    );
});

LogLine.displayName = 'LogLine';

export default LogLine;
