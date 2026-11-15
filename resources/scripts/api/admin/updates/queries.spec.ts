import { describe, expect, it } from 'vitest';
import { updateCheckToast, type AdminUpdateStatus } from './queries';

const build = { version: '3f9c2ab', commit: '3f9c2ab1d4e5f60718293a4b5c6d7e8f90a1b2c3', built_at: null };
const latest = { ...build, version: '9e8d7c6', tag: 'build-9e8d7c6', url: 'https://github.com/x' };

const status = (overrides: Partial<AdminUpdateStatus>): AdminUpdateStatus => ({
    mode: 'notify',
    current_version: '3f9c2ab',
    current: build,
    latest,
    check: 'available',
    update_available: true,
    status: { state: 'idle', step: null, message: null, from: null, to: null, error: null, updated_at: null },
    ...overrides,
});

describe('updateCheckToast', () => {
    it('names the available build', () => {
        expect(updateCheckToast(status({}))).toEqual({ type: 'info', title: 'Update available: 9e8d7c6' });
    });

    it('reports an up to date panel', () => {
        expect(updateCheckToast(status({ check: 'current', update_available: false }))).toEqual({
            type: 'success',
            title: 'Up to date',
        });
    });

    it('reports a failed lookup as an error', () => {
        expect(updateCheckToast(status({ check: 'unknown', update_available: false, latest: null })).type).toBe(
            'error'
        );
    });

    it('reports that checks are turned off', () => {
        expect(updateCheckToast(status({ mode: 'off', check: 'off', update_available: false, latest: null }))).toEqual({
            type: 'info',
            title: 'Update checks are turned off',
        });
    });
});
