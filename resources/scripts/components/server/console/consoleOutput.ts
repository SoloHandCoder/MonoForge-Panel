// Server output is untrusted: it is parsed here into plain text runs with styles chosen from fixed
// tables, so a theme renders it as text nodes and never as HTML. Links are only https URLs that
// the URL parser accepts, so no other scheme (javascript:, data:, ...) can become a link.

/** The log level a line reports in its header, such as `[12:00:01 WARN]:`. */
export type ConsoleLineLevel = 'error' | 'warn' | 'info';

/** A run of a console line in one style. */
export interface ConsoleSegment {
    readonly text: string;
    /** A CSS colour (a `--terminal-ansi-*` token or an `rgb()` value), or null for the default. */
    readonly fg: string | null;
    readonly bg: string | null;
    readonly bold: boolean;
    readonly dim: boolean;
    readonly italic: boolean;
    readonly underline: boolean;
    readonly strike: boolean;
    /** An https URL the run links to, already validated; open it with `rel="noopener noreferrer nofollow"`. */
    readonly href: string | null;
    /** The run is the level word of the line's header (`WARN`, `ERROR`, `INFO`). */
    readonly level: ConsoleLineLevel | null;
}

/** One line of server output, parsed. */
export interface ConsoleLine {
    /** Increases with every line, so it identifies a line across snapshots. */
    readonly id: number;
    /** The line without escape sequences, for search, copying and downloads. */
    readonly text: string;
    readonly segments: readonly ConsoleSegment[];
    readonly level: ConsoleLineLevel | null;
}

/** A change to the output, delivered as it happens. */
export type ConsoleOutputChange =
    | { readonly kind: 'clear' }
    | { readonly kind: 'append'; readonly line: ConsoleLine; readonly raw: string };

/** The console output a theme renders: the retained lines, newest last. */
export interface ConsoleOutput {
    readonly subscribe: (listener: () => void) => () => void;
    readonly getSnapshot: () => readonly ConsoleLine[];
    /** Copies every retained line as plain text; resolves false when the clipboard refused. */
    readonly copy: () => Promise<boolean>;
    /** Saves every retained line as a plain text `.log` file. */
    readonly download: () => void;
}

/** A line longer than this is cut, so one runaway line cannot stall the page. */
export const MAX_LINE_LENGTH = 8_192;

const MAX_URL_LENGTH = 2_048;

const ANSI_NAMES = [
    'black',
    'red',
    'green',
    'yellow',
    'blue',
    'magenta',
    'cyan',
    'white',
    'bright-black',
    'bright-red',
    'bright-green',
    'bright-yellow',
    'bright-blue',
    'bright-magenta',
    'bright-cyan',
    'bright-white',
] as const;

const CUBE_LEVELS = [0, 95, 135, 175, 215, 255] as const;

const rgb = (r: number, g: number, b: number) => `rgb(${r} ${g} ${b})`;

const byte = (value: number | undefined): number | null =>
    value !== undefined && Number.isInteger(value) && value >= 0 && value <= 255 ? value : null;

/** The CSS colour of an xterm 256-colour index, or null when it is not one. */
export function paletteColor(index: number | undefined): string | null {
    const value = byte(index);

    if (value === null) {
        return null;
    }

    if (value < 16) {
        return `var(--terminal-ansi-${ANSI_NAMES[value]})`;
    }

    if (value < 232) {
        const cube = value - 16;

        return rgb(CUBE_LEVELS[Math.floor(cube / 36)], CUBE_LEVELS[Math.floor(cube / 6) % 6], CUBE_LEVELS[cube % 6]);
    }

    const grey = 8 + (value - 232) * 10;

    return rgb(grey, grey, grey);
}

interface Style {
    fg: string | null;
    bg: string | null;
    bold: boolean;
    dim: boolean;
    italic: boolean;
    underline: boolean;
    strike: boolean;
    inverse: boolean;
}

const plainStyle = (): Style => ({
    fg: null,
    bg: null,
    bold: false,
    dim: false,
    italic: false,
    underline: false,
    strike: false,
    inverse: false,
});

