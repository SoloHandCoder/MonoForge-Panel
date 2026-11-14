import { getCoreRowModel, useReactTable, type ColumnDef } from '@tanstack/react-table';
import { Megaphone } from 'lucide-react';
import { httpErrorToHuman } from '@/api/http';
import {
    deleteAdminAnnouncementInput,
    useAdminAnnouncements,
    useDeleteAdminAnnouncement,
    type AdminAnnouncement,
} from '@/api/admin/announcements/queries';
import AdminContentBlock from '@/components/admin/AdminContentBlock';
import AdminListEmpty from '@/components/admin/AdminListEmpty';
import AnnouncementFormDialog from '@/components/admin/announcements/AnnouncementFormDialog';
import { levelLabels, levelTones, statusLabels, statusTones } from '@/components/admin/announcements/levels';
import Badge from '@/components/elements/Badge';
import { Dialog } from '@/components/elements/dialog';
import { NewButton } from '@/components/elements/NewButton';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import DataTable from '@/components/elements/table/DataTable';
import { actionsColumn, DeleteAction, EditAction, RowActions } from '@/components/elements/table/RowActions';
import { formatDate } from '@/lib/dates';

const emptyAnnouncements: AdminAnnouncement[] = [];
// The list has no filter to clear.
const noop = () => {};

function CreateAnnouncementButton() {
    return (
        <Dialog.Trigger trigger={({ onClick }) => <NewButton onClick={onClick}>New announcement</NewButton>}>
            {({ open, onClose }) => <AnnouncementFormDialog open={open} onClose={onClose} />}
        </Dialog.Trigger>
    );
}

const scheduleLabel = ({ starts_at: startsAt, ends_at: endsAt }: AdminAnnouncement['attributes']): string => {
    const format = (iso: string) => formatDate(iso, 'MMM D, HH:mm');

    if (startsAt && endsAt) {
        return `${format(startsAt)} to ${format(endsAt)}`;
    }

    if (startsAt) {
        return `From ${format(startsAt)}`;
    }

    return endsAt ? `Until ${format(endsAt)}` : 'Always';
};

function AnnouncementActionsCell({ announcement }: { announcement: AdminAnnouncement }) {
    const remove = useDeleteAdminAnnouncement();
    const { id, title } = announcement.attributes;

    return (
        <RowActions>
            <Dialog.Trigger trigger={({ onClick }) => <EditAction aria-label={`Edit ${title}`} onClick={onClick} />}>
                {({ open, onClose }) => (
                    <AnnouncementFormDialog announcement={announcement} open={open} onClose={onClose} />
                )}
            </Dialog.Trigger>
            <Dialog.ConfirmTrigger
                title='Delete announcement'
                confirm='Delete announcement'
                onConfirmed={async (_event, close) => {
                    try {
                        await remove.mutateAsync(deleteAdminAnnouncementInput(id, title));
                        close();
                    } catch {
                        // Error toast is handled by the mutation.
                    }
                }}
                trigger={({ onClick }) => <DeleteAction aria-label={`Delete ${title}`} onClick={onClick} />}
            >
                <p className='text-sm leading-relaxed text-muted-foreground'>
                    This permanently deletes <strong className='text-foreground'>{title}</strong> and stops showing it
                    to users.
                </p>
            </Dialog.ConfirmTrigger>
        </RowActions>
    );
}

const columns = [
    {
        id: 'title',
        header: 'Announcement',
        cell: ({ row }) => (
            <div className='min-w-0'>
                <p className='truncate font-medium text-foreground'>{row.original.attributes.title}</p>
                <p className='truncate text-xs text-muted-foreground'>{row.original.attributes.body}</p>
            </div>
        ),
        meta: { headerClassName: 'w-auto', cellClassName: 'w-auto' },
    },
    {
        id: 'level',
        header: 'Level',
        cell: ({ row }) => (
            <Badge tone={levelTones[row.original.attributes.level]} variant='soft' size='sm'>
                {levelLabels[row.original.attributes.level]}
            </Badge>
        ),
        meta: { headerClassName: 'w-28', cellClassName: 'w-28' },
    },
    {
        id: 'status',
        header: 'Status',
        cell: ({ row }) => (
            <Badge tone={statusTones[row.original.attributes.status]} variant='soft' size='sm'>
                {statusLabels[row.original.attributes.status]}
            </Badge>
        ),
        meta: { headerClassName: 'w-28', cellClassName: 'w-28' },
    },
    {
        id: 'schedule',
        header: 'Schedule',
        cell: ({ row }) => (
            <span className='text-xs text-muted-foreground tabular-nums'>{scheduleLabel(row.original.attributes)}</span>
        ),
        meta: { headerClassName: 'hidden w-56 @xl:table-cell', cellClassName: 'hidden w-56 @xl:table-cell' },
    },
    actionsColumn<AdminAnnouncement>(2, (announcement) => <AnnouncementActionsCell announcement={announcement} />),
] satisfies ColumnDef<AdminAnnouncement>[];

export default function AnnouncementsContainer() {
    const { data, error, isFetching, refetch } = useAdminAnnouncements();
    const table = useReactTable({
        data: data?.data ?? emptyAnnouncements,
        columns,
        getCoreRowModel: getCoreRowModel(),
        getRowId: (announcement) => String(announcement.attributes.id),
    });

    if (error) {
        return <ServerError message={httpErrorToHuman(error)} onRetry={() => refetch()} />;
    }

    return (
        <AdminContentBlock
            title='Admin · Announcements'
            heading='Announcements'
            description='Messages every user sees above the panel and on the sign-in page, such as planned downtime.'
        >
            <div className='mb-3 flex justify-end'>
                <CreateAnnouncementButton />
            </div>
            {data ? (
                <DataTable
                    table={table}
                    tableClassName='table-fixed'
                    isFetching={isFetching}
                    emptyState={
                        <AdminListEmpty
                            icon={Megaphone}
                            noun='announcements'
                            onClearFilter={noop}
                            description='Create one to warn users about downtime or tell them what is new.'
                            action={<CreateAnnouncementButton />}
                        />
                    }
                />
            ) : (
                <Spinner size='large' centered />
            )}
        </AdminContentBlock>
    );
}
