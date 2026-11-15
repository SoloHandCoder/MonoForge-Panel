import { describe, expect, it } from 'vitest';
import { fuzzyFilter, fuzzyScore } from './fuzzy';

describe('fuzzyScore', () => {
    it('needs every character of the query in order', () => {
        expect(fuzzyScore('srvprop', '/server.properties')).not.toBeNull();
        expect(fuzzyScore('porp', '/server.properties')).toBeNull();
        expect(fuzzyScore('xyz', '/server.properties')).toBeNull();
        expect(fuzzyScore('ps', '/server.properties')).not.toBeNull();
        expect(fuzzyScore('zs', '/server.properties')).toBeNull();
    });

    it('ignores case and spaces, and matches everything for an empty query', () => {
        expect(fuzzyScore('SERVER prop', '/server.properties')).not.toBeNull();
        expect(fuzzyScore('  ', '/a')).toBe(0);
    });
});

describe('fuzzyFilter', () => {
    const paths = ['/plugins/Essentials/config.yml', '/config/paper-global.yml', '/server.properties', '/bukkit.yml'];

    it('ranks matches in the file name and runs of characters first', () => {
        expect(fuzzyFilter('config', paths)).toEqual(['/plugins/Essentials/config.yml', '/config/paper-global.yml']);
        expect(fuzzyFilter('yml', paths)[0]).toBe('/bukkit.yml');
    });

    it('caps the results', () => {
        expect(fuzzyFilter('', paths, 2)).toHaveLength(2);
    });
});
