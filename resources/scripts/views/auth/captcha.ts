import { createElement, useCallback, useMemo, useRef, type ComponentType, type RefObject } from 'react';
import { toast } from 'sonner';
import { useSiteSettings } from '@/api/settings/queries';
import type { CaptchaProvider, SiteSettings } from '@/api/settings/types';
import InvisibleRecaptcha, { type InvisibleRecaptchaHandle } from '@/components/elements/InvisibleRecaptcha';
import Turnstile from '@/components/elements/Turnstile';

function captchaLeaf(
    ref: RefObject<InvisibleRecaptchaHandle | null>,
    provider: Exclude<CaptchaProvider, 'none'>,
    siteKey: string,
    action: CaptchaAction
): ComponentType {
    if (provider === 'turnstile') {
        return function Captcha() {
            return createElement(Turnstile, { ref, siteKey, action });
        };
    }

    return function Captcha() {
        return createElement(InvisibleRecaptcha, { ref, siteKey });
    };
}

/** The form a captcha token is for; the panel rejects Turnstile tokens solved on another form. */
export type CaptchaAction = 'login' | 'register' | 'password';

/** The panel's captcha: the provider setting, or the legacy reCAPTCHA switch on older documents. */
export function captchaSettings(settings: SiteSettings): { provider: CaptchaProvider; siteKey: string } {
    if (settings.captcha) {
        return settings.captcha;
    }

    return settings.recaptcha.enabled
        ? { provider: 'recaptcha', siteKey: settings.recaptcha.siteKey }
        : { provider: 'none', siteKey: '' };
}

/** The captcha (reCAPTCHA or Cloudflare Turnstile) of an auth form, when the panel enables one. */
interface AuthCaptcha {
    readonly Captcha: ComponentType | null;
    /** The value to send, or null once the user has been told the verification failed. */
    readonly token: () => Promise<string | null>;
}

export function useCaptcha(action: CaptchaAction): AuthCaptcha {
    const ref = useRef<InvisibleRecaptchaHandle>(null);
    const { provider, siteKey } = captchaSettings(useSiteSettings());
    const enabled = provider !== 'none';
    const Captcha = useMemo(
        () => (provider === 'none' ? null : captchaLeaf(ref, provider, siteKey || '', action)),
        [provider, siteKey, action]
    );
    const token = useCallback(async () => {
        if (!enabled) {
            return '';
        }

        const value = (await ref.current?.execute()) ?? '';

        if (!value) {
            toast.error('Captcha verification failed, please try again.');

            return null;
        }

        return value;
    }, [enabled]);

    return { Captcha, token };
}
