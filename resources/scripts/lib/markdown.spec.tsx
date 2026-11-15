/** @vitest-environment jsdom */

import { cleanup, render } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import Markdown, { safeMarkdownHref } from './markdown';

afterEach(cleanup);

const html = (source: string) => render(<Markdown source={source} />).container.innerHTML;

describe('markdown', () => {
    it('renders headings, paragraphs, lists, quotes and rules', () => {
        const { container } = render(
            <Markdown
                source={[
                    '# Terms',
                    '',
                    'First line',
                    'continues here.',
                    '',
                    '## Lists',
                    '- one',
                    '- **two**',
                    '',
                    '1. first',
                    '2. *second*',
                    '',
                    '> A note',
                    '',
                    '---',
                    'Use `code` and _this_.',
                ].join('\n')}
            />
        );

        expect(container.querySelector('h1')?.textContent).toBe('Terms');
        expect(container.querySelector('h2')?.textContent).toBe('Lists');
        expect(container.querySelector('p')?.textContent).toBe('First line continues here.');
        expect([...container.querySelectorAll('ul li')].map((item) => item.textContent)).toEqual(['one', 'two']);
        expect(container.querySelector('ul strong')?.textContent).toBe('two');
        expect([...container.querySelectorAll('ol li')].map((item) => item.textContent)).toEqual(['first', 'second']);
        expect(container.querySelector('ol em')?.textContent).toBe('second');
        expect(container.querySelector('blockquote')?.textContent).toBe('A note');
        expect(container.querySelector('hr')).not.toBeNull();
        expect(container.querySelector('code')?.textContent).toBe('code');
    });

    it('opens https links in a new tab and keeps site links in place', () => {
        const { container } = render(
            <Markdown source='See [Discord](https://discord.gg/B3fd45pgvC) and the [privacy policy](/legal/privacy).' />
        );
        const [external, internal] = container.querySelectorAll('a');

        expect(external.getAttribute('href')).toBe('https://discord.gg/B3fd45pgvC');
        expect(external.getAttribute('target')).toBe('_blank');
        expect(external.getAttribute('rel')).toBe('noopener noreferrer nofollow');
        expect(internal.getAttribute('href')).toBe('/legal/privacy');
        expect(internal.hasAttribute('target')).toBe(false);
    });

    it('never renders raw HTML', () => {
        const output = html(
            [
                '<script>alert(1)</script>',
                '',
                '<img src=x onerror="alert(1)">',
                '',
                '**<b onclick="alert(1)">bold</b>**',
                '',
                '# <iframe src="https://evil.example"></iframe>',
            ].join('\n')
        );

        expect(output).not.toMatch(/<(script|img|iframe|b)\b/i);
        expect(output).toContain('&lt;script&gt;alert(1)&lt;/script&gt;');

        // The markup is text: no element of the output carries an event handler.
        const { container } = render(<Markdown source='<img src=x onerror="alert(1)">' />);
        const handlers = [...container.querySelectorAll('*')].flatMap((element) =>
            element.getAttributeNames().filter((name) => name.startsWith('on'))
        );

        expect(handlers).toEqual([]);
    });

    it('drops links that are not https or on this site', () => {
        const output = html(
            [
                '[a](javascript:alert(1))',
                '[b](http://example.com)',
                '[c](data:text/html;base64,PHNjcmlwdD4=)',
                '[d](//evil.example/path)',
                '[e](vbscript:msgbox)',
                '[f](JaVaScRiPt:alert(1))',
            ].join('\n\n')
        );

        expect(output).not.toContain('<a');
        expect(output).not.toContain('href=');
    });

    it('only accepts https and same-site hrefs', () => {
        expect(safeMarkdownHref('https://example.com/a?b=c')).toBe('https://example.com/a?b=c');
        expect(safeMarkdownHref('/legal/terms')).toBe('/legal/terms');
        expect(safeMarkdownHref('//evil.example')).toBeNull();
        expect(safeMarkdownHref('/\\evil.example')).toBeNull();
        expect(safeMarkdownHref('http://example.com')).toBeNull();
        expect(safeMarkdownHref('javascript:alert(1)')).toBeNull();
        expect(safeMarkdownHref('mailto:someone@example.com')).toBeNull();
    });
});
