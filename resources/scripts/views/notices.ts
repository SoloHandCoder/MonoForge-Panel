import { useCallback, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
    noticeFeedQueryOptions,
    publicNoticeFeedQueryOptions,
    useMarkNoticeRead,
    type FeedAnnouncement,
    type FeedNotice,
    type NoticeFeed,
} from '@/api/notices/queries';
import type { PanelNotice, PanelNoticeLevel } from './layouts';

const LEVELS: ReadonlySet<string> = new Set(['info', 'success', 'warning', 'danger']);
const DISMISSED_KEY = 'monoforge:dismissed-announcements';

const level = (value: string): PanelNoticeLevel => (LEVELS.has(value) ? (value as PanelNoticeLevel) : 'info');

/** Browsers drop whitespace and control characters and read `\` as `/`, so `/\evil.example` would leave the panel. */
const hasUnsafeCharacter = (url: string): boolean =>
    [...url].some((character) => {
        const code = character.codePointAt(0) ?? 0;

        return code <= 0x20 || code === 0x7f || character === '\\';
    });

const parseUrl = (url: string, base?: string): URL | null => {
    try {
        return new URL(url, base);
    } catch {
        return null;
    }
};

/**
 * Only paths within the panel or http(s) pages; a `javascript:` url never becomes a link. The server
 * applies the same rules when it stores a notice (`ActionUrl::sanitize`).
 */
export const safeActionUrl = (
    url: string | null | undefined,
    origin: string = globalThis.location?.origin ?? ''
): string | null => {
    if (!url || hasUnsafeCharacter(url)) {
        return null;
    }

    if (url.startsWith('/')) {
        return !url.startsWith('//') && parseUrl(url, origin)?.origin === origin ? url : null;
    }

    const parsed = parseUrl(url);

    return parsed && (parsed.protocol === 'http:' || parsed.protocol === 'https:') ? url : null;
};

/** A dismissal is remembered per update, so an edited announcement shows again. */
const dismissalKey = (announcement: FeedAnnouncement) => `${announcement.id}:${announcement.updated_at}`;

function readDismissed(): string[] {
    try {
        // Space separated `id:updated_at` keys.
        return (window.localStorage.getItem(DISMISSED_KEY) ?? '').split(' ').filter(Boolean);
    } catch {
        return [];
    }
}

function writeDismissed(keys: readonly string[]): void {
    try {
        window.localStorage.setItem(DISMISSED_KEY, keys.join(' '));
    } catch {
        // Storage can be unavailable (private windows); the dismissal then lasts until reload.
    }
}

const fromAnnouncement = (announcement: FeedAnnouncement): PanelNotice => ({
    id: `announcement:${announcement.id}`,
    level: level(announcement.level),
    title: announcement.title,
    body: announcement.body,
    dismissible: announcement.dismissible,
    action: null,
});

const fromNotice = (notice: FeedNotice): PanelNotice => {
    const url = safeActionUrl(notice.action_url);

    return {
        id: `notice:${notice.id}`,
        level: level(notice.level),
        title: notice.title,
        body: notice.body,
        dismissible: true,
        action: url ? { label: notice.action_label || 'Open', url } : null,
    };
};

/** The feed as the shell draws it: announcements first, without those this browser dismissed. */
export function panelNotices(feed: NoticeFeed | undefined, dismissed: readonly string[]): PanelNotice[] {
    if (!feed) {
        return [];
    }

    return [
        ...feed.announcements
            .filter((announcement) => !announcement.dismissible || !dismissed.includes(dismissalKey(announcement)))
            .map(fromAnnouncement),
        ...feed.notices.map(fromNotice),
    ];
}

export interface PanelNoticesModel {
    readonly notices: readonly PanelNotice[];
    /** Hides a notice: an announcement in this browser, a user's notice by marking it read. */
    readonly dismissNotice: (id: string) => void;
}

function useNoticeModel(feed: NoticeFeed | undefined, markRead: ((id: number) => void) | null): PanelNoticesModel {
    const [dismissed, setDismissed] = useState(readDismissed);
    // Notices marked read leave at once, before the feed refetches without them.
    const [read, setRead] = useState<readonly string[]>([]);
    const notices = useMemo(
        () => panelNotices(feed, dismissed).filter((notice) => !read.includes(notice.id)),
        [feed, dismissed, read]
    );

    const dismissNotice = useCallback(
        (id: string) => {
            const [kind, raw] = id.split(':');
            const key = Number(raw);

            if (kind === 'notice') {
                setRead((ids) => [...ids, id]);
                markRead?.(key);

                return;
            }

            const announcement = feed?.announcements.find((entry) => entry.id === key);

            if (announcement?.dismissible) {
                // Keep the keys of announcements still in the feed, so storage does not grow forever.
                const live = new Set(feed?.announcements.map(dismissalKey));
                const next = [...dismissed.filter((entry) => live.has(entry)), dismissalKey(announcement)];

                writeDismissed(next);
                setDismissed(next);
            }
        },
        [feed, dismissed, markRead]
    );

    return useMemo(() => ({ notices, dismissNotice }), [notices, dismissNotice]);
}

/** The notices of a signed-in page: announcements and the user's own notices, checked every minute. */
export function useAppNotices(): PanelNoticesModel {
    const { data } = useQuery(noticeFeedQueryOptions());
    const { mutate } = useMarkNoticeRead();
    const markRead = useCallback((id: number) => mutate({ path: { notice: String(id) } }), [mutate]);

    return useNoticeModel(data?.attributes, markRead);
}

/** The notices of the sign-in pages: the active announcements. */
export function useAuthNotices(): PanelNoticesModel {
    const { data } = useQuery(publicNoticeFeedQueryOptions());

    return useNoticeModel(data?.attributes, null);
}
