import { describe, expect, it } from 'vitest';
import { ENTRANCE_DOMAIN, serverAddress, slugify } from './serverAddress';

describe('server address from a name', () => {
    it('slugs a name the way Laravel does', () => {
        expect(slugify('Friday Survival')).toBe('friday-survival');
        expect(slugify('  Café   Über__Survival  ')).toBe('cafe-uber-survival');
        expect(slugify("Mira's Build Club!")).toBe('miras-build-club');
        expect(slugify('me@home')).toBe('me-at-home');
        expect(slugify('--a -- b--')).toBe('a-b');
    });

    it('gives the page slug and the join host for a free-looking name', () => {
        expect(serverAddress('Friday Survival')).toEqual({
            ok: true,
            slug: 'friday-survival',
            host: `friday-survival.${ENTRANCE_DOMAIN}`,
        });
        expect(ENTRANCE_DOMAIN).toBe('play.monoforge.net');
    });

    it('keeps to 32 characters without a trailing hyphen', () => {
        // The 32nd character is the hyphen before "yz", so it is cut along with the rest.
        const address = serverAddress('The very long name of a serverx yz');

        expect(address.slug).toBe('the-very-long-name-of-a-serverx');
        expect(address.slug.length).toBeLessThanOrEqual(32);
        expect(address.ok).toBe(true);
    });

    it('refuses short names and the words the panel keeps', () => {
        expect(serverAddress('ab')).toMatchObject({ ok: false, problem: 'Use at least 3 letters or digits.' });
        expect(serverAddress('!!!')).toMatchObject({ ok: false });
        // A page word, a join-domain word, and a word a page address may not even contain.
        for (const name of ['Status', 'Gateway', 'Official Survival', 'My Store']) {
            expect(serverAddress(name)).toMatchObject({ ok: false });
        }
    });
});
