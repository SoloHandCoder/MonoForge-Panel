import { useStore } from '@tanstack/react-form';
import {
    useCreateAdminTokenPack,
    useUpdateAdminTokenPack,
    type AdminTokenPack,
    type AdminTokenPackInput,
} from '@/api/admin/shop/tebex';
import Button from '@/components/elements/Button';
import { Dialog } from '@/components/elements/dialog';
import SpinnerOverlay from '@/components/elements/SpinnerOverlay';
import { Form, useAppForm } from '@/components/form';

interface Props {
    open: boolean;
    onClose: () => void;
    pack?: AdminTokenPack;
}

interface PackFormValues {
    tebexPackageId: string;
    name: string;
    tokens: number | null;
    bonusTokens: number | null;
    sort: number | null;
    visible: boolean;
}

const formValues = (pack?: AdminTokenPack): PackFormValues => ({
    tebexPackageId: pack?.tebex_package_id ?? '',
    name: pack?.name ?? '',
    tokens: pack?.tokens ?? null,
    bonusTokens: pack?.bonus_tokens ?? 0,
    sort: pack?.sort ?? 0,
    visible: pack?.visible ?? true,
});

const toRequestValues = (values: PackFormValues): AdminTokenPackInput => ({
    tebex_package_id: values.tebexPackageId.trim(),
    name: values.name.trim(),
    tokens: values.tokens ?? 0,
    bonus_tokens: values.bonusTokens ?? 0,
    sort: values.sort ?? 0,
    visible: values.visible,
});

/** Creates or edits a token pack: the Tebex package it sells and the tokens it credits. */
export default function TokenPackFormDialog({ open, onClose, pack }: Props) {
    const create = useCreateAdminTokenPack();
    const update = useUpdateAdminTokenPack();
    const form = useAppForm({
        defaultValues: formValues(pack),
        onSubmit: async ({ value }) => {
            try {
                if (pack) {
                    await update.mutateAsync({ id: pack.id, body: toRequestValues(value) });
                } else {
                    await create.mutateAsync(toRequestValues(value));
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
            title={pack ? 'Edit token pack' : 'New token pack'}
            preventExternalClose={isSubmitting}
            hideCloseIcon={isSubmitting}
            onClose={onClose}
        >
            <SpinnerOverlay visible={isSubmitting} />
            <Form form={form} className='m-0'>
                <div className='grid gap-5'>
                    <form.AppField
                        name='name'
                        validators={{ onChange: ({ value }) => (value.trim() ? undefined : 'A name is required.') }}
                    >
                        {(field) => <field.TextField type='text' label='Name' autoComplete='off' />}
                    </form.AppField>
                    <form.AppField
                        name='tebexPackageId'
                        validators={{
                            onChange: ({ value }) =>
                                /^[0-9]+$/.test(value.trim()) ? undefined : 'Enter the numeric Tebex package ID.',
                        }}
                    >
                        {(field) => (
                            <field.TextField
                                type='text'
                                inputMode='numeric'
                                label='Tebex package ID'
                                description='The numeric ID of a fixed package in the Tebex creator panel (Packages → edit → the number in the URL).'
                                autoComplete='off'
                            />
                        )}
                    </form.AppField>
                    <div className='grid gap-5 sm:grid-cols-2'>
                        <form.AppField
                            name='tokens'
                            validators={{
                                onChange: ({ value }) => (value && value >= 1 ? undefined : 'At least 1 token.'),
                            }}
                        >
                            {(field) => <field.NumberField label='Tokens' min={1} />}
                        </form.AppField>
                        <form.AppField name='bonusTokens'>
                            {(field) => (
                                <field.NumberField
                                    label='Bonus tokens'
                                    description='Added on top of the tokens.'
                                    min={0}
                                />
                            )}
                        </form.AppField>
                    </div>
                    <form.AppField name='sort'>
                        {(field) => <field.NumberField label='Sort order' description='Lower shows first.' />}
                    </form.AppField>
                    <form.AppField name='visible'>
                        {(field) => (
                            <field.SwitchField label='On sale' description='Show this pack on the Wallet page.' />
                        )}
                    </form.AppField>
                </div>
                <div className='mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end'>
                    <Button.Text type='button' isSecondary onClick={onClose}>
                        Cancel
                    </Button.Text>
                    <form.AppForm>
                        <form.SubmitButton>{pack ? 'Save changes' : 'Create token pack'}</form.SubmitButton>
                    </form.AppForm>
                </div>
            </Form>
        </Dialog>
    );
}
