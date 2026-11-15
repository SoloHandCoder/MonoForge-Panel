/** @vitest-environment jsdom */
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import Markdown, { parseInline, parseMarkdown } from './markdown';

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
        expect(screen.getByRole('link', { name: 'docs' })).toHaveAttribute('rel', 'noopener noreferrer nofollow');
    });
});
