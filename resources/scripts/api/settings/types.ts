/** What the Discord/Google callback that redirected here reported, for this one page load. */
export interface OAuthResult {
    status: 'error' | 'success' | 'checkpoint';
    message: string;
    /** The two-factor confirmation token, for `checkpoint`. */
    token: string | null;
}

/** A Discord/Google sign-up waiting on the consent screen for the visitor to accept the terms. */
export interface OAuthRegistration {
    provider: 'discord' | 'google';
    label: string;
    email: string;
    username: string;
    /** One-time value the consent screen sends back, so it creates exactly this sign-up. */
    nonce: string;
}

export type CaptchaProvider = 'none' | 'recaptcha' | 'turnstile';

export type LegalDocumentId = 'terms' | 'privacy' | 'refunds';

/** A legal document as every page knows it: where it lives, its version and when it changed. */
export interface LegalDocumentSummary {
    title: string;
    /** The panel's /legal page, or the external page the document lives on. */
    url: string;
    external: boolean;
    version: number;
    /** ISO 8601. */
    updatedAt: string;
}

export interface SiteSettings {
    name: string;
    locale: string;
    recaptcha: {
        enabled: boolean;
        siteKey: string;
    };
    /** Discord and Google sign in: which providers are enabled, and the last callback's outcome. */
    oauth?: {
        providers: Partial<Record<'discord' | 'google', boolean>>;
        result: OAuthResult | null;
        /** Set while a provider sign-up waits for the visitor to accept the terms. */
        registration?: OAuthRegistration | null;
    };
    /** The captcha the auth forms load. Older documents only carry `recaptcha`. */
    captcha?: {
        provider: CaptchaProvider;
        siteKey: string;
    };
    /** Whether visitors may create their own account. */
    registration?: {
        enabled: boolean;
    };
    /** The Terms of Service, Privacy Policy and Refund Policy, and those the signed-in user has to accept again. */
    legal?: {
        documents: Record<LegalDocumentId, LegalDocumentSummary>;
        pending: LegalDocumentId[];
    };
}
