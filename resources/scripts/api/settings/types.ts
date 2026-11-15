/** What the Discord/Google callback that redirected here reported, for this one page load. */
export interface OAuthResult {
    status: 'error' | 'success' | 'checkpoint';
    message: string;
    /** The two-factor confirmation token, for `checkpoint`. */
    token: string | null;
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
    };
}
