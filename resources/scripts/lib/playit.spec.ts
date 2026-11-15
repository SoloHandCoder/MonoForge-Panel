import { describe, expect, it } from 'vitest';
import { addressPending, cleanPlayitValue } from '@/lib/playit';
import { createTestServer } from '@/views/fixtures';

const secret = '0123456789abcdef0123456789abcdef';
const agent = '5b9d1b0e-3c1f-4e8a-9a51-1f0e7d2c6b44';

describe('cleanPlayitValue', () => {
    it('takes off what people copy from playit.toml', () => {
        expect(cleanPlayitValue(secret, 'secret_key')).toBe(secret);
        expect(cleanPlayitValue(`  ${secret}\n`, 'secret_key')).toBe(secret);
        expect(cleanPlayitValue(`"${secret}"`, 'secret_key')).toBe(secret);
        expect(cleanPlayitValue(`'${secret}'`, 'secret_key')).toBe(secret);
        expect(cleanPlayitValue(`secret_key = "${secret}"`, 'secret_key')).toBe(secret);
        expect(cleanPlayitValue(`SECRET_KEY="${secret}"`, 'secret_key')).toBe(secret);
        expect(cleanPlayitValue(`agent_id = "${agent}"`, 'agent_id')).toBe(agent);
    });

    it('leaves anything else for validation to refuse', () => {
        expect(cleanPlayitValue(`agent_id = "${agent}"`, 'secret_key')).toBe(`agent_id = "${agent}"`);
        expect(cleanPlayitValue(`""${secret}""`, 'secret_key')).toBe(`"${secret}"`);
        expect(cleanPlayitValue('', 'secret_key')).toBe('');
    });
});

describe('addressPending', () => {
    it('is true only while a server on a playit.gg node has no tunnel address', () => {
        const pending = createTestServer({ addressPending: true }).attributes;

        expect(addressPending(pending)).toBe(true);
        expect(addressPending({ ...pending, playit_address: 'xyz.joinmc.link' })).toBe(false);
        expect(addressPending(createTestServer().attributes)).toBe(false);
    });
});
