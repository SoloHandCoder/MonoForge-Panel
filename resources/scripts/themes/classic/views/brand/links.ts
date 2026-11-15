/** The MonoForge community Discord invite, used by every Discord link in the classic theme. */
export const DISCORD_INVITE_URL = 'https://discord.gg/B3fd45pgvC';

/** Attributes for an external link to the Discord invite: a new tab that gives the page no opener. */
export const discordLinkProps = {
    href: DISCORD_INVITE_URL,
    target: '_blank',
    rel: 'noopener noreferrer',
} as const;

/**
 * The Terms of Service, Privacy Policy and Refund Policy. The panel serves each at these paths and
 * redirects to the document's external page when the admin set one, so the links never go stale.
 */
export const LEGAL_LINKS = [
    { id: 'terms', label: 'Terms', title: 'Terms of Service', href: '/legal/terms' },
    { id: 'privacy', label: 'Privacy', title: 'Privacy Policy', href: '/legal/privacy' },
    { id: 'refunds', label: 'Refunds', title: 'Refund Policy', href: '/legal/refunds' },
] as const;

/** Attributes for a link to a legal document: a new tab, so a form being filled in is kept. */
export const legalLinkProps = (href: string) => ({ href, target: '_blank', rel: 'noopener noreferrer' }) as const;
