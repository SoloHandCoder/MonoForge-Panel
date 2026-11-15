import { useId, useState } from 'react';
import { Link } from '@tanstack/react-router';
import { Inbox } from 'lucide-react';
import { httpErrorToHuman } from '@/api/http';
import {
    useAdminSupportOptions,
    useAdminSupportTickets,
    type AdminSupportFilters,
    type AdminSupportStatusFilter,
    type AdminSupportTicketPage,
} from '@/api/admin/support/queries';
import Icon from '@/components/elements/Icon';
import Label from '@/components/elements/Label';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import PaginationFooter from '@/components/elements/table/PaginationFooter';
import { TextInput } from '@/components/form/controls';
import SupportStatusBadge from '@/components/support/SupportStatusBadge';
import { supportStatusLabels, supportStatuses } from '@/components/support/supportStatus';
import Select from '@/components/ui/Select';
import { fromNow } from '@/lib/dates';
import { useDebouncedCallback } from '@/plugins/useDebouncedCallback';

const ALL = 'all';

const statusFilters: readonly AdminSupportStatusFilter[] = ['active', ...supportStatuses];

const statusOptions = [
    { value: 'active', label: 'Not closed' },
    { value: ALL, label: 'Any status' },
    ...supportStatuses.map((status) => ({ value: status, label: supportStatusLabels[status] })),
];

const parseStatus = (value: string | number): AdminSupportStatusFilter | typeof ALL =>
    statusFilters.find((status) => status === value) ?? ALL;

function TicketList({
    data,
    isFetching,
    onPageSelect,
}: {
    data: AdminSupportTicketPage | undefined;
    isFetching: boolean;
    onPageSelect: (page: number) => void;
}) {
    if (!data) {
        return <Spinner size='large' centered />;
    }

    if (data.data.length === 0) {
        return (
            <div className='flex flex-col items-center gap-2 rounded-lg border border-dashed border-border px-6 py-12 text-center'>
                <Icon icon={Inbox} className='size-8 text-muted-foreground' aria-hidden />
                <p className='font-medium text-foreground'>No tickets</p>
                <p className='text-sm text-muted-foreground'>Nothing matches these filters.</p>
            </div>
        );
    }

    return (
        <>
            <ul
                className='divide-y divide-border overflow-hidden rounded-lg border border-border bg-card'
                aria-busy={isFetching}
                aria-label='Tickets'
            >
                {data.data.map((ticket) => (
                    <li key={ticket.id}>
                        <Link
                            to='/panel/support/$ticketId'
                            params={{ ticketId: ticket.id }}
                            className='flex flex-col gap-2 px-4 py-3 transition-colors hover:bg-muted/40 focus-visible:bg-muted/40 focus-visible:outline-none motion-reduce:transition-none md:flex-row md:items-center md:gap-4'
                        >
                            <div className='min-w-0 flex-1'>
                                <p className='flex items-center gap-2 font-medium text-foreground'>
                                    {ticket.unread && (
                                        <span className='size-2 shrink-0 rounded-full bg-primary' aria-label='Unread' />
                                    )}
                                    <span className='truncate'>{ticket.subject}</span>
                                </p>
                                <p className='truncate text-xs text-muted-foreground'>
                                    #{ticket.id} · {ticket.user.username}
                                    {ticket.category && ` · ${ticket.category.name}`}
                                    {ticket.server && ` · ${ticket.server.name}`}
                                </p>
                            </div>
                            <div className='flex flex-wrap items-center gap-3 text-xs text-muted-foreground'>
                                <span>{ticket.assignee ? ticket.assignee.username : 'Unassigned'}</span>
                                <SupportStatusBadge status={ticket.status} />
                                {ticket.last_message_at && (
                                    <time dateTime={ticket.last_message_at} className='tabular-nums'>
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

/** The ticket queue with its filters; each ticket opens its thread. */
export default function TicketsTab() {
    const id = useId();
    const [status, setStatus] = useState<AdminSupportStatusFilter | typeof ALL>('active');
    const [assigned, setAssigned] = useState<string>(ALL);
    const [category, setCategory] = useState<number | typeof ALL>(ALL);
    const [searchInput, setSearchInput] = useState('');
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const applySearch = useDebouncedCallback((value: string) => {
        setSearch(value.trim());
        setPage(1);
    }, 300);
    const { data: options } = useAdminSupportOptions();

    const filters: AdminSupportFilters = {
        page,
        status: status === ALL ? undefined : status,
        assigned: assigned === ALL ? undefined : assigned,
        category: category === ALL ? undefined : category,
        search: search || undefined,
    };
    const { data, error, isFetching } = useAdminSupportTickets(filters);

    if (error && !data) {
        return <ServerError message={httpErrorToHuman(error)} />;
    }

    return (
        <div className='space-y-4'>
            <div className='grid gap-3 sm:grid-cols-2 lg:grid-cols-4'>
                <div>
                    <Label htmlFor={`${id}-search`}>Search</Label>
                    <TextInput
                        id={`${id}-search`}
                        type='search'
                        value={searchInput}
                        placeholder='#12, subject, player'
                        onValueChange={(value: string) => {
                            setSearchInput(value);
                            applySearch(value);
                        }}
                    />
                </div>
                <div>
                    <Label htmlFor={`${id}-status`}>Status</Label>
                    <Select
                        id={`${id}-status`}
                        value={status}
                        options={statusOptions}
                        onChange={(value) => {
                            setStatus(parseStatus(value));
                            setPage(1);
                        }}
                    />
                </div>
                <div>
                    <Label htmlFor={`${id}-assigned`}>Assigned to</Label>
                    <Select
                        id={`${id}-assigned`}
                        value={assigned}
                        options={[
                            { value: ALL, label: 'Anyone' },
                            { value: 'me', label: 'Me' },
                            { value: 'none', label: 'Nobody' },
                            ...(options?.staff ?? []).map((member) => ({
                                value: String(member.id),
                                label: member.username,
                            })),
                        ]}
                        onChange={(value) => {
                            setAssigned(String(value));
                            setPage(1);
                        }}
                    />
                </div>
                <div>
                    <Label htmlFor={`${id}-category`}>Category</Label>
                    <Select
                        id={`${id}-category`}
                        value={category}
                        options={[
                            { value: ALL, label: 'Any category' },
                            ...(options?.categories ?? []).map((item) => ({ value: item.id, label: item.name })),
                        ]}
                        onChange={(value) => {
                            setCategory(value === ALL ? ALL : Number(value));
                            setPage(1);
                        }}
                    />
                </div>
            </div>
            <TicketList data={data} isFetching={isFetching} onPageSelect={setPage} />
        </div>
    );
}