/** Reads an extended colour (`5;n` or `2;r;g;b`) from `codes` at `index`; returns the colour and the codes it used. */
function extendedColor(codes: readonly number[], index: number): [string | null, number] {
    if (codes[index] === 5) {
        return [paletteColor(codes[index + 1]), 2];
    }

    if (codes[index] === 2) {
        const [r, g, b] = [byte(codes[index + 1]), byte(codes[index + 2]), byte(codes[index + 3])];

        return [r === null || g === null || b === null ? null : rgb(r, g, b), 4];
    }

    return [null, 0];
}

/** SGR codes that only switch an attribute. */
const ATTRIBUTE_CODES = new Map<number, Partial<Style>>([
    [1, { bold: true }],
    [2, { dim: true }],
    [3, { italic: true }],
    [4, { underline: true }],
    [7, { inverse: true }],
    [9, { strike: true }],
    [22, { bold: false, dim: false }],
    [23, { italic: false }],
    [24, { underline: false }],
    [27, { inverse: false }],
    [29, { strike: false }],
    [39, { fg: null }],
    [49, { bg: null }],
]);

/** The colour a basic SGR colour code sets, as [slot, colour], or null when the code is not one. */
function basicColor(code: number): ['fg' | 'bg', string | null] | null {
    const ranges = [
        [30, 'fg', 0],
        [90, 'fg', 8],
        [40, 'bg', 0],
        [100, 'bg', 8],
    ] as const;

    for (const [from, slot, offset] of ranges) {
        if (code >= from && code <= from + 7) {
            return [slot, paletteColor(code - from + offset)];
        }
    }

    return null;
}

/** The SGR codes of a parameter string; colon sub-parameters (38:2::r:g:b) read like semicolons once empty fields go. */
const sgrCodes = (parameters: string): number[] =>
    parameters === ''
        ? [0]
        : parameters
              .split(/[;:]/)
              .filter((code, index) => code !== '' || index === 0)
              .map(Number);

function applySgr(style: Style, parameters: string): void {
    const codes = sgrCodes(parameters);

    for (let index = 0; index < codes.length; index++) {
        const code = codes[index];
        const attribute = ATTRIBUTE_CODES.get(code);
        const color = basicColor(code);

        if (code === 0 || Number.isNaN(code)) {
            Object.assign(style, plainStyle());
        } else if (attribute) {
            Object.assign(style, attribute);
        } else if (color) {
            style[color[0]] = color[1];
        } else if (code === 38 || code === 48) {
            const [extended, used] = extendedColor(codes, index + 1);

            style[code === 38 ? 'fg' : 'bg'] = extended;
            index += used;
        }
    }
}

const segmentOf = (text: string, style: Style): ConsoleSegment => ({
    text,
    fg: style.inverse ? (style.bg ?? 'var(--terminal-background)') : style.fg,
    bg: style.inverse ? (style.fg ?? 'var(--terminal-foreground)') : style.bg,
    bold: style.bold,
    dim: style.dim,
    italic: style.italic,
    underline: style.underline,
    strike: style.strike,
    href: null,
    level: null,
});

const sameLook = (a: ConsoleSegment, b: ConsoleSegment) =>
    a.fg === b.fg &&
    a.bg === b.bg &&
    a.bold === b.bold &&
    a.dim === b.dim &&
    a.italic === b.italic &&
    a.underline === b.underline &&
    a.strike === b.strike &&
    a.href === b.href &&
    a.level === b.level;

/** Index just past an escape sequence starting at `start` (the ESC or a C1 CSI), with its SGR parameters if it is one. */
interface EscapeSequence {
    /** The index just past the sequence. */
    readonly end: number;
    /** The parameters of an SGR (colour and style) sequence; null for any other sequence. */
    readonly sgr: string | null;
}

function readEscape(raw: string, start: number): EscapeSequence {
    const csi = raw[start] === '\u009b' || raw[start + 1] === '[';
    const osc = raw[start + 1] === ']';

    if (csi) {
        let index = start + (raw[start] === '\u009b' ? 1 : 2);
        const from = index;

        while (index < raw.length) {
            const code = raw.charCodeAt(index);

            if (code >= 0x40 && code <= 0x7e) {
                return { end: index + 1, sgr: raw[index] === 'm' ? raw.slice(from, index) : null };
            }

            index++;
        }

        return { end: raw.length, sgr: null };
    }

    if (osc) {
        // OSC (window titles, OSC 8 hyperlinks) ends at BEL or ESC \; its payload is never shown or linked.
        for (let index = start + 2; index < raw.length; index++) {
            if (raw[index] === '\u0007') {
                return { end: index + 1, sgr: null };
            }

            if (raw[index] === '\u001b' && raw[index + 1] === '\\') {
                return { end: index + 2, sgr: null };
            }
        }

        return { end: raw.length, sgr: null };
    }

    // A two-character escape (ESC 7, ESC c, ...), or a lone ESC at the end.
    return { end: Math.min(start + 2, raw.length), sgr: null };
}

