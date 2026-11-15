import { useEffect, useState, type ReactNode } from 'react';
import { Check, Copy } from 'lucide-react';
import Icon from '@/components/elements/Icon';
import copyToClipboard from '@/lib/clipboard';
import { cn } from '@/lib/cn';

/*
 * A tiny Markdown renderer for assistant replies. It builds React elements and never HTML strings, so
 * whatever the model writes, including tags, renders as text. It knows paragraphs, headings, lists,
 * quotes, fenced code, inline code, bold, italics and links (https only, shown with their full address).
 */

export type MarkdownBlock =
    | { readonly kind: 'paragraph'; readonly text: string }
    | { readonly kind: 'heading'; readonly level: 1 | 2 | 3; readonly text: string }
    | { readonly kind: 'list'; readonly ordered: boolean; readonly items: readonly string[] }
    | { readonly kind: 'quote'; readonly text: string }
    | { readonly kind: 'code'; readonly language: string; readonly code: string };

const fence = /^\s*(```|~~~)\s*([\w+#.-]*)\s*$/;
const heading = /^(#{1,6})\s+(.*)$/;
const bullet = /^\s*[-*+]\s+(.*)$/;
const numbered = /^\s*\d+[.)]\s+(.*)$/;
const quote = /^\s*>\s?(.*)$/;

const listItem = (line: string, ordered: boolean): string | null =>
    (ordered ? numbered : bullet).exec(line)?.[1] ?? null;

/** The code block opening at `start`; an unclosed fence runs to the end, as it does while text streams in. */
function readCode(lines: readonly string[], start: number, language: string): [MarkdownBlock, number] {
    const marker = fence.exec(lines[start] ?? '')?.[1] ?? '```';
    const body: string[] = [];
    let index = start + 1;

    while (index < lines.length && !(lines[index] ?? '').trim().startsWith(marker)) {
        body.push(lines[index] ?? '');
        index += 1;
    }

    return [{ kind: 'code', language, code: body.join('\n') }, index + 1];
}

function readList(lines: readonly string[], start: number, ordered: boolean): [MarkdownBlock, number] {
    const items: string[] = [];
    let index = start;

    while (index < lines.length) {
        const line = lines[index] ?? '';
        const item = listItem(line, ordered);

        if (item !== null) {
            items.push(item);
        } else if (line.trim() !== '' && /^\s{2,}/.test(line) && items.length) {
            // An indented continuation line belongs to the item above it.
            items[items.length - 1] = `${items.at(-1) ?? ''} ${line.trim()}`;
        } else {
            break;
        }

        index += 1;
    }

    return [{ kind: 'list', ordered, items }, index];
}

const startsBlock = (line: string): boolean =>
    fence.test(line) || heading.test(line) || bullet.test(line) || numbered.test(line) || quote.test(line);

function readHeading(line: string, start: number): [MarkdownBlock, number] | null {
    const title = heading.exec(line);

    if (!title) {
        return null;
    }

    const level = Math.min(title[1]?.length ?? 1, 3) as 1 | 2 | 3;

    return [{ kind: 'heading', level, text: title[2] ?? '' }, start + 1];
}

function readQuote(lines: readonly string[], start: number): [MarkdownBlock, number] | null {
    const quoted: string[] = [];
    let index = start;
    let match = quote.exec(lines[index] ?? '');

    while (match) {
        quoted.push(match[1] ?? '');
        index += 1;
        match = quote.exec(lines[index] ?? '');
    }

    return quoted.length ? [{ kind: 'quote', text: quoted.join('\n') }, index] : null;
}

function readParagraph(lines: readonly string[], start: number): [MarkdownBlock, number] {
    const paragraph: string[] = [lines[start] ?? ''];
    let index = start + 1;
    const continues = (line: string | undefined) => line !== undefined && line.trim() !== '' && !startsBlock(line);

    while (continues(lines[index])) {
        paragraph.push(lines[index] ?? '');
        index += 1;
    }

    return [{ kind: 'paragraph', text: paragraph.join('\n') }, index];
}

