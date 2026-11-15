import { useAdminSupportSummary } from '@/api/admin/support/queries';

/** The Support link's count of tickets waiting on the signed-in staff member. Hidden at zero. */
export default function SupportNavBadge() {
    const { data } = useAdminSupportSummary();
    const unread = data?.data.unread ?? 0;

    if (unread <= 0) {
        return null;
    }

    return (
        <span
            className='ml-auto rounded-full bg-primary px-1.5 text-xs font-medium text-primary-foreground tabular-nums'
            aria-label={`${unread} ${unread === 1 ? 'ticket waits' : 'tickets wait'} on you`}
        >
            {unread > 99 ? '99+' : unread}
        </span>
    );
}
