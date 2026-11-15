/**
 * Invisible and direction-changing characters that can make a command or path look different
 * from what runs, as inclusive code point ranges: soft hyphen, combining grapheme joiner, Arabic
 * letter mark, Hangul fillers, the Mongolian vowel separator, zero-width spaces and joiners,
 * bidi marks, embeddings, overrides and isolates, word joiners and invisible operators,
 * variation selectors, the BOM and tag characters (used to smuggle hidden text).
 */
const hiddenRanges: readonly (readonly [number, number])[] = [
    [0x00ad, 0x00ad],
    [0x034f, 0x034f],
    [0x061c, 0x061c],
    [0x115f, 0x1160],
    [0x180e, 0x180e],
    [0x200b, 0x200f],
    [0x202a, 0x202e],
    [0x2060, 0x2064],
    [0x2066, 0x2069],
    [0x3164, 0x3164],
    [0xfe00, 0xfe0f],
    [0xfeff, 0xfeff],
    [0xffa0, 0xffa0],
    [0xe0000, 0xe007f],
];

export const isHiddenCodePoint = (codePoint: number): boolean =>
    hiddenRanges.some(([from, to]) => codePoint >= from && codePoint <= to);

export interface RevealedText {
    text: string;
    found: boolean;
}

/** Text with every hidden character shown as `\u{XXXX}`, and whether there was any. */
export function revealHidden(text: string): RevealedText {
    let found = false;
    let shown = '';

    for (const character of text) {
        const codePoint = character.codePointAt(0) ?? 0;

        if (isHiddenCodePoint(codePoint)) {
            found = true;
            shown += `\\u{${codePoint.toString(16).toUpperCase().padStart(4, '0')}}`;
        } else {
            shown += character;
        }
    }

    return { text: shown, found };
}
