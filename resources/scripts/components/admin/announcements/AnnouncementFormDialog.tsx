import { useStore } from '@tanstack/react-form';
import {
    announcementLevels,
    createAdminAnnouncementInput,
    updateAdminAnnouncementInput,
    useCreateAdminAnnouncement,
    useUpdateAdminAnnouncement,
    type AdminAnnouncement,
    type AdminAnnouncementValues,
    type AnnouncementLevel,
} from '@/api/admin/announcements/queries';
import { levelLabels } from '@/components/admin/announcements/levels';
import Button from '@/components/elements/Button';
import { Dialog } from '@/components/elements/dialog';
import SpinnerOverlay from '@/components/elements/SpinnerOverlay';
import { Form, useAppForm } from '@/components/form';
import { formatDate } from '@/lib/dates';

interface Props {
    open: boolean;
    onClose: () => void;
    announcement?: AdminAnnouncement;
}

interface AnnouncementFormValues {
    title: string;
    body: string;
    level: AnnouncementLevel;
    startsAt: string;
    endsAt: string;
    dismissible: boolean;
}

/** `datetime-local` inputs hold local wall time without a zone; the API takes ISO 8601. */
const toLocalInput = (iso: string | null | undefined): string => (iso ? formatDate(iso, 'YYYY-MM-DDTHH:mm') : '');

const toIso = (local: string): string | null => {
    if (!local) {
        return null;
    }

    const date = new Date(local);

    return Number.isNaN(date.getTime()) ? null : date.toISOString();
};

const formValues = (announcement?: AdminAnnouncement): AnnouncementFormValues => ({
    title: announcement?.attributes.title ?? '',
    body: announcement?.attributes.body ?? '',
    level: announcement?.attributes.level ?? 'info',
    startsAt: toLocalInput(announcement?.attributes.starts_at),
    endsAt: toLocalInput(announcement?.attributes.ends_at),
    dismissible: announcement?.attributes.dismissible ?? true,
});

const toRequestValues = (values: AnnouncementFormValues): AdminAnnouncementValues => ({
    title: values.title.trim(),
    body: values.body.trim(),
    level: values.level,
    starts_at: toIso(values.startsAt),
    ends_at: toIso(values.endsAt),
    dismissible: values.dismissible,
});

const validateText = (label: string, max: number, value: string): string | undefined => {
    const length = value.trim().length;

    if (length === 0) {
        return `${label} is required.`;
    }

    return length > max ? `${label} must not exceed ${max} characters.` : undefined;
};

const levelOptions = announcementLevels.map((level) => ({ value: level, label: levelLabels[level] }));

export default function AnnouncementFormDialog({ open, onClose, announcement }: Props) {
    const create = useCreateAdminAnnouncement();
    const update = useUpdateAdminAnnouncement();
    const form = useAppForm({
        defaultValues: formValues(announcement),
        onSubmit: async ({ value }) => {
            try {
                if (announcement) {
                    await update.mutateAsync(
                        updateAdminAnnouncementInput(announcement.attributes.id, toRequestValues(value))
                    );
                } else {
                    await create.mutateAsync(createAdminAnnouncementInput(toRequestValues(value)));
                }

                onClose();
            } catch {
                // Error toast is handled by the mutation.
            }
        },
    });
    const isSubmitting = useStore(form.store, (state) => state.isSubmitting);

    return (
        <Dialog
            open={open}
            title={announcement ? 'Edit announcement' : 'New announcement'}
            preventExternalClose={isSubmitting}
            hideCloseIcon={isSubmitting}
            onClose={onClose}
        >
            <SpinnerOverlay visible={isSubmitting} />
            <Form form={form} className='m-0'>
                <div className='grid gap-5'>
                    <form.AppField
                        name='title'
                        validators={{ onChange: ({ value }) => validateText('Title', 191, value) }}
                    >
                        {(field) => <field.TextField type='text' label='Title' autoComplete='off' />}
                    </form.AppField>
                    <form.AppField
                        name='body'
                        validators={{ onChange: ({ value }) => validateText('Message', 5000, value) }}
                    >
                        {(field) => (
                            <field.TextAreaField
                                label='Message'
                                description='What users see under the title, such as when the panel is back.'
                                rows={4}
                            />
                        )}
                    </form.AppField>
                    <form.AppField name='level'>
                        {(field) => (
                            <field.SelectField
                                label='Level'
                                description='Sets the colour: danger red, warning amber, info violet, success green.'
                                options={levelOptions}
                            />
                        )}
                    </form.AppField>
                    <div className='grid gap-5 sm:grid-cols-2'>
                        <form.AppField name='startsAt'>
                            {(field) => (
                                <field.TextField
                                    type='datetime-local'
                                    label='Starts'
                                    description='Empty shows it right away.'
                                />
                            )}
                        </form.AppField>
                        <form.AppField
                            name='endsAt'
                            validators={{
                                onChangeListenTo: ['startsAt'],
                                onChange: ({ value, fieldApi }) => {
                                    const starts = toIso(fieldApi.form.getFieldValue('startsAt'));
                                    const ends = toIso(value);

                                    return starts && ends && ends <= starts ? 'Must be after the start.' : undefined;
                                },
                            }}
                        >
                            {(field) => (
                                <field.TextField
                                    type='datetime-local'
                                    label='Ends'
                                    description='Empty keeps it until you delete it.'
                                />
                            )}
                        </form.AppField>
                    </div>
                    <form.AppField name='dismissible'>
                        {(field) => (
                            <field.SwitchField
                                label='Dismissible'
                                description='Lets users hide it. Editing the announcement shows it again.'
                            />
                        )}
                    </form.AppField>
                </div>
                <div className='mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end'>
                    <Button.Text type='button' isSecondary onClick={onClose}>
                        Cancel
                    </Button.Text>
                    <form.AppForm>
                        <form.SubmitButton>{announcement ? 'Save changes' : 'Create announcement'}</form.SubmitButton>
                    </form.AppForm>
                </div>
            </Form>
        </Dialog>
    );
}
