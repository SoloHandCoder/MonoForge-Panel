import { useStore } from '@tanstack/react-form';
import { useId, useState } from 'react';
import {
    type AdminLegalDocument,
    updateAdminLegalDocumentInput,
    useAdminLegalDocuments,
    useUpdateAdminLegalDocument,
} from '@/api/admin/settings/legal';
import { httpErrorToHuman } from '@/api/http';
import type { LegalDocumentId } from '@/api/settings/types';
import { Alert } from '@/components/elements/alert';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import TitledGreyBox from '@/components/elements/TitledGreyBox';
import { Form, useAppForm } from '@/components/form';
import { formatLegalDate } from '@/components/legal/formatLegalDate';
import Checkbox from '@/components/ui/Checkbox';
import { cn } from '@/lib/cn';
import Markdown from '@/lib/markdown';

const isHttpsUrl = (value: string): boolean => {
    try {
        return new URL(value).protocol === 'https:';
    } catch {
        return false;
    }
};

function ReacceptCheckbox({
    document,
    checked,
    onChange,
}: {
    document: LegalDocumentId;
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    const id = useId();
    const accepted = document !== 'refunds';

    return (
        <div className='flex items-start gap-3'>
            <Checkbox id={id} checked={checked} onChange={onChange} />
            <label htmlFor={id} className='text-sm'>
                <span className='font-medium text-foreground'>
                    {accepted ? 'Ask players to accept again' : 'Save as a new version'}
                </span>
                <span className='block text-xs text-muted-foreground'>
                    {accepted
                        ? 'Raises the version: every signed-in player, admins included, has to accept the update before using the panel. Leave it off for typo fixes.'
                        : 'Raises the version number shown on the page. Players accept the refund policy with the Terms of Service when they buy.'}
                </span>
            </label>
        </div>
    );
}

export function LegalDocumentForm({ document }: { document: AdminLegalDocument }) {
    const update = useUpdateAdminLegalDocument();
    const [preview, setPreview] = useState(false);
    const form = useAppForm({
        defaultValues: { content: document.content, externalUrl: document.externalUrl, reaccept: false },
        onSubmit: async ({ value }) => {
            try {
                await update.mutateAsync(updateAdminLegalDocumentInput({ document: document.document, ...value }));
                form.reset({ ...value, reaccept: false });
            } catch {
                // Error toast is handled by the mutation.
            }
        },
    });
    const content = useStore(form.store, (state) => state.values.content);
    const externalUrl = useStore(form.store, (state) => state.values.externalUrl);
    const updated = formatLegalDate(document.updatedAt);

    return (
        <Form form={form}>
            <p className='mb-4 text-sm text-muted-foreground'>
                Version {document.version}
                {updated && <> · Last updated {updated}</>} ·{' '}
                <a
                    href={document.publicUrl}
                    target='_blank'
                    rel='noopener noreferrer'
                    className='text-accent underline underline-offset-2'
                >
                    View the public page
                </a>
            </p>
            {document.isDefault && (
                <Alert type='warning' className='mb-6 text-sm'>
                    <span>
                        This is the starter text shipped with the panel. It is a template, not legal advice: read it,
                        fill in every placeholder in square brackets (such as <code>[Your name / business]</code> and{' '}
                        <code>[contact email]</code>) and check it against the law that applies to you before you rely
                        on it.
                    </span>
                </Alert>
            )}
            <TitledGreyBox title='Hosted elsewhere'>
                <form.AppField
                    name='externalUrl'
                    validators={{
                        onChange: ({ value }) =>
                            value.trim() === '' || isHttpsUrl(value.trim())
                                ? undefined
                                : 'Use a full https:// address, or leave it blank.',
                    }}
                >
                    {(field) => (
                        <field.TextField
                            type='url'
                            id={`legal_${document.document}_url`}
                            label='External URL'
                            placeholder='https://example.com/terms'
                            description='Leave blank to host the document on the panel. When set, every link and /legal page goes to this address instead.'
                        />
                    )}
                </form.AppField>
            </TitledGreyBox>
            <TitledGreyBox title='Document' className={cn('mt-6', externalUrl.trim() !== '' && 'opacity-70')}>
                <div className='mb-3 flex gap-2' role='tablist' aria-label='Editor mode'>
                    {(['Write', 'Preview'] as const).map((mode) => (
                        <button
                            key={mode}
                            type='button'
                            role='tab'
                            aria-selected={preview === (mode === 'Preview')}
                            onClick={() => setPreview(mode === 'Preview')}
                            className={cn(
                                'rounded-md px-3 py-1 text-sm transition-colors',
                                preview === (mode === 'Preview')
                                    ? 'bg-muted text-foreground'
                                    : 'text-muted-foreground hover:text-foreground'
                            )}
                        >
                            {mode}
                        </button>
                    ))}
                </div>
                {preview ? (
                    <div className='max-h-[60vh] overflow-y-auto rounded-md border border-border p-4' role='tabpanel'>
                        <Markdown source={content} />
                    </div>
                ) : (
                    <form.AppField name='content'>
                        {(field) => (
                            <field.TextAreaField
                                id={`legal_${document.document}_content`}
                                label='Markdown'
                                rows={20}
                                className='font-mono text-sm'
                                description='Headings (#), paragraphs, lists (- or 1.), quotes (>), **bold**, *italic* and [links](https://…). Raw HTML is shown as text and only https:// or /legal/… links work. Leave empty to use the starter text.'
                            />
                        )}
                    </form.AppField>
                )}
            </TitledGreyBox>
            <div className='mt-6 flex flex-wrap items-center justify-between gap-4'>
                <form.Field name='reaccept'>
                    {(field) => (
                        <ReacceptCheckbox
                            document={document.document}
                            checked={field.state.value}
                            onChange={(checked) => field.handleChange(checked)}
                        />
                    )}
                </form.Field>
                <form.AppForm>
                    <form.SubmitButton>Save {document.title}</form.SubmitButton>
                </form.AppForm>
            </div>
        </Form>
    );
}

/** Admin > Settings > Legal: the Terms of Service, Privacy Policy and Refund Policy. */
export default function LegalSettings() {
    const { data, error, refetch } = useAdminLegalDocuments();
    const [selected, setSelected] = useState<LegalDocumentId>('terms');

    if (error && !data) {
        return <ServerError message={httpErrorToHuman(error)} onRetry={() => refetch()} />;
    }

    if (!data) {
        return <Spinner size='large' centered />;
    }

    const document = data.find((entry) => entry.document === selected) ?? data[0];

    return (
        <div>
            <div className='mb-6 flex flex-wrap gap-2' role='tablist' aria-label='Legal documents'>
                {data.map((entry) => (
                    <button
                        key={entry.document}
                        type='button'
                        role='tab'
                        aria-selected={entry.document === document?.document}
                        onClick={() => setSelected(entry.document)}
                        className={cn(
                            'rounded-full border px-4 py-1.5 text-sm transition-colors',
                            entry.document === document?.document
                                ? 'border-accent bg-accent text-accent-foreground'
                                : 'border-border text-muted-foreground hover:text-foreground'
                        )}
                    >
                        {entry.title}
                    </button>
                ))}
            </div>
            {/* Keyed so switching documents starts a fresh form. */}
            {document && <LegalDocumentForm key={document.document} document={document} />}
        </div>
    );
}
