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

// Order matters: code first (its content is literal), then links, then strong before emphasis.
const INLINE =
    /`([^`]+)`|\[([^\]]+)\]\(([^)\s]+)\)|\*\*(.+?)\*\*|__(.+?)__|\*(?!\s)(.+?)\*|(?<![\w])_(?!\s)(.+?)_(?![\w])/;

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

/** One inline match: code, a link, strong or emphasis. */
function renderToken(match: RegExpExecArray, id: string): ReactNode {
    const [, code, label, href, strong, strongAlt, em, emAlt] = match;

    if (code !== undefined) {
        return (
            <code key={id} className='rounded bg-muted px-1 py-0.5 font-mono'>
                {code}
            </code>
        );
    }

    if (label !== undefined && href !== undefined) {
        return renderLink(label, href, id);
    }

    const bold = strong ?? strongAlt;

    if (bold !== undefined) {
        return (
            <strong key={id} className='font-semibold text-foreground'>
                {renderInline(bold, id)}
            </strong>
        );
    }

    return <em key={id}>{renderInline(em ?? emAlt ?? '', id)}</em>;
}

function renderInline(text: string, key = 'i'): ReactNode[] {
    const nodes: ReactNode[] = [];
    let rest = text;
    let index = 0;

    for (let match = INLINE.exec(rest); match; match = INLINE.exec(rest)) {
        if (match.index > 0) {
            nodes.push(rest.slice(0, match.index));
        }

        nodes.push(renderToken(match, `${key}-${index++}`));
        rest = rest.slice(match.index + match[0].length);
    }

    if (rest.length > 0) {
        nodes.push(rest);
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
