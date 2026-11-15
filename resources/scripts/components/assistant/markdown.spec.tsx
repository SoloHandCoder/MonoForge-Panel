/** @vitest-environment jsdom */
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import Markdown, { linkTarget, parseInline, parseMarkdown } from './markdown';

afterEach(cleanup);

describe('parseMarkdown', () => {
    it('splits paragraphs, headings, lists, quotes and fenced code', () => {
        expect(
            parseMarkdown('# Title\n\nOne\ntwo\n\n- a\n- b\n\n1. x\n2. y\n\n> note\n\n```yaml\nkey: 1\n```')
        ).toEqual([
            { kind: 'heading', level: 1, text: 'Title' },
            { kind: 'paragraph', text: 'One\ntwo' },
            { kind: 'list', ordered: false, items: ['a', 'b'] },
            { kind: 'list', ordered: true, items: ['x', 'y'] },
            { kind: 'quote', text: 'note' },
            { kind: 'code', language: 'yaml', code: 'key: 1' },
        ]);
    });

    it('runs an unclosed fence to the end', () => {
        expect(parseMarkdown('```\nline')).toEqual([{ kind: 'code', language: '', code: 'line' }]);
    });
});

describe('parseInline', () => {
    it('keeps only http(s) links', () => {
        expect(parseInline('[ok](https://x.test) [bad](javascript:alert(1))')).toEqual([
            { kind: 'link', text: 'ok', href: 'https://x.test' },
            { kind: 'text', text: ' [bad](javascript:alert(1))' },
        ]);
    });

    it('links only https addresses with a host name, never plain http or raw IPs', () => {
        expect(linkTarget('https://docs.papermc.io/paper')).toBe('https://docs.papermc.io/paper');
        expect(linkTarget('http://docs.test')).toBeNull();
        expect(linkTarget('https://203.0.113.9/x')).toBeNull();
        expect(linkTarget('https://3405803785/x')).toBeNull();
        expect(linkTarget('https://[2001:db8::1]/x')).toBeNull();
        expect(linkTarget('https://user:pass@docs.test')).toBeNull();
    });
});

describe('Markdown', () => {
    it('renders raw HTML as text, never as elements', () => {
        const { container } = render(
            <Markdown source={'<script>alert(1)</script>\n\n<img src=x onerror="alert(1)"> **bold** `code`'} />
        );

        expect(container.querySelector('script, img')).toBeNull();
        expect(screen.getByText('<script>alert(1)</script>')).toBeInTheDocument();
        expect(container.querySelector('strong')).toHaveTextContent('bold');
        expect(container.querySelector('code')).toHaveTextContent('code');
    });

    it('gives code blocks a copy button and safe links', () => {
        const { container } = render(<Markdown source={'See [docs](https://docs.test).\n\n```sh\nls -la\n```'} />);

        expect(screen.getByRole('button', { name: 'Copy code' })).toBeInTheDocument();
        expect(container.querySelector('pre')).toHaveTextContent('ls -la');
        expect(screen.getByRole('link', { name: 'https://docs.test' })).toHaveAttribute(
            'rel',
            'noopener noreferrer nofollow'
        );
    });

    it('shows the full address as the link text, so a label cannot hide where it goes', () => {
        const { container } = render(<Markdown source='Read the [Paper docs](https://evil.test/c?d=rcon-secret).' />);

        const link = screen.getByRole('link');

        expect(link).toHaveTextContent('https://evil.test/c?d=rcon-secret');
        expect(link).toHaveAttribute('href', 'https://evil.test/c?d=rcon-secret');
        expect(container).toHaveTextContent('Read the Paper docs (https://evil.test/c?d=rcon-secret).');
    });

    it('does not link plain http or raw IP addresses', () => {
        const { container } = render(<Markdown source='[a](http://docs.test) and [b](https://198.51.100.4/x)' />);

        expect(screen.queryByRole('link')).toBeNull();
        expect(container).toHaveTextContent('a (http://docs.test) and b (https://198.51.100.4/x)');
    });
});
