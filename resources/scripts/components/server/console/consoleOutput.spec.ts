import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    ConsoleOutputStore,
    linkify,
    MAX_LINE_LENGTH,
    paletteColor,
    parseAnsi,
    parseConsoleLine,
    safeLink,
    stripAnsi,
} from './consoleOutput';

const ESC = '\u001b';

describe('parseAnsi', () => {
    it('splits a line into runs with the colours and styles of its SGR codes', () => {
        const segments = parseAnsi(`plain ${ESC}[1;31mbold red${ESC}[22m red ${ESC}[0mplain`);

        expect(segments.map(({ text, fg, bold }) => ({ text, fg, bold }))).toEqual([
            { text: 'plain ', fg: null, bold: false },
            { text: 'bold red', fg: 'var(--terminal-ansi-red)', bold: true },
            { text: ' red ', fg: 'var(--terminal-ansi-red)', bold: false },
            { text: 'plain', fg: null, bold: false },
        ]);
    });

    it('reads bright, 256-colour and true colour codes', () => {
        const [bright, palette, grey, rgb, colon] = parseAnsi(
            `${ESC}[92ma${ESC}[38;5;196mb${ESC}[38;5;244mc${ESC}[38;2;10;20;30;48;2;1;2;3md${ESC}[38:2::4:5:6me`
        );

        expect(bright.fg).toBe('var(--terminal-ansi-bright-green)');
        expect(palette.fg).toBe('rgb(255 0 0)');
        expect(grey.fg).toBe('rgb(128 128 128)');
        expect(rgb).toMatchObject({ fg: 'rgb(10 20 30)', bg: 'rgb(1 2 3)' });
        expect(colon.fg).toBe('rgb(4 5 6)');
    });

    it('builds colours only from numbers it checked', () => {
        expect(paletteColor(256)).toBeNull();
        expect(paletteColor(-1)).toBeNull();
        expect(paletteColor(1.5)).toBeNull();
        expect(parseAnsi(`${ESC}[38;2;999;0;0mx`)[0].fg).toBeNull();
        expect(parseAnsi(`${ESC}[38;5;red);background:url(x)mx`)[0].fg).toBeNull();
    });

    it('swaps the colours of inverse text', () => {
        expect(parseAnsi(`${ESC}[7;32mx`)[0]).toMatchObject({
            fg: 'var(--terminal-background)',
            bg: 'var(--terminal-ansi-green)',
        });
    });

    it('drops cursor movement, OSC payloads and control characters', () => {
        const raw = `${ESC}[2K${ESC}[1Gtitle${ESC}]0;window title\u0007 ${ESC}]8;;javascript:alert(1)${ESC}\\link${ESC}]8;;${ESC}\\ a\u0000\u0008b\tc${ESC}7`;

        expect(stripAnsi(raw)).toBe('title link ab\tc');
    });

    it('starts the line over at a carriage return, as progress bars do', () => {
        expect(stripAnsi('10%\r50%\r100%')).toBe('100%');
        expect(stripAnsi('done\r')).toBe('done');
    });

    it('keeps markup as text', () => {
        expect(stripAnsi('<script>alert(1)</script>')).toBe('<script>alert(1)</script>');
    });

    it('cuts runaway lines', () => {
        expect(stripAnsi('x'.repeat(MAX_LINE_LENGTH + 50))).toHaveLength(MAX_LINE_LENGTH);
    });
});

describe('links', () => {
    it('accepts plain https URLs only', () => {
        expect(safeLink('https://example.com/a?b=c')).toBe('https://example.com/a?b=c');
        expect(safeLink('http://example.com')).toBeNull();
        expect(safeLink('javascript:alert(1)')).toBeNull();
        expect(safeLink('data:text/html,<script>')).toBeNull();
        expect(safeLink('https://bank.example@evil.example')).toBeNull();
        expect(safeLink(`https://example.com/${'a'.repeat(3000)}`)).toBeNull();
    });

    it('marks https URLs in runs and leaves the rest as text', () => {
        const segments = linkify(
            parseAnsi('docs at https://example.com/x). or http://plain.example or javascript:alert(1)')
        );

        expect(segments.filter((segment) => segment.href).map((segment) => [segment.text, segment.href])).toEqual([
            ['https://example.com/x', 'https://example.com/x'],
        ]);
        expect(segments.map((segment) => segment.text).join('')).toBe(
            'docs at https://example.com/x). or http://plain.example or javascript:alert(1)'
        );
    });

    it('never links a javascript URL hidden after https', () => {
        const segments = linkify(parseAnsi('https://"onmouseover="alert(1) https://x.example/"><img>'));

        expect(segments.filter((segment) => segment.href).map((segment) => segment.href)).toEqual([
            'https://x.example/',
        ]);
    });
});

describe('parseConsoleLine', () => {
    it('finds the log level in the header and marks its word', () => {
        const line = parseConsoleLine(`[12:00:01 ${ESC}[33mWARN${ESC}[0m]: Can't keep up!`, 7);

        expect(line).toMatchObject({ id: 7, level: 'warn', text: "[12:00:01 WARN]: Can't keep up!" });
        expect(line.segments.find((segment) => segment.level)).toMatchObject({ text: 'WARN', level: 'warn' });
    });

    it('reads Java thread headers and leaves lines without a level alone', () => {
        expect(parseConsoleLine('[Server thread/ERROR]: Boom', 1).level).toBe('error');
        expect(parseConsoleLine('[12:00:00 INFO]: Hello', 1).level).toBe('info');
        expect(parseConsoleLine('a player said warn me', 1).level).toBeNull();
        expect(parseConsoleLine(`${'x'.repeat(80)} ERROR late in the line`, 1).level).toBeNull();
    });
});

describe('ConsoleOutputStore', () => {
    afterEach(() => vi.useRealTimers());

    it('tells readers once per burst and every change listener at once', () => {
        vi.useFakeTimers();
        const store = new ConsoleOutputStore();
        const reader = vi.fn();
        const changes = vi.fn();

        store.subscribe(reader);
        store.onChange(changes);
        store.writeln('one');
        store.writeln('two');

        expect(changes).toHaveBeenCalledTimes(2);
        expect(reader).not.toHaveBeenCalled();
        vi.advanceTimersByTime(50);
        expect(reader).toHaveBeenCalledTimes(1);
        expect(store.getSnapshot().map((line) => line.text)).toEqual(['one', 'two']);

        store.clear();
        store.flush();
        expect(store.getSnapshot()).toEqual([]);
        expect(changes).toHaveBeenLastCalledWith({ kind: 'clear' });
    });

    it('keeps the newest lines up to its limit', () => {
        const store = new ConsoleOutputStore(3);

        for (let index = 1; index <= 110; index++) {
            store.writeln(`${ESC}[32mline ${index}`);
        }

        store.flush();
        expect(store.getSnapshot().map((line) => line.text)).toEqual(['line 108', 'line 109', 'line 110']);
        expect(store.text()).toBe('line 108\nline 109\nline 110');
        expect(store.rawLines()).toEqual([`${ESC}[32mline 108`, `${ESC}[32mline 109`, `${ESC}[32mline 110`]);
    });
});