/** Parses one line of output into styled runs; escape sequences other than colours and styles are dropped. */
export function parseAnsi(raw: string): ConsoleSegment[] {
    const style = plainStyle();
    let segments: ConsoleSegment[] = [];
    let text = '';
    let length = 0;

    const flush = () => {
        if (text === '') {
            return;
        }

        const segment = segmentOf(text, style);
        const last = segments.at(-1);

        if (last && sameLook(last, segment)) {
            segments[segments.length - 1] = { ...last, text: last.text + segment.text };
        } else {
            segments.push(segment);
        }

        text = '';
    };

    for (let index = 0; index < raw.length && length < MAX_LINE_LENGTH;) {
        const char = raw[index];
        const code = raw.charCodeAt(index);

        if (char === '\u001b' || char === '\u009b') {
            const { end, sgr } = readEscape(raw, index);

            if (sgr !== null) {
                flush();
                applySgr(style, sgr);
            }

            index = end;
            continue;
        }

        if (char === '\r') {
            // A carriage return starts the line over, as progress bars expect; a trailing one changes nothing.
            if (index < raw.length - 1) {
                text = '';
                segments = [];
                length = 0;
            }
        } else if (char === '\t' || (code >= 0x20 && code !== 0x7f && !(code >= 0x80 && code < 0xa0))) {
            text += char;
            length++;
        }

        index++;
    }

    flush();

    return segments;
}

// Characters a URL in a log line ends at; quotes and brackets around it are not part of it.
const URL_PATTERN = /https?:\/\/[^\s<>"'`{}|\\^]+/gi;
const TRAILING_PUNCTUATION = /[.,;:!?)\]}*]+$/;

/** The URL to link `candidate` to, or null unless it is a plain https URL. */
export function safeLink(candidate: string): string | null {
    if (candidate.length > MAX_URL_LENGTH) {
        return null;
    }

    try {
        const url = new URL(candidate);

        // Credentials in a URL (https://bank.example@evil.example) only serve to disguise where it goes.
        if (url.protocol !== 'https:' || url.hostname === '' || url.username !== '' || url.password !== '') {
            return null;
        }

        return url.href;
    } catch {
        return null;
    }
}

/** Splits runs at the https URLs in their text, marking those parts as links. */
export function linkify(segments: readonly ConsoleSegment[]): ConsoleSegment[] {
    return segments.flatMap((segment) => {
        const parts: ConsoleSegment[] = [];
        let cursor = 0;

        for (const match of segment.text.matchAll(URL_PATTERN)) {
            const candidate = match[0].replace(TRAILING_PUNCTUATION, '');
            const href = safeLink(candidate);

            if (href === null) {
                continue;
            }

            if (match.index > cursor) {
                parts.push({ ...segment, text: segment.text.slice(cursor, match.index) });
            }

            parts.push({ ...segment, text: candidate, href });
            cursor = match.index + candidate.length;
        }

        if (cursor === 0) {
            return [segment];
        }

        if (cursor < segment.text.length) {
            parts.push({ ...segment, text: segment.text.slice(cursor) });
        }

        return parts;
    });
}

// The level word in a line's header: "[12:00:01 WARN]:", "[Server thread/ERROR]:", "[INFO] ...".
const LEVEL_PATTERN = /\b(SEVERE|FATAL|ERROR|WARNING|WARN|INFO)\b/;
const HEADER_LENGTH = 64;

const levelOf = (word: string): ConsoleLineLevel => {
    if (word === 'INFO') {
        return 'info';
    }

    return word.startsWith('WARN') ? 'warn' : 'error';
};

