/**
 * Invisible and direction-changing characters that can make a command or path look different
 * from what runs, as inclusive code point ranges: soft hyphen, combining grapheme joiner, Arabic
 * letter mark, Hangul fillers, Khmer inherent vowels, Mongolian free variation selectors and
 * vowel separator, zero-width spaces and joiners, bidi marks, embeddings, overrides and
 * isolates, line and paragraph separators, word joiners and invisible operators, deprecated
 * format characters, variation selectors (and their supplement), the BOM, interlinear
 * annotation characters, musical formatting characters and tag characters (used to smuggle
 * hidden text).
 */
const hiddenRanges: readonly (readonly [number, number])[] = [
    [0x00ad, 0x00ad],
    [0x034f, 0x034f],
    [0x061c, 0x061c],
    [0x115f, 0x1160],
    [0x17b4, 0x17b5],
    [0x180b, 0x180f],
    [0x200b, 0x200f],
    [0x2028, 0x2029],
    [0x202a, 0x202e],
    [0x2060, 0x2064],
    [0x2066, 0x206f],
    [0x3164, 0x3164],
    [0xfe00, 0xfe0f],
    [0xfeff, 0xfeff],
    [0xffa0, 0xffa0],
    [0xfff9, 0xfffb],
    [0x1d173, 0x1d17a],
    [0xe0000, 0xe007f],
    [0xe0100, 0xe01ef],
];

/** Text and emoji presentation selectors, which are ordinary right after an emoji. */
const emojiSelectors = new Set([0xfe0e, 0xfe0f]);

const emojiBase = /\p{Extended_Pictographic}/u;

export const isHiddenCodePoint = (codePoint: number): boolean =>
    hiddenRanges.some(([from, to]) => codePoint >= from && codePoint <= to);

export interface RevealedText {
    text: string;
    found: boolean;
}

/**
 * Text with every hidden character shown as `\u{XXXX}`, and whether there was any. A text or
 * emoji presentation selector directly after an emoji (as in ❤️) is left alone.
 */
export function revealHidden(text: string): RevealedText {
    let found = false;
    let shown = '';
    let previous = '';

    for (const character of text) {
        const codePoint = character.codePointAt(0) ?? 0;
        const emojiSelector = emojiSelectors.has(codePoint) && emojiBase.test(previous);

        if (isHiddenCodePoint(codePoint) && !emojiSelector) {
            found = true;
            shown += `\\u{${codePoint.toString(16).toUpperCase().padStart(4, '0')}}`;
        } else {
            shown += character;
        }

        previous = character;
    }

    return { text: shown, found };
}
