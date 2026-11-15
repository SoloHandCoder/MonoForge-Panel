import { describe, expect, it } from 'vitest';
import { modelPath } from './modelUri';

describe('modelPath', () => {
    it.each([
        ['/.env', 'file:///.env'],
        ['/server.properties', 'file:///server.properties'],
        ['plugins/Essentials/config.yml', 'file:///plugins/Essentials/config.yml'],
        ['/folder with space/ä #1%.json', 'file:///folder%20with%20space/%C3%A4%20%231%25.json'],
        ['/a?b.txt', 'file:///a%3Fb.txt'],
    ])('builds a valid file URI for %s', (path, expected) => {
        expect(modelPath(path)).toBe(expected);
    });

    it('never produces an empty authority with a "//" path (which Monaco rejects)', () => {
        for (const path of ['/.env', '//double/slash.txt', '/']) {
            expect(modelPath(path)).not.toMatch(/^file:\/\/\/\//);
            expect(modelPath(path)).not.toContain('%2F');
        }
    });

    it('gives the same model to a path with and without its leading slash', () => {
        expect(modelPath('/.env')).toBe(modelPath('.env'));
    });
});
