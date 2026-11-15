import { useState } from 'react';
import { Link } from '@tanstack/react-router';
import { LifeBuoy } from 'lucide-react';
import { httpErrorToHuman } from '@/api/http';
import { useSupportTickets, type SupportTicketPage } from '@/api/support/queries';
import NewTicketDialog from '@/components/account/support/NewTicketDialog';
import { Dialog } from '@/components/elements/dialog';
import Icon from '@/components/elements/Icon';
import { NewButton } from '@/components/elements/NewButton';
import PageContentBlock from '@/components/elements/PageContentBlock';
import PageHeading from '@/components/elements/PageHeading';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import PaginationFooter from '@/components/elements/table/PaginationFooter';
import SupportStatusBadge from '@/components/support/SupportStatusBadge';
import { formatDate, fromNow } from '@/lib/dates';

function NewTicketButton() {
    return (
        <Dialog.Trigger trigger={({ onClick }) => <NewButton onClick={onClick}>New ticket</NewButton>}>
            {({ open, onClose }) => <NewTicketDialog open={open} onClose={onClose} />}
        </Dialog.Trigger>
    );
}

function TicketList({
    data,
    isFetching,
    onPageSelect,
}: {
    data: SupportTicketPage | undefined;
    isFetching: boolean;
    onPageSelect: (page: number) => void;
}) {
    if (!data) {
        return <Spinner size='large' centered />;
    }

    if (data.data.length === 0) {
        return (
            <div className='flex flex-col items-center gap-3 rounded-lg border border-dashed border-border px-6 py-12 text-center'>
                <Icon icon={LifeBuoy} className='size-8 text-muted-foreground' aria-hidden />
                <p className='font-medium text-foreground'>No tickets yet</p>
                <p className='max-w-sm text-sm text-muted-foreground'>
                    Something wrong with a server, your account or a payment? Open a ticket and we will take a look.
                </p>
                <NewTicketButton />
            </div>
        );
    }

    return (
        <>
            <ul
                className='divide-y divide-border overflow-hidden rounded-lg border border-border bg-card'
                aria-busy={isFetching}
            >
                {data.data.map((ticket) => (
                    <li key={ticket.id}>
                        <Link
                            to='/account/support/$ticketId'
                            params={{ ticketId: ticket.id }}
                            className='flex flex-col gap-2 px-4 py-3 transition-colors hover:bg-muted/40 focus-visible:bg-muted/40 focus-visible:outline-none motion-reduce:transition-none sm:flex-row sm:items-center sm:gap-4'
                        >
                            <div className='min-w-0 flex-1'>
                                <p className='flex items-center gap-2 font-medium text-foreground'>
                                    {ticket.unread && (
                                        <span
                                            className='size-2 shrink-0 rounded-full bg-primary'
                                            aria-label='New reply'
                                        />
                                    )}
                                    <span className='truncate'>{ticket.subject}</span>
                                </p>
                                <p className='truncate text-xs text-muted-foreground'>
                                    #{ticket.id}
                                    {ticket.category && ` · ${ticket.category.name}`}
                                    {ticket.server && ` · ${ticket.server.name}`}
                                </p>
                            </div>
                            <div className='flex items-center gap-3 text-xs text-muted-foreground'>
                                <SupportStatusBadge status={ticket.status} />
                                {ticket.last_message_at && (
                                    <time
                                        dateTime={ticket.last_message_at}
                                        title={formatDate(ticket.last_message_at, 'MMM D, YYYY HH:mm')}
                                        className='tabular-nums'
                                    >
                                        {fromNow(ticket.last_message_at)}
                                    </time>
                                )}
                            </div>
                        </Link>
                    </li>
                ))}
            </ul>
            {data.meta.last_page > 1 && (
                <PaginationFooter
                    className='mt-4'
                    pagination={{
                        total: data.meta.total,
                        count: data.data.length,
                        perPage: data.meta.per_page,
                        currentPage: data.meta.current_page,
                        totalPages: data.meta.last_page,
                    }}
                    onPageSelect={onPageSelect}
                />
            )}
        </>
    );
}

/** Account → Support: the player's tickets, newest activity first, and a button to open one. */
export default function SupportContainer() {
    const [page, setPage] = useState(1);
    const { data, error, isFetching } = useSupportTickets(page);

    if (error && !data) {
        return <ServerError message={httpErrorToHuman(error)} />;
    }

    return (
        <PageContentBlock title='Support'>
            <div className='flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between'>
                <PageHeading
                    title='Support'
                    description='Ask our team for help. We answer here, and email you when we do.'
                />
                <NewTicketButton />
            </div>
            <div className='mt-6'>
                <TicketList data={data} isFetching={isFetching} onPageSelect={setPage} />
            </div>
        </PageContentBlock>
    );
}
