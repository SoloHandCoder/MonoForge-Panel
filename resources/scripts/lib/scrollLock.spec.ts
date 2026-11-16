/** @vitest-environment jsdom */

import { afterEach, describe, expect, it } from 'vitest';
import { lockPageScroll, pageScrollLockHolders, releaseStaleScrollLock, SCROLL_LOCK_ATTRIBUTE } from './scrollLock';

const locked = () => document.documentElement.hasAttribute(SCROLL_LOCK_ATTRIBUTE);

afterEach(() => {
    document.documentElement.removeAttribute(SCROLL_LOCK_ATTRIBUTE);
    document.documentElement.removeAttribute('style');
    document.body.removeAttribute('style');
    document.body.innerHTML = '';
});

describe('lockPageScroll', () => {
    it('unlocks only when the last of overlapping locks is released, in any order', () => {
        const first = lockPageScroll();
        const second = lockPageScroll();

        first();
        expect(locked()).toBe(true);

        second();
        expect(locked()).toBe(false);
        expect(pageScrollLockHolders()).toBe(0);
    });

    it('ignores a release called twice', () => {
        const first = lockPageScroll();
        const second = lockPageScroll();

        first();
        first();
        expect(locked()).toBe(true);

        second();
        expect(locked()).toBe(false);
    });

    it('never touches the inline body styles another lock saves and restores', () => {
        document.body.style.overflow = 'auto';
        const release = lockPageScroll();

        expect(document.body.style.overflow).toBe('auto');
        release();
        expect(document.body.style.overflow).toBe('auto');
    });
});

describe('releaseStaleScrollLock', () => {
    it('drops an overflow hidden left behind when nothing is open', () => {
        document.body.style.overflow = 'hidden';
        document.documentElement.style.overflowY = 'hidden';

        releaseStaleScrollLock();

        expect(document.body.style.overflow).toBe('');
        expect(document.documentElement.style.overflowY).toBe('');
    });

    it('keeps the lock while a lock is held or a dialog is open', () => {
        const release = lockPageScroll();

        document.body.style.overflow = 'hidden';
        releaseStaleScrollLock();
        expect(locked()).toBe(true);
        expect(document.body.style.overflow).toBe('hidden');
        release();

        document.body.innerHTML = '<div role="dialog"></div>';
        releaseStaleScrollLock();
        expect(document.body.style.overflow).toBe('hidden');
    });
});
