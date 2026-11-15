/* oxlint-disable no-use-before-define -- the inline renderers recurse into each other */
import { Fragment, type ReactNode } from 'react';
import { cn } from '@/lib/cn';

/**
 * A small, safe markdown renderer for the legal documents: headings, paragraphs, lists, quotes,
 * rules, links, bold, italic and inline code. It builds React elements and never HTML strings,
 * so markup in the source (`<script>`, `<img onerror>`) is shown as text, and only https:// and
 * same-site `/path` links become links.
 */

type Block =
    | { kind: 'heading'; level: number; text: string }
    | { kind: 'paragraph'; text: string }
    | { kind: 'quote'; text: string }
    | { kind: 'list'; ordered: boolean; items: string[] }
    | { kind: 'rule' };

const HEADING = /^(#{1,6})\s+(.+?)\s*#*\s*$/;
const BULLET = /^\s*[-*+]\s+(.*)$/;
const NUMBERED = /^\s*\d+[.)]\s+(.*)$/;
const QUOTE = /^\s*>\s?(.*)$/;
const RULE = /^\s*(?:-{3,}|\*{3,}|_{3,})\s*$/;
const CONTINUATION = /^\s+\S/;

/** A link target the renderer follows: an absolute https URL, or a path on this site. */
export function safeMarkdownHref(href: string): string | null {
    const value = href.trim();

    if (/^\/(?!\/)[^\s\\]*$/.test(value)) {
        return value;
    }

    try {
        const url = new URL(value);

        return url.protocol === 'https:' && !/[\s\\]/.test(value) ? url.href : null;
    } catch {
        return null;
    }
}

/** Collects lines into blocks; a block that is still open takes the lines that continue it. */
class BlockParser {
    readonly blocks: Block[] = [];
    private paragraph: string[] = [];
    private quote: string[] = [];
    private list: { ordered: boolean; items: string[] } | null = null;

    flush(): void {
        if (this.paragraph.length > 0) {
            this.blocks.push({ kind: 'paragraph', text: this.paragraph.join(' ') });
            this.paragraph = [];
        }

        if (this.quote.length > 0) {
            this.blocks.push({ kind: 'quote', text: this.quote.join(' ') });
            this.quote = [];
        }

        if (this.list) {
            this.blocks.push({ kind: 'list', ordered: this.list.ordered, items: this.list.items });
            this.list = null;
        }
    }

    line(line: string): void {
        if (line.trim() === '') {
            this.flush();

            return;
        }

        if (!this.standalone(line) && !this.listItem(line) && !this.quoted(line)) {
            this.text(line);
        }
    }

    /** A heading or a rule: a block of its own. */
    private standalone(line: string): boolean {
        const heading = HEADING.exec(line);

        if (heading) {
            this.flush();
            this.blocks.push({ kind: 'heading', level: heading[1].length, text: heading[2] });

            return true;
        }

        if (RULE.test(line)) {
            this.flush();
            this.blocks.push({ kind: 'rule' });

            return true;
        }

        return false;
    }

    private listItem(line: string): boolean {
        const bullet = BULLET.exec(line);
        const item = bullet ?? NUMBERED.exec(line);

        if (!item) {
            return false;
        }

        const ordered = !bullet;

        if (!this.list || this.list.ordered !== ordered) {
            this.flush();
            this.list = { ordered, items: [] };
        }

        this.list.items.push(item[1]);

        return true;
    }

    private quoted(line: string): boolean {
        const quoted = QUOTE.exec(line);

        if (!quoted) {
            return false;
        }

        if (this.quote.length === 0) {
            this.flush();
        }

        this.quote.push(quoted[1]);

        return true;
    }

    private text(line: string): void {
        if (this.list && CONTINUATION.test(line)) {
            // An indented line continues the list item above it.
            this.list.items[this.list.items.length - 1] += ` ${line.trim()}`;

            return;
        }

        if (this.quote.length > 0 || this.list) {
            this.flush();
        }

        this.paragraph.push(line.trim());
    }
}