function readBlock(lines: readonly string[], index: number): [MarkdownBlock, number] {
    const line = lines[index] ?? '';
    const opening = fence.exec(line);

    if (opening) {
        return readCode(lines, index, opening[2] ?? '');
    }

    if (bullet.test(line) || numbered.test(line)) {
        return readList(lines, index, !bullet.test(line));
    }

    return readHeading(line, index) ?? readQuote(lines, index) ?? readParagraph(lines, index);
}

/** Splits Markdown into blocks. */
export function parseMarkdown(source: string): MarkdownBlock[] {
    const lines = source.replaceAll('\r\n', '\n').split('\n');
    const blocks: MarkdownBlock[] = [];
    let index = 0;

    while (index < lines.length) {
        if ((lines[index] ?? '').trim() === '') {
            index += 1;
            continue;
        }

        const [block, next] = readBlock(lines, index);

        blocks.push(block);
        index = next;
    }

    return blocks;
}

export type MarkdownInline =
    | { readonly kind: 'text'; readonly text: string }
    | { readonly kind: 'code'; readonly text: string }
    | { readonly kind: 'strong'; readonly text: string }
    | { readonly kind: 'em'; readonly text: string }
    | { readonly kind: 'link'; readonly text: string; readonly href: string };

const inlinePattern =
    /`([^`\n]+)`|\*\*([^*\n]+)\*\*|__([^_\n]+)__|\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)|\*([^*\s][^*\n]*)\*|(?<![\w])_([^_\s][^_\n]*)_(?![\w])/g;

/** Splits a line of Markdown into inline spans. Only http(s) URLs become links. */
export function parseInline(text: string): MarkdownInline[] {
    const spans: MarkdownInline[] = [];
    let last = 0;

    for (const match of text.matchAll(inlinePattern)) {
        const at = match.index;

        if (at > last) {
            spans.push({ kind: 'text', text: text.slice(last, at) });
        }

        const [, code, strong, strongAlt, label, href, em, emAlt] = match;

        if (code !== undefined) {
            spans.push({ kind: 'code', text: code });
        } else if (label !== undefined && href !== undefined) {
            spans.push({ kind: 'link', text: label, href });
        } else if (strong !== undefined || strongAlt !== undefined) {
            spans.push({ kind: 'strong', text: strong ?? strongAlt ?? '' });
        } else {
            spans.push({ kind: 'em', text: em ?? emAlt ?? '' });
        }

        last = at + match[0].length;
    }

    if (last < text.length) {
        spans.push({ kind: 'text', text: text.slice(last) });
    }

    return spans;
}

const ipv4Host = /^\d{1,3}(\.\d{1,3}){3}$/;

/**
 * The address a link may open, or null. Only https addresses with a real host name are linked:
 * plain http, raw IP addresses (in any spelling, which URL normalises) and addresses with a
 * user name or password stay text, so a link is less useful for phishing or for sending data
 * to a server the model picked.
 */
export function linkTarget(href: string): string | null {
    let url: URL;

    try {
        url = new URL(href);
    } catch {
        return null;
    }

    const host = url.hostname;

    if (url.protocol !== 'https:' || host === '' || host.startsWith('[') || ipv4Host.test(host)) {
        return null;
    }

    return url.username === '' && url.password === '' ? href : null;
}

/** A link shows its full address as its text, so a label never hides where it goes. */
function renderLink(span: Extract<MarkdownInline, { kind: 'link' }>, key: number): ReactNode {
    const target = linkTarget(span.href);
    const label = span.text.trim() === span.href ? null : span.text;

    if (target === null) {
        return label === null ? span.href : `${label} (${span.href})`;
    }

    const anchor = (
        <a
            key={label === null ? key : undefined}
            href={target}
            target='_blank'
            rel='noopener noreferrer nofollow'
            className='break-all text-primary underline underline-offset-2 hover:opacity-80'
        >
            {target}
        </a>
    );

    return label === null ? (
        anchor
    ) : (
        <span key={key}>
            {label} ({anchor})
        </span>
    );
}

function renderSpan(span: MarkdownInline, key: number): ReactNode {
    switch (span.kind) {
        case 'text':
            return span.text;
        case 'code':
            return (
                <code key={key} className='rounded-md bg-sunken px-1.5 py-0.5 font-mono text-xs text-foreground'>
                    {span.text}
                </code>
            );
        case 'strong':
            return (
                <strong key={key} className='font-semibold text-foreground'>
                    {span.text}
                </strong>
            );
        case 'em':
            return <em key={key}>{span.text}</em>;
        case 'link':
            return renderLink(span, key);
    }
}

/** Inline Markdown, with single line breaks kept. */
function Inline({ text }: { text: string }) {
    const lines = text.split('\n');

    return (
        <>
            {lines.map((line, index) => (
                // oxlint-disable-next-line react/no-array-index-key -- lines have no identity of their own.
                <span key={index}>
                    {index > 0 && <br />}
                    {parseInline(line).map(renderSpan)}
                </span>
            ))}
        </>
    );
}

export function CopyButton({ text, label = 'Copy code' }: { text: string; label?: string }) {
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        if (!copied) {
            return;
        }

        const timeout = setTimeout(() => setCopied(false), 2000);

        return () => clearTimeout(timeout);
    }, [copied]);

    return (
        <button
            type='button'
            aria-label={copied ? 'Copied' : label}
            title={copied ? 'Copied' : label}
            onClick={() => {
                void copyToClipboard(text).then(setCopied);
            }}
            className='inline-flex size-7 items-center justify-center rounded-md text-muted-foreground transition-colors duration-150 hover:bg-(--hover-row) hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none'
        >
            <Icon icon={copied ? Check : Copy} className={cn('size-3.5', copied && 'text-success')} aria-hidden />
        </button>
    );
}

function CodeBlock({ language, code }: { language: string; code: string }) {
    return (
        <div className='overflow-hidden rounded-lg border border-border bg-sunken'>
            <div className='flex items-center justify-between border-b border-border py-0.5 pr-1 pl-3'>
                <span className='font-mono text-xs text-muted-foreground'>{language || 'text'}</span>
                <CopyButton text={code} />
            </div>
            <pre className='overflow-x-auto p-3 text-xs leading-relaxed'>
                <code className='font-mono text-foreground'>{code}</code>
            </pre>
        </div>
    );
}

const headingClasses = {
    1: 'text-lg font-semibold',
    2: 'text-base font-semibold',
    3: 'text-sm font-semibold uppercase tracking-wide text-muted-foreground',
} as const;

function List({ ordered, items }: { ordered: boolean; items: readonly string[] }) {
    const Tag = ordered ? 'ol' : 'ul';

    return (
        <Tag className={cn('space-y-1 pl-5', ordered ? 'list-decimal' : 'list-disc')}>
            {items.map((item, index) => (
                // oxlint-disable-next-line react/no-array-index-key -- items have no identity of their own.
                <li key={index} className='pl-1 marker:text-muted-foreground'>
                    <Inline text={item} />
                </li>
            ))}
        </Tag>
    );
}

function renderBlock(block: MarkdownBlock, key: number): ReactNode {
    switch (block.kind) {
        case 'paragraph':
            return (
                <p key={key}>
                    <Inline text={block.text} />
                </p>
            );
        case 'heading':
            return (
                <p key={key} role='heading' aria-level={block.level + 2} className={headingClasses[block.level]}>
                    <Inline text={block.text} />
                </p>
            );
        case 'list':
            return <List key={key} ordered={block.ordered} items={block.items} />;
        case 'quote':
            return (
                <blockquote key={key} className='border-l-2 border-primary/40 pl-3 text-muted-foreground'>
                    <Inline text={block.text} />
                </blockquote>
            );
        case 'code':
            return <CodeBlock key={key} language={block.language} code={block.code} />;
    }
}

/** Assistant text as Markdown, without any raw HTML. */
export default function Markdown({ source, className }: { source: string; className?: string }) {
    return (
        <div className={cn('space-y-3 text-sm leading-relaxed break-words', className)}>
            {parseMarkdown(source).map(renderBlock)}
        </div>
    );
}
