import React, { useCallback, useEffect, useImperativeHandle, useRef } from 'react';
import type { InvisibleRecaptchaHandle } from './InvisibleRecaptcha';

/** The options of `turnstile.render` this widget passes. */
interface TurnstileRenderOptions {
    sitekey: string;
    action?: string;
    execution: 'render' | 'execute';
    appearance: 'always' | 'execute' | 'interaction-only';
    callback: (token: string) => void;
    'error-callback': () => void;
    'timeout-callback': () => void;
}

/** The part of Cloudflare's `window.turnstile` this widget uses. */
interface TurnstileApi {
    render: (container: HTMLElement, options: TurnstileRenderOptions) => string | undefined;
    execute: (widgetId: string) => void;
    reset: (widgetId: string) => void;
    remove: (widgetId: string) => void;
}

type TurnstileWindow = Window & { turnstile?: TurnstileApi };

const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

// Upper bound for a single execution, so a stuck challenge cannot block the form forever.
const EXECUTE_TIMEOUT_MS = 120_000;

let loading: Promise<TurnstileApi> | null = null;

/** Loads Cloudflare's script once per page; a failed load can be retried by the next widget. */
export function loadTurnstile(): Promise<TurnstileApi> {
    const ready = (window as TurnstileWindow).turnstile;

    if (ready) {
        return Promise.resolve(ready);
    }

    loading ??= new Promise<TurnstileApi>((resolve, reject) => {
        const script = document.createElement('script');

        script.src = SCRIPT_URL;
        script.async = true;
        script.onload = () => {
            const api = (window as TurnstileWindow).turnstile;

            if (api) {
                resolve(api);
            } else {
                reject(new Error('Turnstile did not load.'));
            }
        };

        script.onerror = () => reject(new Error('Turnstile did not load.'));
        document.head.appendChild(script);
    }).catch(() => {
        loading = null;

        throw new Error('Turnstile did not load.');
    });

    return loading;
}

interface Props {
    siteKey: string;
    /** Which form this is ("login", "register"...); the panel checks it when verifying the token. */
    action?: string;
    ref?: React.Ref<InvisibleRecaptchaHandle>;
}

/**
 * Cloudflare Turnstile; call `ref.execute()` to obtain a token. The widget only shows itself
 * when Cloudflare wants the visitor to interact with it.
 */
const Turnstile = ({ siteKey, action, ref }: Props) => {
    const containerRef = useRef<HTMLDivElement>(null);
    const widgetRef = useRef<{ api: TurnstileApi; id: string } | null>(null);
    const pendingRef = useRef<((token: string | null) => void) | null>(null);

    const settle = useCallback((token: string | null) => {
        const resolve = pendingRef.current;

        pendingRef.current = null;
        resolve?.(token);
    }, []);

    useEffect(() => {
        let cancelled = false;

        loadTurnstile()
            .then((api) => {
                const container = containerRef.current;

                if (cancelled || !container) {
                    return;
                }

                const id = api.render(container, {
                    sitekey: siteKey || '_invalid_key',
                    action,
                    execution: 'execute',
                    appearance: 'interaction-only',
                    callback: (token: string) => settle(token || null),
                    'error-callback': () => settle(null),
                    'timeout-callback': () => settle(null),
                });

                if (id !== undefined) {
                    widgetRef.current = { api, id };
                }
            })
            .catch(() => {
                // Without the script every execution resolves with no token.
            });

        return () => {
            cancelled = true;
            settle(null);

            const widget = widgetRef.current;

            widgetRef.current = null;

            try {
                widget?.api.remove(widget.id);
            } catch {
                // The widget has already been removed.
            }
        };
    }, [siteKey, action, settle]);

    useImperativeHandle(
        ref,
        () => ({
            execute: () =>
                new Promise<string | null>((resolve) => {
                    settle(null);

                    const widget = widgetRef.current;

                    if (!widget) {
                        resolve(null);

                        return;
                    }

                    const timeout = window.setTimeout(() => settle(null), EXECUTE_TIMEOUT_MS);

                    pendingRef.current = (token) => {
                        window.clearTimeout(timeout);
                        resolve(token);
                    };

                    try {
                        // Tokens are single use, so every submit asks for a fresh one.
                        widget.api.reset(widget.id);
                        widget.api.execute(widget.id);
                    } catch {
                        settle(null);
                    }
                }),
        }),
        [settle]
    );

    return <div ref={containerRef} className='mt-4 flex justify-center' />;
};

Turnstile.displayName = 'Turnstile';

export default Turnstile;
