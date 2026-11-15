import { Link, useParams } from '@tanstack/react-router';
import { ArrowLeft } from 'lucide-react';
import { httpErrorToHuman } from '@/api/http';
import {
    closeSupportTicketInput,
    loadClientAttachment,
    replySupportTicketInput,
    useCloseSupportTicket,
    useReplySupportTicket,
    useSupportTicket,
} from '@/api/support/queries';
import { Alert } from '@/components/elements/alert';
import Button from '@/components/elements/Button';
import { Dialog } from '@/components/elements/dialog';
import Icon from '@/components/elements/Icon';
import PageContentBlock from '@/components/elements/PageContentBlock';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import SupportComposer from '@/components/support/SupportComposer';
import SupportStatusBadge from '@/components/support/SupportStatusBadge';
import SupportThread from '@/components/support/SupportThread';

/** One of the player's tickets: the thread, a reply box, and closing it. */
export default function SupportTicketContainer() {
    const { ticketId } = useParams({ from: '/authenticated/account/support/$ticketId' });
    const { data: ticket, error } = useSupportTicket(ticketId);
    const reply = useReplySupportTicket();
    const close = useCloseSupportTicket();

    if (error && !ticket) {
        return <ServerError message={httpErrorToHuman(error)} />;
    }

    if (!ticket) {
        return <Spinner size='large' centered />;
    }

    const { data } = ticket;
    const closed = data.status === 'closed';

    return (
        <PageContentBlock title={`Ticket #${data.id}`}>
            <Link
                to='/account/support'
                className='mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground'
            >
                <Icon icon={ArrowLeft} className='size-4' aria-hidden />
                All tickets
            </Link>
            <header className='mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between'>
                <div className='min-w-0'>
                    <h1 className='font-header text-2xl font-semibold break-words text-foreground'>{data.subject}</h1>
                    <p className='mt-1 flex flex-wrap items-center gap-2 text-sm text-muted-foreground'>
                        <SupportStatusBadge status={data.status} />
                        <span>#{data.id}</span>
                        {data.category && <span>· {data.category.name}</span>}
                        {data.server && <span>· {data.server.name}</span>}
                    </p>
                </div>
                {!closed && (
                    <Dialog.ConfirmTrigger
                        title='Close this ticket?'
                        confirm='Close ticket'
                        onConfirmed={async (_event, done) => {
                            try {
                                await close.mutateAsync(closeSupportTicketInput(data.id));
                                done();
                            } catch {
                                // The mutation shows the error.
                            }
                        }}
                        trigger={({ onClick }) => (
                            <Button.Text isSecondary onClick={onClick}>
                                Close ticket
                            </Button.Text>
                        )}
                    >
                        <p className='text-sm text-muted-foreground'>
                            Close it when your problem is solved. Replying within 7 days opens it again.
                        </p>
                    </Dialog.ConfirmTrigger>
                )}
            </header>
            <SupportThread
                messages={data.messages}
                viewer='player'
                loadAttachment={(attachment) => loadClientAttachment(data.id, attachment)}
            />
            <div className='mt-8'>
                {data.can_reply ? (
                    <>
                        {closed && (
                            <Alert type='info' title='This ticket is closed' className='mb-4'>
                                Replying opens it again.
                            </Alert>
                        )}
                        <SupportComposer
                            submitLabel='Send reply'
                            onSubmit={async (body, files) => {
                                try {
                                    await reply.mutateAsync(replySupportTicketInput(data.id, body, files));

                                    return true;
                                } catch {
                                    return false;
                                }
                            }}
                        />
                    </>
                ) : (
                    <Alert type='info' title='This ticket is closed'>
                        It was closed more than 7 days ago. Open a new ticket if you still need help.
                    </Alert>
                )}
            </div>
        </PageContentBlock>
    );
}