function parseBlocks(source: string): Block[] {
    const parser = new BlockParser();

    for (const line of source.replaceAll(/\r\n?/g, '\n').split('\n')) {
        parser.line(line);
    }

    parser.flush();

    return parser.blocks;
}

/** The longest link label, link target and code or emphasis span the renderer turns into markup. */
export const MAX_LINK_LABEL = 500;
export const MAX_LINK_HREF = 2048;
export const MAX_SPAN = 2000;

type InlineToken =
    | { kind: 'code' | 'strong' | 'em'; end: number; inner: string }
    | { kind: 'link'; end: number; inner: string; href: string };

/**
 * The next position of a delimiter, remembered per delimiter. Lookups for one delimiter only
 * move forward through the text, so a remembered position that is still ahead is the answer,
 * and one that ran off the end stays off it: every character is searched at most once per
 * delimiter, which keeps an unclosed `[[[[…` or `**a **a …` linear instead of quadratic.
 */
class DelimiterFinder {
    private readonly found = new Map<string, number>();

    constructor(private readonly text: string) {}

    next(delimiter: string, from: number): number {
        const known = this.found.get(delimiter);

        if (known !== undefined && (known === -1 || known >= from)) {
            return known;
        }

        const position = this.text.indexOf(delimiter, from);

        this.found.set(delimiter, position);

        return position;
    }
}

const isWordChar = (char: string | undefined): boolean => char !== undefined && /\w/.test(char);
const isSpace = (char: string | undefined): boolean => char !== undefined && /\s/.test(char);

/** A span opened at `start` (after an `open`-long opener) and closed by `close`, within `max`. */
function span(text: string, find: DelimiterFinder, start: number, open: number, close: string, max: number) {
    const end = find.next(close, start + open);

    return end > start + open && end - start - open <= max ? { end, inner: text.slice(start + open, end) } : null;
}

function linkAt(text: string, find: DelimiterFinder, start: number): InlineToken | null {
    const label = span(text, find, start, 1, ']', MAX_LINK_LABEL);

    if (!label || text[label.end + 1] !== '(') {
        return null;
    }

    const target = span(text, find, label.end + 1, 1, ')', MAX_LINK_HREF);

    return target && !/\s/.test(target.inner)
        ? { kind: 'link', end: target.end + 1, inner: label.inner, href: target.inner }
        : null;
}

function emphasisAt(text: string, find: DelimiterFinder, start: number): InlineToken | null {
    const char = text[start];

    if (char === '*' && text[start + 1] === '*') {
        const strong = span(text, find, start, 2, '**', MAX_SPAN);

        if (strong) {
            return { kind: 'strong', end: strong.end + 2, inner: strong.inner };
        }
    }

    if (isSpace(text[start + 1]) || (char === '_' && isWordChar(text[start - 1]))) {
        return null;
    }

    const em = span(text, find, start, 1, char, MAX_SPAN);

    if (!em || (char === '_' && isWordChar(text[em.end + 1]))) {
        return null;
    }

    return { kind: 'em', end: em.end + 1, inner: em.inner };
}

/** The inline token opening at `start`, if any. */
function tokenAt(text: string, find: DelimiterFinder, start: number): InlineToken | null {
    switch (text[start]) {
        case '`': {
            const code = span(text, find, start, 1, '`', MAX_SPAN);

            return code ? { kind: 'code', end: code.end + 1, inner: code.inner } : null;
        }

        case '[':
            return linkAt(text, find, start);

        case '*':
        case '_':
            return emphasisAt(text, find, start);

        default:
            return null;
    }
}

const linkClass = 'font-medium text-accent underline underline-offset-2 hover:text-foreground';

