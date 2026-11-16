import { useEffect } from 'react';

/**
 * One page scroll lock for the panel's full-screen overlays (purchase screens, the editor).
 *
 * They used to save `document.body.style.overflow`, set it to `hidden` and put the saved value back
 * on unmount. When two locks overlapped (an overlay and a Base UI dialog, or a page that mounted
 * before the previous one unmounted), one of them saved the other's `hidden` and restored it last,
 * so the page could no longer scroll until a reload. This lock is counted and lives in an
 * attribute on `<html>` (styled in tailwind.css), never in the inline styles Base UI saves and
 * restores for its own lock.
 */
export const SCROLL_LOCK_ATTRIBUTE = 'data-scroll-lock';

let holders = 0;

/** Locks the page scroll until the returned function is called (calling it twice is harmless). */
export function lockPageScroll(): () => void {
    holders += 1;
    document.documentElement.setAttribute(SCROLL_LOCK_ATTRIBUTE, '');

    let released = false;

    return () => {
        if (released) {
            return;
        }

        released = true;
        holders = Math.max(0, holders - 1);
        if (holders === 0) {
            document.documentElement.removeAttribute(SCROLL_LOCK_ATTRIBUTE);
        }
    };
}

/** Locks the page scroll while the calling component is mounted. */
export function usePageScrollLock(): void {
    useEffect(() => lockPageScroll(), []);
}

/** How many locks are held; for tests. */
export function pageScrollLockHolders(): number {
    return holders;
}

const OPEN_LAYER_SELECTOR = '[role="dialog"], [role="alertdialog"], [role="menu"], [role="listbox"]';

/**
 * After a navigation: when nothing holds a lock and no dialog or menu is open, drops an
 * `overflow: hidden` some lock left behind on `<html>` or `<body>`, so a stuck page scrolls again
 * without a reload.
 */
export function releaseStaleScrollLock(): void {
    if (holders > 0 || document.querySelector(OPEN_LAYER_SELECTOR) !== null) {
        return;
    }

    document.documentElement.removeAttribute(SCROLL_LOCK_ATTRIBUTE);
    for (const element of [document.documentElement, document.body]) {
        for (const property of ['overflow', 'overflow-x', 'overflow-y'] as const) {
            if (element.style.getPropertyValue(property) === 'hidden') {
                element.style.removeProperty(property);
            }
        }
    }
}
