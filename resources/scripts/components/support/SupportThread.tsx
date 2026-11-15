import { Lock } from 'lucide-react';
import type { SupportAttachment, SupportMessage } from '@/api/support/queries';
import Badge from '@/components/elements/Badge';
import Icon from '@/components/elements/Icon';
import SupportAttachmentImage from '@/components/support/SupportAttachmentImage';
import { cn } from '@/lib/cn';
import { formatDate, fromNow } from '@/lib/dates';
import Markdown from '@/lib/markdown';

export type ThreadMessage = SupportMessage & { is_internal?: boolean };

const messageTone = (message: ThreadMessage, own: boolean): string => {
    if (message.is_internal) {
        return 'border-dashed border-warning/60 bg-warning/5';
    }

    return own ? 'border-primary/30 bg-primary/5' : 'border-border bg-card';
};

interface Props {
    messages: readonly ThreadMessage[];
    /** Fetches an image of this ticket through the API. */
    loadAttachment: (attachment: SupportAttachment) => Promise<Blob>;
    /** Staff see their own side on the right; players see theirs. */
    viewer: 'player' | 'staff';
}

/**
 * The messages of a ticket, oldest first. Bodies go through the safe markdown renderer, which
 * builds React elements and never HTML, so markup in a message shows as text.
 */
export default function SupportThread({ messages, loadAttachment, viewer }: Props) {
    return (
        <ol className='space-y-4' aria-label='Messages'>
            {messages.map((message) => {
                const own = viewer === 'staff' ? message.is_staff : !message.is_staff;

                return (
                    <li
                        key={message.id}
                        className={cn('flex', own ? 'justify-end' : 'justify-start')}
                        data-testid='support-message'
                    >
                        <article
                            className={cn('w-full max-w-2xl rounded-lg border p-4', messageTone(message, own))}
                            aria-label={
                                message.is_internal
                                    ? `Internal note by ${message.author}`
                                    : `Message by ${message.author}`
                            }
                        >
                            <header className='mb-2 flex flex-wrap items-center gap-2 text-xs text-muted-foreground'>
                                <span className='font-medium text-foreground'>{message.author}</span>
                                {message.is_staff && (
                                    <Badge tone='accent' variant='soft' size='sm'>
                                        Staff
                                    </Badge>
                                )}
                                {message.is_internal && (
                                    <Badge tone='warning' variant='soft' size='sm'>
                                        <Icon icon={Lock} className='mr-1 inline size-3' aria-hidden />
                                        Internal note
                                    </Badge>
                                )}
                                <time
                                    className='ml-auto tabular-nums'
                                    dateTime={message.created_at}
                                    title={formatDate(message.created_at, 'MMM D, YYYY HH:mm')}
                                >
                                    {fromNow(message.created_at)}
                                </time>
                            </header>
                            <Markdown
                                source={message.body}
                                className='text-sm leading-relaxed break-words text-foreground'
                            />
                            {message.attachments.length > 0 && (
                                <ul className='mt-3 flex flex-wrap gap-2' aria-label='Attachments'>
                                    {message.attachments.map((attachment) => (
                                        <li key={attachment.id}>
                                            <SupportAttachmentImage attachment={attachment} load={loadAttachment} />
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </article>
                    </li>
                );
            })}
        </ol>
    );
}
