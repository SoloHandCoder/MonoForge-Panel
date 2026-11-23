import { useEffect, useRef, type CSSProperties } from 'react';

/**
 * Marks a section `data-shown` once it scrolls into view, so its `.home-rise` parts enter in turn.
 * Marked at once where IntersectionObserver is missing, so nothing stays hidden.
 */
export function useReveal<T extends HTMLElement>() {
    const ref = useRef<T>(null);

    useEffect(() => {
        const element = ref.current;

        if (!element) {
            return;
        }

        if (!('IntersectionObserver' in window)) {
            element.dataset.shown = '';

            return;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    element.dataset.shown = '';
                    observer.disconnect();
                }
            },
            { rootMargin: '0px 0px -8% 0px' }
        );

        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    return ref;
}

/** The entrance order of a part inside its section. */
export const rise = (index: number) => ({ '--i': index }) as CSSProperties;