/** Finds the level word near the start of the line and marks the run part that holds it. */
function markLevel(segments: readonly ConsoleSegment[], text: string): [ConsoleSegment[], ConsoleLineLevel | null] {
    const match = LEVEL_PATTERN.exec(text.slice(0, HEADER_LENGTH));

    if (!match) {
        return [[...segments], null];
    }

    const level = levelOf(match[1]);
    const start = match.index;
    const end = start + match[1].length;
    const marked: ConsoleSegment[] = [];
    let offset = 0;

    for (const segment of segments) {
        const from = offset;
        const to = offset + segment.text.length;

        offset = to;

        if (to <= start || from >= end || segment.href !== null) {
            marked.push(segment);
            continue;
        }

        const cuts = [from, Math.max(from, start), Math.min(to, end), to];

        for (let index = 0; index < 3; index++) {
            const piece = segment.text.slice(cuts[index] - from, cuts[index + 1] - from);

            if (piece !== '') {
                marked.push({ ...segment, text: piece, level: index === 1 ? level : null });
            }
        }
    }

    return [marked, level];
}

/** Parses a line of output: its plain text, styled runs with their links, and its log level. */
export function parseConsoleLine(raw: string, id: number): ConsoleLine {
    const parsed = parseAnsi(raw);
    const text = parsed.map((segment) => segment.text).join('');
    const [segments, level] = markLevel(linkify(parsed), text);

    return { id, text, segments, level };
}

/** Removes escape sequences and control characters, leaving the text a line shows. */
export function stripAnsi(raw: string): string {
    return parseAnsi(raw)
        .map((segment) => segment.text)
        .join('');
}

const NOTIFY_DELAY_MS = 32;

/**
 * The lines of a server's console, written by the socket and read by themes. Readers are told
 * of changes at most every few frames, so a burst of history renders once; `onChange` listeners
 * hear every change as it happens.
 */
export class ConsoleOutputStore {
    private lines: ConsoleLine[] = [];
    /** The lines as written, escape sequences included, for a terminal that draws them itself. */
    private raws: string[] = [];
    private snapshot: readonly ConsoleLine[] = [];
    private nextId = 1;
    private readonly listeners = new Set<() => void>();
    private readonly changeListeners = new Set<(change: ConsoleOutputChange) => void>();
    private timer: ReturnType<typeof setTimeout> | null = null;

    constructor(private readonly limit = 2_000) {}

    readonly subscribe = (listener: () => void): (() => void) => {
        this.listeners.add(listener);

        return () => {
            this.listeners.delete(listener);
        };
    };

    readonly getSnapshot = (): readonly ConsoleLine[] => this.snapshot;

    /** Hears each line and clear as it happens. */
    onChange(listener: (change: ConsoleOutputChange) => void): () => void {
        this.changeListeners.add(listener);

        return () => {
            this.changeListeners.delete(listener);
        };
    }

    writeln(raw: string): void {
        const line = parseConsoleLine(raw, this.nextId++);

        this.lines.push(line);
        this.raws.push(raw);
        // Trim in batches so a busy console does not shift the arrays on every line.
        if (this.lines.length > this.limit + 100) {
            this.lines.splice(0, this.lines.length - this.limit);
            this.raws.splice(0, this.raws.length - this.limit);
        }

        this.emit({ kind: 'append', line, raw });
    }

    clear(): void {
        this.lines = [];
        this.raws = [];
        this.emit({ kind: 'clear' });
    }

    /** Every retained line as plain text. */
    text(): string {
        return this.retained()
            .map((line) => line.text)
            .join('\n');
    }

    /** Every retained line as written. */
    rawLines(): string[] {
        return this.raws.slice(-this.limit);
    }

    /** Sends any pending change to readers now. */
    flush(): void {
        if (this.timer !== null) {
            clearTimeout(this.timer);
            this.timer = null;
        }

        this.snapshot = this.retained();
        for (const listener of this.listeners) {
            listener();
        }
    }

    private retained(): ConsoleLine[] {
        return this.lines.length > this.limit ? this.lines.slice(-this.limit) : [...this.lines];
    }

    private emit(change: ConsoleOutputChange): void {
        for (const listener of this.changeListeners) {
            listener(change);
        }

        this.timer ??= setTimeout(() => {
            this.timer = null;
            this.flush();
        }, NOTIFY_DELAY_MS);
    }
}
