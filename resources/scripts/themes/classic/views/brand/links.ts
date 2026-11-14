/** The MonoForge community Discord invite, used by every Discord link in the classic theme. */
export const DISCORD_INVITE_URL = 'https://discord.gg/B3fd45pgvC';

/** Attributes for an external link to the Discord invite: a new tab that gives the page no opener. */
export const discordLinkProps = {
    href: DISCORD_INVITE_URL,
    target: '_blank',
    rel: 'noopener noreferrer',
} as const;
