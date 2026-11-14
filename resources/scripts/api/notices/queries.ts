import { queryOptions, useMutation, useQueryClient } from '@tanstack/react-query';
import http from '@/api/http';
import { invalidateGeneratedOperations } from '@/api/mutationUtils';
import { clientListNoticesOptions, clientMarkNoticeReadMutation } from '@/api/generated/@tanstack/react-query.gen';
import type { ClientNoticeFeedResource } from '@/api/generated';

export type NoticeFeed = ClientNoticeFeedResource['attributes'];
export type FeedAnnouncement = NoticeFeed['announcements'][number];
export type FeedNotice = NoticeFeed['notices'][number];

/** How often the panel checks for new announcements and notices. */
export const NOTICE_POLL_INTERVAL = 60_000;

/** The signed-in user's feed: the active announcements and their unread notices. */
export const noticeFeedQueryOptions = () =>
    queryOptions({
        ...clientListNoticesOptions(),
        refetchInterval: NOTICE_POLL_INTERVAL,
        staleTime: NOTICE_POLL_INTERVAL / 2,
    });

/** The sign-in pages' feed, which has the active announcements only. */
export const publicNoticeFeedQueryOptions = () =>
    queryOptions({
        queryKey: ['notices', 'public'],
        // The sign-in feed is public, so it sits outside the documented API and its generated client.
        queryFn: async ({ signal }): Promise<ClientNoticeFeedResource> =>
            // oxlint-disable-next-line no-restricted-properties
            (await http.get<ClientNoticeFeedResource>('/api/notices/public', { signal })).data,
        refetchInterval: NOTICE_POLL_INTERVAL,
        staleTime: NOTICE_POLL_INTERVAL / 2,
    });

/** Marks one of the user's notices read, which takes it out of the feed. */
export const useMarkNoticeRead = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...clientMarkNoticeReadMutation(),
        onSettled: () => invalidateGeneratedOperations(queryClient, ['clientListNotices']),
    });
};
