/** @vitest-environment jsdom */

import * as monaco from 'monaco-editor/editor/editor.api.js';
import { beforeAll, describe, expect, it } from 'vitest';
import { HYPERSCRIPT_ID, registerHyperScript } from './hyperscript';

/** The token types of one line, as `text=type` pairs without the language suffix. */
function tokens(line: string): string[] {
    const [lineTokens = []] = monaco.editor.tokenize(line, HYPERSCRIPT_ID);

    return lineTokens.map((token, index) => {
        const end = lineTokens[index + 1]?.offset ?? line.length;

        return `${line.slice(token.offset, end)}=${token.type.replace(`.${HYPERSCRIPT_ID}`, '')}`;
    });
}

beforeAll(() => {
    registerHyperScript(monaco);
});

describe('HyperScript highlighting', () => {
    it('reads comments, but not a # inside text', () => {
        expect(tokens('# Requires: 1.5.9')).toEqual(['# Requires: 1.5.9=comment']);
        expect(tokens('send "#ff8800"')).not.toContain('#ff8800"=comment');
        expect(tokens('send "#ff8800"').some((token) => token.endsWith('=comment'))).toBe(false);
    });

    it('colours events, commands and their argument types', () => {
        expect(tokens('on player join:')).toEqual(expect.arrayContaining(['on=keyword']));
        expect(tokens('command /hello <target:player>:')).toEqual(
            expect.arrayContaining(['command=keyword', '/hello=tag', '<target:player>=type'])
        );
    });

    it('tells global, local and option variables apart, nested ones included', () => {
        const line = 'set {%player%.coins} to {_amount} + {@bonus}';
        const types = tokens(line);

        expect(types).toEqual(expect.arrayContaining(['set=keyword', 'to=keyword.operator', '+=delimiter']));
        expect(types.join(' ')).toContain('{_=variable.local');
        expect(types.join(' ')).toContain('{@=variable.option');
        // The nested %player% stays inside the variable: nothing after it reads as text or a comment.
        expect(types.filter((token) => token.startsWith('}')).every((token) => token.endsWith('=variable'))).toBe(true);
    });

    it('highlights placeholders, variables and colour codes inside text', () => {
        const types = tokens('send "&aHello %player.name%! You have %{%player%.coins}% coins"');

        expect(types).toEqual(
            expect.arrayContaining(['&a=type', '%player.name%=variable', '%{%player%.coins}%=variable'])
        );
        expect(types.some((token) => token.startsWith(' coins') && token.endsWith('=string'))).toBe(true);
    });

    it('reads numbers, durations, chances and constants', () => {
        expect(tokens('wait 5 seconds')).toEqual(['wait=keyword', ' =', '5=number', ' =', 'seconds=number']);
        expect(tokens('chance of 25%:')).toEqual(expect.arrayContaining(['chance=keyword', '25%=number']));
        expect(tokens('if {_ok} is true:')).toEqual(
            expect.arrayContaining(['if=keyword', 'is=keyword.operator', 'true=number'])
        );
    });

    it('marks function calls', () => {
        expect(tokens('set {_x} to round({_n})')).toEqual(expect.arrayContaining(['round=type.function']));
    });
});
