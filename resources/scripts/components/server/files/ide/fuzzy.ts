/**
 * Scores how well a query matches a path, as quick open lists files: every query character must
 * appear in order. Matches in the file name, at the start of a word and in a run score higher;
 * null means no match. Spaces in the query are ignored.
 */
export function fuzzyScore(query: string, path: string): number | null {
    const needle = query.replaceAll(/\s+/g, '').toLowerCase();

    if (needle === '') {
        return 0;
    }

    const haystack = path.toLowerCase();
    const nameStart = haystack.lastIndexOf('/') + 1;
    let score = 0;
    let from = 0;
    let previous = -2;

    for (const char of needle) {
        const index = haystack.indexOf(char, from);

        if (index === -1) {
            return null;
        }

        const boundary = index === 0 || '/._- '.includes(haystack[index - 1]);

        score += 1;
        score += index === previous + 1 ? 3 : 0;
        score += boundary ? 2 : 0;
        score += index >= nameStart ? 2 : 0;
        previous = index;
        from = index + 1;
    }

    // Prefer shorter paths when the matches are otherwise alike.
    return score - haystack.length / 100;
}

/** The paths matching the query, best first, at most `limit` of them. */
export function fuzzyFilter(query: string, paths: Iterable<string>, limit = 50): string[] {
    const scored: { path: string; score: number }[] = [];

    for (const path of paths) {
        const score = fuzzyScore(query, path);

        if (score !== null) {
            scored.push({ path, score });
        }
    }

    return scored
        .sort((a, b) => b.score - a.score || a.path.localeCompare(b.path))
        .slice(0, limit)
        .map(({ path }) => path);
}