function renderLink(label: string, href: string, id: string): ReactNode {
    const target = safeMarkdownHref(href);
    const children = renderInline(label, id);

    if (target === null) {
        return <Fragment key={id}>{children}</Fragment>;
    }

    if (target.startsWith('/')) {
        return (
            <a key={id} href={target} className={linkClass}>
                {children}
            </a>
        );
    }

    return (
        <a key={id} href={target} target='_blank' rel='noopener noreferrer nofollow' className={linkClass}>
            {children}
        </a>
    );
}

function renderToken(token: InlineToken, id: string): ReactNode {
    switch (token.kind) {
        case 'code':
            return (
                <code key={id} className='rounded bg-muted px-1 py-0.5 font-mono'>
                    {token.inner}
                </code>
            );

        case 'link':
            return renderLink(token.inner, token.href, id);

        case 'strong':
            return (
                <strong key={id} className='font-semibold text-foreground'>
                    {renderInline(token.inner, id)}
                </strong>
            );

        case 'em':
            return <em key={id}>{renderInline(token.inner, id)}</em>;
    }
}

/** Code, links, strong and emphasis in one forward scan of the text. */
function renderInline(text: string, key = 'i'): ReactNode[] {
    const find = new DelimiterFinder(text);
    const nodes: ReactNode[] = [];
    let plain = 0;
    let index = 0;

    for (let position = 0; position < text.length;) {
        const token = tokenAt(text, find, position);

        if (!token) {
            position++;
            continue;
        }

        if (position > plain) {
            nodes.push(text.slice(plain, position));
        }

        nodes.push(renderToken(token, `${key}-${index++}`));
        position = token.end;
        plain = position;
    }

    if (plain < text.length) {
        nodes.push(text.slice(plain));
    }

    return nodes;
}

const headingClass = {
    1: 'mt-0 mb-4 font-header text-3xl font-bold text-foreground',
    2: 'mt-8 mb-3 font-header text-xl font-semibold text-foreground',
    3: 'mt-6 mb-2 font-header text-lg font-semibold text-foreground',
} as const;

const headingTags = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as const;

function renderBlock(block: Block, index: number): ReactNode {
    const key = `b${index}`;

    switch (block.kind) {
        case 'heading': {
            const Tag = headingTags[Math.min(block.level, 6) - 1];
            const className =
                block.level === 1 || block.level === 2 || block.level === 3
                    ? headingClass[block.level]
                    : 'mt-5 mb-2 text-base font-semibold text-foreground';

            return (
                <Tag key={key} className={className}>
                    {renderInline(block.text, key)}
                </Tag>
            );
        }

        case 'paragraph':
            return (
                <p key={key} className='my-3 leading-relaxed'>
                    {renderInline(block.text, key)}
                </p>
            );

        case 'quote':
            return (
                <blockquote
                    key={key}
                    className='my-4 rounded-lg border border-border border-l-4 border-l-accent bg-muted/40 px-4 py-3'
                >
                    {renderInline(block.text, key)}
                </blockquote>
            );

        case 'list': {
            const Tag = block.ordered ? 'ol' : 'ul';

            return (
                <Tag key={key} className={cn('my-3 grid gap-1.5 pl-6', block.ordered ? 'list-decimal' : 'list-disc')}>
                    {block.items.map((item, itemIndex) => (
                        // oxlint-disable-next-line react/no-array-index-key -- items have no identity beyond their place
                        <li key={itemIndex} className='leading-relaxed'>
                            {renderInline(item, `${key}-${itemIndex}`)}
                        </li>
                    ))}
                </Tag>
            );
        }

        case 'rule':
            return <hr key={key} className='my-6 border-border' />;
    }
}

/** The React elements of a markdown document. */
export function renderMarkdown(source: string): ReactNode[] {
    return parseBlocks(source).map(renderBlock);
}

export default function Markdown({ source, className }: { source: string; className?: string }) {
    return <div className={cn('text-sm text-muted-foreground sm:text-base', className)}>{renderMarkdown(source)}</div>;
}
