import { describe, expect, it } from 'vitest';
import type { NoticeFeed } from '@/api/notices/queries';
import { panelNotices, safeActionUrl } from './notices';

const feed: NoticeFeed = {
    announcements: [
        {
            id: 1,
            title: 'Maintenance',
            body: 'Down at six.',
            level: 'warning',
            dismissible: true,
            starts_at: '2026-11-15T11:00:00+00:00',
            ends_at: '2026-11-15T19:00:00+00:00',
            updated_at: '2026-11-15T12:00:00+00:00',
        },
        {
            id: 2,
            title: 'Outage',
            body: 'Node one is down.',
            level: 'danger',
            dismissible: false,
            starts_at: '2026-11-15T11:00:00+00:00',
            ends_at: '2026-11-15T19:00:00+00:00',
            updated_at: '2026-11-15T12:00:00+00:00',
        },
    ],
    notices: [
        {
            id: 7,
            level: 'loud',
            title: 'Low balance',
            body: 'Two days left.',
            action_label: 'Top up',
            action_url: 'javascript:alert(1)',
            kind: 'billing.low_balance',
            created_at: '2026-11-15T12:00:00+00:00',
        },
    ],
};

describe('panelNotices', () => {
    it('lists announcements before the user notices', () => {
        expect(panelNotices(feed, []).map((notice) => notice.id)).toEqual([
            'announcement:1',
            'announcement:2',
            'notice:7',
        ]);
    });

    it('hides dismissed announcements until they are edited, but never undismissible ones', () => {
        const dismissed = ['1:2026-11-15T12:00:00+00:00', '2:2026-11-15T12:00:00+00:00'];

        expect(panelNotices(feed, dismissed).map((notice) => notice.id)).toEqual(['announcement:2', 'notice:7']);
        expect(panelNotices(feed, ['1:2026-11-14T12:00:00+00:00']).map((notice) => notice.id)).toContain(
            'announcement:1'
        );
    });

    it('falls back to info for an unknown level and drops unsafe action links', () => {
        const notice = panelNotices(feed, []).at(-1);

        expect(notice?.level).toBe('info');
        expect(notice?.action).toBeNull();
        expect(notice?.dismissible).toBe(true);
    });
});

describe('safeActionUrl', () => {
    it('keeps panel paths and http links only', () => {
        expect(safeActionUrl('/account')).toBe('/account');
        expect(safeActionUrl('https://monoforge.net/billing')).toBe('https://monoforge.net/billing');
        expect(safeActionUrl('//evil.example')).toBeNull();
        expect(safeActionUrl('javascript:alert(1)')).toBeNull();
        expect(safeActionUrl(null)).toBeNull();
    });
});
