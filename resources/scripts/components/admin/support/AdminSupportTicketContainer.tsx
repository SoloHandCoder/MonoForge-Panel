import { useId, useState } from 'react';
import { Link, useParams } from '@tanstack/react-router';
import { ArrowLeft } from 'lucide-react';
import { httpErrorToHuman } from '@/api/http';
import {
    loadAdminAttachment,
    staffReplyInput,
    updateSupportTicketInput,
    useAdminSupportOptions,
    useAdminSupportTicket,
    useCannedReplies,
    useStaffReply,
    useUpdateSupportTicket,
    type AdminSupportThread,
} from '@/api/admin/support/queries';
import AdminContentBlock from '@/components/admin/AdminContentBlock';
import Icon from '@/components/elements/Icon';
import Label from '@/components/elements/Label';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import SupportComposer from '@/components/support/SupportComposer';
import SupportStatusBadge from '@/components/support/SupportStatusBadge';
import { supportStatusLabels, supportStatuses } from '@/components/support/supportStatus';
import SupportThread from '@/components/support/SupportThread';
import Select from '@/components/ui/Select';
import Switch from '@/components/ui/Switch';
import { formatDate } from '@/lib/dates';

const NOBODY = 0;

const statusOptions = supportStatuses.map((status) => ({ value: status, label: supportStatusLabels[status] }));

const optionalId = (value: string | number): number | null => (Number(value) === NOBODY ? null : Number(value));

/** The reply box: a reply or an internal note, with the canned replies to insert. */
function StaffComposer({ ticket }: { ticket: AdminSupportThread }) {
    const id = useId();
    const { data: canned } = useCannedReplies();
    const reply = useStaffReply();
    const [internal, setInternal] = useState(false);
    const [insert, setInsert] = useState<{ key: number; text: string } | null>(null);
    const replies = canned?.data ?? [];

    return (
        <SupportComposer
            label={internal ? 'Internal note' : 'Reply'}
            placeholder={internal ? 'Only staff see this note.' : undefined}
            submitLabel={internal ? 'Add note' : 'Send reply'}
            insert={insert}
            tools={
                <>
                    <Switch
                        checked={internal}
                        onChange={setInternal}
                        label='Internal note'
                        description='Only staff see it; the player is not told.'
                    />
                    {replies.length > 0 && (
                        <div className='w-56'>
                            <Select
                                id={`${id}-canned`}
                                value={null}
                                placeholder='Insert a canned reply'
                                options={replies.map((item) => ({ value: item.id, label: item.title }))}
                                onChange={(value) => {
                                    const picked = replies.find((item) => item.id === Number(value));

                                    if (picked) {
                                        setInsert((current) => ({ key: (current?.key ?? 0) + 1, text: picked.body }));
                                    }
                                }}
                            />
                        </div>
                    )}
                </>
            }
            onSubmit={async (body, files) => {
                try {
                    await reply.mutateAsync(staffReplyInput(ticket.id, body, internal, files));

                    return true;
                } catch {
                    return false;
                }
            }}
        />
    );
}

/** Who and what the ticket is about, and its status, assignee and category. */
function TicketDetails({ ticket }: { ticket: AdminSupportThread }) {
    const id = useId();
    const { data: options } = useAdminSupportOptions();
    const update = useUpdateSupportTicket();
    const save = (body: Parameters<typeof updateSupportTicketInput>[1]) => {
        update.mutate(updateSupportTicketInput(ticket.id, body));
    };

    return (
        <aside
            className='space-y-5 rounded-lg border border-border bg-card p-4 lg:self-start'
            aria-label='Ticket details'
        >
            <div className='flex items-center justify-between'>
                <SupportStatusBadge status={ticket.status} />
                <span className='text-xs text-muted-foreground'>#{ticket.id}</span>
            </div>
            <dl className='space-y-2 text-sm'>
                <div>
                    <dt className='text-xs text-muted-foreground'>Player</dt>
                    <dd className='break-all text-foreground'>
                        {ticket.user.username} <span className='text-muted-foreground'>({ticket.user.email})</span>
                    </dd>
                </div>
                {ticket.server && (
                    <div>
                        <dt className='text-xs text-muted-foreground'>Server</dt>
                        <dd className='text-foreground'>
                            {ticket.server.name}{' '}
                            <span className='text-muted-foreground'>({ticket.server.identifier})</span>
                        </dd>
                    </div>
                )}
                <div>
                    <dt className='text-xs text-muted-foreground'>Opened</dt>
                    <dd className='text-foreground tabular-nums'>
                        {formatDate(ticket.created_at, 'MMM D, YYYY HH:mm')}
                    </dd>
                </div>
            </dl>
            <div>
                <Label htmlFor={`${id}-status`}>Status</Label>
                <Select
                    id={`${id}-status`}
                    value={ticket.status}
                    disabled={update.isPending}
                    options={statusOptions}
                    onChange={(value) => {
                        const status = supportStatuses.find((candidate) => candidate === value);

                        if (status) {
                            save({ status });
                        }
                    }}
                />
            </div>
            <div>
                <Label htmlFor={`${id}-assignee`}>Assigned to</Label>
                <Select
                    id={`${id}-assignee`}
                    value={ticket.assignee?.id ?? NOBODY}
                    disabled={update.isPending}
                    options={[
                        { value: NOBODY, label: 'Nobody' },
                        ...(options?.staff ?? []).map((member) => ({ value: member.id, label: member.username })),
                    ]}
                    onChange={(value) => save({ assigned_to: optionalId(value) })}
                />
            </div>
            <div>
                <Label htmlFor={`${id}-category`}>Category</Label>
                <Select
                    id={`${id}-category`}
                    value={ticket.category?.id ?? NOBODY}
                    disabled={update.isPending}
                    options={[
                        { value: NOBODY, label: 'None' },
                        ...(options?.categories ?? []).map((item) => ({ value: item.id, label: item.name })),
                    ]}
                    onChange={(value) => save({ category_id: optionalId(value) })}
                />
            </div>
        </aside>
    );
}

/** Admin → Support → a ticket: the full thread with internal notes, replying, and the ticket's settings. */
export default function AdminSupportTicketContainer() {
    const { ticketId } = useParams({ from: '/authenticated/panel/support/$ticketId' });
    const { data: ticket, error } = useAdminSupportTicket(ticketId);

    if (error && !ticket) {
        return <ServerError message={httpErrorToHuman(error)} />;
    }

    if (!ticket) {
        return <Spinner size='large' centered />;
    }

    const { data } = ticket;

    return (
        <AdminContentBlock title={`Admin · Ticket #${data.id}`} heading={data.subject}>
            <Link
                to='/panel/support'
                className='mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground'
            >
                <Icon icon={ArrowLeft} className='size-4' aria-hidden />
                All tickets
            </Link>
            <div className='grid gap-6 lg:grid-cols-[1fr_18rem]'>
                <div className='min-w-0 space-y-8'>
                    <SupportThread
                        messages={data.messages}
                        viewer='staff'
                        loadAttachment={(attachment) => loadAdminAttachment(data.id, attachment)}
                    />
                    <StaffComposer ticket={data} />
                </div>
                <TicketDetails ticket={data} />
            </div>
        </AdminContentBlock>
    );
}
