import { useId, useState, type ReactNode } from 'react';
import { SUPPORT_MAX_BODY } from '@/api/support/queries';
import Button from '@/components/elements/Button';
import Label from '@/components/elements/Label';
import { TextArea } from '@/components/form/controls';
import SupportImagePicker from '@/components/support/SupportImagePicker';

interface Props {
    label?: string;
    placeholder?: string;
    submitLabel: string;
    /** Sends the message; resolves true when it was sent, which clears the composer. */
    onSubmit: (body: string, files: File[]) => Promise<boolean>;
    disabled?: boolean;
    /** Controls beside the send button, such as the internal-note switch. */
    tools?: ReactNode;
    /** Lets a canned reply fill the text: the composer reads it once per change of the key. */
    insert?: { key: number; text: string } | null;
}

/** A markdown message with up to three images, for players and staff alike. */
export default function SupportComposer({
    label = 'Reply',
    placeholder,
    submitLabel,
    onSubmit,
    disabled,
    tools,
    insert,
}: Props) {
    const id = useId();
    const [body, setBody] = useState('');
    const [files, setFiles] = useState<File[]>([]);
    const [sending, setSending] = useState(false);
    const [inserted, setInserted] = useState<number | null>(null);

    if (insert && insert.key !== inserted) {
        setInserted(insert.key);
        setBody((current) => (current.trim() ? `${current.trimEnd()}\n\n${insert.text}` : insert.text));
    }

    const length = body.trim().length;
    const tooLong = length > SUPPORT_MAX_BODY;
    const canSend = !disabled && !sending && length >= 2 && !tooLong;

    const submit = async () => {
        if (!canSend) {
            return;
        }

        setSending(true);
        try {
            if (await onSubmit(body, files)) {
                setBody('');
                setFiles([]);
            }
        } finally {
            setSending(false);
        }
    };

    return (
        <form
            className='space-y-3'
            onSubmit={(event) => {
                event.preventDefault();
                void submit();
            }}
        >
            <div>
                <Label htmlFor={id}>{label}</Label>
                <TextArea
                    id={id}
                    rows={5}
                    value={body}
                    placeholder={placeholder}
                    disabled={disabled || sending}
                    $hasError={tooLong}
                    aria-describedby={`${id}-help`}
                    onChange={(event) => setBody(event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
                            event.preventDefault();
                            void submit();
                        }
                    }}
                />
                <p id={`${id}-help`} className={tooLong ? 'input-help error' : 'input-help'}>
                    {tooLong
                        ? `Keep it under ${SUPPORT_MAX_BODY.toLocaleString()} characters.`
                        : 'Markdown works: **bold**, *italic*, `code`, lists and links.'}
                </p>
            </div>
            <SupportImagePicker files={files} onChange={setFiles} disabled={disabled || sending} />
            <div className='flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between'>
                <div className='flex flex-wrap items-center gap-3'>{tools}</div>
                <Button type='submit' disabled={!canSend} isLoading={sending}>
                    {submitLabel}
                </Button>
            </div>
        </form>
    );
}
