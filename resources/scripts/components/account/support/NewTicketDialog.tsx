import { useId, useState } from 'react';
import { useNavigate } from '@tanstack/react-router';
import {
    openSupportTicketInput,
    SUPPORT_MAX_BODY,
    useOpenSupportTicket,
    useSupportOptions,
} from '@/api/support/queries';
import Button from '@/components/elements/Button';
import { Dialog } from '@/components/elements/dialog';
import Label from '@/components/elements/Label';
import Spinner from '@/components/elements/Spinner';
import { TextArea, TextInput } from '@/components/form/controls';
import SupportImagePicker from '@/components/support/SupportImagePicker';
import Select from '@/components/ui/Select';

interface Props {
    open: boolean;
    onClose: () => void;
}

const NO_SERVER = '';

const bodyProblem = (body: string): string | null => {
    const length = body.trim().length;

    if (length < 2) {
        return 'Describe the problem.';
    }

    return length > SUPPORT_MAX_BODY ? `Keep it under ${SUPPORT_MAX_BODY.toLocaleString()} characters.` : null;
};

/** Opens a ticket: subject, category, optionally one of the player's servers, and the first message. */
export default function NewTicketDialog({ open, onClose }: Props) {
    const id = useId();
    const navigate = useNavigate();
    const { data: options } = useSupportOptions();
    const openTicket = useOpenSupportTicket();
    const [subject, setSubject] = useState('');
    const [categoryId, setCategoryId] = useState<number | null>(null);
    const [server, setServer] = useState<string>(NO_SERVER);
    const [body, setBody] = useState('');
    const [files, setFiles] = useState<File[]>([]);
    const [touched, setTouched] = useState(false);

    const errors = {
        subject: subject.trim().length < 3 ? 'Give the ticket a subject of at least 3 characters.' : null,
        category: categoryId === null ? 'Choose a category.' : null,
        body: bodyProblem(body),
    };
    const valid = !errors.subject && !errors.category && !errors.body;
    const submitting = openTicket.isPending;

    const submit = async () => {
        setTouched(true);
        if (!valid || categoryId === null) {
            return;
        }

        try {
            const created = await openTicket.mutateAsync(
                openSupportTicketInput({ subject, categoryId, server: server || null, body, attachments: files })
            );

            onClose();
            await navigate({ to: '/account/support/$ticketId', params: { ticketId: created.data.id } });
        } catch {
            // The mutation shows the error.
        }
    };

    const show = (error: string | null) => (touched ? error : null);

    return (
        <Dialog open={open} title='New support ticket' preventExternalClose={submitting} onClose={onClose}>
            {options ? (
                <form
                    className='grid gap-5'
                    onSubmit={(event) => {
                        event.preventDefault();
                        void submit();
                    }}
                >
                    <div>
                        <Label htmlFor={`${id}-subject`}>Subject</Label>
                        <TextInput
                            id={`${id}-subject`}
                            value={subject}
                            maxLength={150}
                            autoComplete='off'
                            $hasError={!!show(errors.subject)}
                            aria-invalid={!!show(errors.subject)}
                            aria-describedby={`${id}-subject-help`}
                            onValueChange={(value: string) => setSubject(value)}
                        />
                        <p
                            id={`${id}-subject-help`}
                            className={show(errors.subject) ? 'input-help error' : 'input-help'}
                        >
                            {show(errors.subject) ?? 'A short summary, such as "Server stops after loading the world".'}
                        </p>
                    </div>
                    <div className='grid gap-5 sm:grid-cols-2'>
                        <div>
                            <Label htmlFor={`${id}-category`}>Category</Label>
                            <Select
                                id={`${id}-category`}
                                value={categoryId}
                                placeholder='Choose a category'
                                hasError={!!show(errors.category)}
                                options={options.categories.map((category) => ({
                                    value: category.id,
                                    label: category.name,
                                }))}
                                onChange={(value) => setCategoryId(Number(value))}
                            />
                            {show(errors.category) && <p className='input-help error'>{show(errors.category)}</p>}
                        </div>
                        <div>
                            <Label htmlFor={`${id}-server`}>Server</Label>
                            <Select
                                id={`${id}-server`}
                                value={server}
                                options={[
                                    { value: NO_SERVER, label: 'Not about a server' },
                                    ...options.servers.map((item) => ({ value: item.identifier, label: item.name })),
                                ]}
                                onChange={(value) => setServer(String(value))}
                            />
                        </div>
                    </div>
                    <div>
                        <Label htmlFor={`${id}-body`}>Message</Label>
                        <TextArea
                            id={`${id}-body`}
                            rows={6}
                            value={body}
                            $hasError={!!show(errors.body)}
                            aria-invalid={!!show(errors.body)}
                            aria-describedby={`${id}-body-help`}
                            onChange={(event) => setBody(event.target.value)}
                        />
                        <p id={`${id}-body-help`} className={show(errors.body) ? 'input-help error' : 'input-help'}>
                            {show(errors.body) ?? 'What happened, and what you already tried. Markdown works.'}
                        </p>
                    </div>
                    <SupportImagePicker files={files} onChange={setFiles} disabled={submitting} />
                    <div className='flex flex-col-reverse gap-2 sm:flex-row sm:justify-end'>
                        <Button.Text type='button' isSecondary onClick={onClose} disabled={submitting}>
                            Cancel
                        </Button.Text>
                        <Button type='submit' isLoading={submitting}>
                            Open ticket
                        </Button>
                    </div>
                </form>
            ) : (
                <Spinner size='large' centered />
            )}
        </Dialog>
    );
}
