import { useId, useState } from 'react';
import { MessageSquareText } from 'lucide-react';
import { httpErrorToHuman } from '@/api/http';
import {
    createCannedReplyInput,
    deleteCannedReplyInput,
    updateCannedReplyInput,
    useCannedReplies,
    useCreateCannedReply,
    useDeleteCannedReply,
    useUpdateCannedReply,
    type CannedReply,
} from '@/api/admin/support/queries';
import Button from '@/components/elements/Button';
import { Dialog } from '@/components/elements/dialog';
import Icon from '@/components/elements/Icon';
import Label from '@/components/elements/Label';
import { NewButton } from '@/components/elements/NewButton';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import { DeleteAction, EditAction, RowActions } from '@/components/elements/table/RowActions';
import { TextArea, TextInput } from '@/components/form/controls';
import { SUPPORT_MAX_BODY } from '@/api/support/queries';

function CannedReplyDialog({ open, onClose, reply }: { open: boolean; onClose: () => void; reply?: CannedReply }) {
    const id = useId();
    const create = useCreateCannedReply();
    const update = useUpdateCannedReply();
    const [title, setTitle] = useState(reply?.title ?? '');
    const [body, setBody] = useState(reply?.body ?? '');
    const pending = create.isPending || update.isPending;
    const valid =
        title.trim().length > 0 &&
        title.trim().length <= 100 &&
        body.trim().length > 0 &&
        body.length <= SUPPORT_MAX_BODY;

    const submit = async () => {
        if (!valid) {
            return;
        }

        try {
            if (reply) {
                await update.mutateAsync(updateCannedReplyInput(reply.id, title, body));
            } else {
                await create.mutateAsync(createCannedReplyInput(title, body));
            }

            onClose();
        } catch {
            // The mutation shows the error.
        }
    };

    return (
        <Dialog
            open={open}
            title={reply ? 'Edit canned reply' : 'New canned reply'}
            preventExternalClose={pending}
            onClose={onClose}
        >
            <form
                className='grid gap-5'
                onSubmit={(event) => {
                    event.preventDefault();
                    void submit();
                }}
            >
                <div>
                    <Label htmlFor={`${id}-title`}>Title</Label>
                    <TextInput
                        id={`${id}-title`}
                        value={title}
                        maxLength={100}
                        onValueChange={(value: string) => setTitle(value)}
                    />
                    <p className='input-help'>Staff pick it by this name.</p>
                </div>
                <div>
                    <Label htmlFor={`${id}-body`}>Text</Label>
                    <TextArea
                        id={`${id}-body`}
                        rows={6}
                        value={body}
                        onChange={(event) => setBody(event.target.value)}
                    />
                    <p className='input-help'>
                        Inserted into the reply box, where it can still be edited. Markdown works.
                    </p>
                </div>
                <div className='flex flex-col-reverse gap-2 sm:flex-row sm:justify-end'>
                    <Button.Text type='button' isSecondary onClick={onClose} disabled={pending}>
                        Cancel
                    </Button.Text>
                    <Button type='submit' isLoading={pending} disabled={!valid}>
                        {reply ? 'Save changes' : 'Create canned reply'}
                    </Button>
                </div>
            </form>
        </Dialog>
    );
}

function NewCannedReplyButton() {
    return (
        <Dialog.Trigger trigger={({ onClick }) => <NewButton onClick={onClick}>New canned reply</NewButton>}>
            {({ open, onClose }) => <CannedReplyDialog open={open} onClose={onClose} />}
        </Dialog.Trigger>
    );
}

/** Saved answers staff insert into replies. */
export default function CannedRepliesTab() {
    const { data, error } = useCannedReplies();
    const remove = useDeleteCannedReply();

    if (error && !data) {
        return <ServerError message={httpErrorToHuman(error)} />;
    }

    if (!data) {
        return <Spinner size='large' centered />;
    }

    return (
        <div className='space-y-4'>
            <div className='flex justify-end'>
                <NewCannedReplyButton />
            </div>
            {data.data.length === 0 ? (
                <div className='flex flex-col items-center gap-2 rounded-lg border border-dashed border-border px-6 py-12 text-center'>
                    <Icon icon={MessageSquareText} className='size-8 text-muted-foreground' aria-hidden />
                    <p className='font-medium text-foreground'>No canned replies</p>
                    <p className='text-sm text-muted-foreground'>
                        Save answers you give often, then insert them into a reply.
                    </p>
                </div>
            ) : (
                <ul
                    className='divide-y divide-border overflow-hidden rounded-lg border border-border bg-card'
                    aria-label='Canned replies'
                >
                    {data.data.map((reply) => (
                        <li key={reply.id} className='flex items-start gap-4 px-4 py-3'>
                            <div className='min-w-0 flex-1'>
                                <p className='font-medium text-foreground'>{reply.title}</p>
                                <p className='line-clamp-2 text-sm whitespace-pre-line text-muted-foreground'>
                                    {reply.body}
                                </p>
                            </div>
                            <RowActions>
                                <Dialog.Trigger
                                    trigger={({ onClick }) => (
                                        <EditAction aria-label={`Edit ${reply.title}`} onClick={onClick} />
                                    )}
                                >
                                    {({ open, onClose }) => (
                                        <CannedReplyDialog open={open} onClose={onClose} reply={reply} />
                                    )}
                                </Dialog.Trigger>
                                <Dialog.ConfirmTrigger
                                    title='Delete canned reply'
                                    confirm='Delete canned reply'
                                    onConfirmed={async (_event, close) => {
                                        try {
                                            await remove.mutateAsync(deleteCannedReplyInput(reply.id, reply.title));
                                            close();
                                        } catch {
                                            // The mutation shows the error.
                                        }
                                    }}
                                    trigger={({ onClick }) => (
                                        <DeleteAction aria-label={`Delete ${reply.title}`} onClick={onClick} />
                                    )}
                                >
                                    <p className='text-sm text-muted-foreground'>
                                        This deletes <strong className='text-foreground'>{reply.title}</strong>. Replies
                                        already sent keep their text.
                                    </p>
                                </Dialog.ConfirmTrigger>
                            </RowActions>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
