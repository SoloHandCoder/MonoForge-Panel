/**
 * The two addresses a new server gets from its name, worked out the way the panel does it:
 * the public page `/s/<slug>` (ServerPageSlug) and the Minecraft join address
 * `<name>.play.monoforge.net` (EntranceHostnames::label, which starts from the page's slug).
 * Whether the name is still free is only known once the server exists.
 */

/** The shared Minecraft entrance every routed server is joined through. */
export const ENTRANCE_DOMAIN = 'play.monoforge.net';

export const NAME_MIN = 3;
export const NAME_MAX = 32;

/** ServerPageSlug::RESERVED: words a public page address may not be. */
const PAGE_RESERVED = new Set([
    'about', 'account', 'admin', 'administrator', 'api', 'app', 'assets', 'auth', 'banner', 'billing',
    'blog', 'contact', 'dashboard', 'docs', 'download', 'edit', 'extensions', 'faq', 'help', 'home',
    'index', 'legal', 'login', 'logout', 'mod', 'moderator', 'monoforge', 'new', 'null', 'official',
    'owner', 'panel', 'password', 'payment', 'privacy', 'pterodactyl', 'register', 'root', 'security',
    'server', 'servers', 'settings', 'shop', 'signin', 'signup', 'staff', 'status', 'store', 'support',
    'system', 'team', 'terms', 'undefined', 'upload', 'user', 'users', 'vote', 'wake', 'www',
]); // prettier-ignore

/** ServerPageSlug::RESERVED_PARTS: words a page address may not even contain. */
const PAGE_RESERVED_PARTS = [
    'monoforge',
    'admin',
    'support',
    'staff',
    'official',
    'login',
    'panel',
    'billing',
    'status',
    'store',
];

/** EntranceHostnames::RESERVED: names the join domain keeps for itself. */
const ENTRANCE_RESERVED = new Set([
    'www', 'play', 'admin', 'administrator', 'api', 'mail', 'email', 'smtp', 'imap', 'pop', 'pop3', 'mx',
    'ns', 'ns1', 'ns2', 'dns', 'ftp', 'sftp', 'ssh', 'panel', 'node', 'wings', 'status', 'help', 'support',
    'docs', 'cdn', 'static', 'assets', 'webmail', 'login', 'auth', 'root', 'staff', 'mod', 'moderator',
    'owner', 'official', 'monoforge', 'gateway', 'proxy', 'localhost', 'test', 'dev', 'staging',
]); // prettier-ignore

/** Laravel's Str::slug for the letters a server name uses: accents dropped, anything else a hyphen. */
export function slugify(text: string): string {
    return text
        .normalize('NFKD')
        .replaceAll(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replaceAll(/_+/g, '-')
        .replaceAll('@', '-at-')
        .replaceAll(/[^a-z0-9\s-]+/g, '')
        .replaceAll(/[\s-]+/g, '-')
        .replaceAll(/^-+|-+$/g, '');
}

export type ServerAddress = { ok: true; slug: string; host: string } | { ok: false; slug: string; problem: string };

/** The page slug and the join host for a server name, or why that name cannot have them. */
export function serverAddress(name: string): ServerAddress {
    const slug = slugify(name).slice(0, NAME_MAX).replace(/-+$/, '');

    if (slug.length < NAME_MIN) {
        return { ok: false, slug, problem: `Use at least ${NAME_MIN} letters or digits.` };
    }

    if (
        PAGE_RESERVED.has(slug) ||
        ENTRANCE_RESERVED.has(slug) ||
        PAGE_RESERVED_PARTS.some((part) => slug.includes(part))
    ) {
        return { ok: false, slug, problem: 'That name is kept for the panel itself. Try another one.' };
    }

    return { ok: true, slug, host: `${slug}.${ENTRANCE_DOMAIN}` };
}
