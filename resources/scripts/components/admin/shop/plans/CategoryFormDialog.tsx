import { useStore } from '@tanstack/react-form';
import { useSaveShopCategory, type ShopCategory, type ShopCategoryInput } from '@/api/admin/shop/queries';
import { slugify } from '@/components/admin/shop/format';
import Button from '@/components/elements/Button';
import { Dialog } from '@/components/elements/dialog';
import SpinnerOverlay from '@/components/elements/SpinnerOverlay';
import { Form, useAppForm } from '@/components/form';

interface Props {
    open: boolean;
    onClose: () => void;
    category?: ShopCategory;
}

const required =
    (label: string) =>
    ({ value }: { value: string }) =>
        value.trim() === '' ? `${label} is required.` : undefined;

export default function CategoryFormDialog({ open, onClose, category }: Props) {
    const save = useSaveShopCategory();
    const form = useAppForm({
        defaultValues: {
            name: category?.name ?? '',
            slug: category?.slug ?? '',
            description: category?.description ?? '',
            visible: category?.visible ?? true,
        },
        onSubmit: async ({ value }) => {
            const values: ShopCategoryInput = {
                name: value.name.trim(),
                slug: value.slug.trim(),
                description: value.description.trim() || null,
                visible: value.visible,
            };

            try {
                await save.mutateAsync({ id: category?.id, values });
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
            title={category ? 'Edit category' : 'New category'}
            preventExternalClose={isSubmitting}
            hideCloseIcon={isSubmitting}
            onClose={onClose}
        >
            <SpinnerOverlay visible={isSubmitting} />
            <Form form={form} className='m-0'>
                <div className='grid gap-5'>
                    <form.AppField
                        name='name'
                        validators={{ onChange: required('Name') }}
                        listeners={{
                            onChange: ({ value }) => {
                                // A new category's slug follows its name until the slug is edited by hand.
                                if (!category && !form.getFieldMeta('slug')?.isDirty) {
                                    form.setFieldValue('slug', slugify(value), { dontUpdateMeta: true });
                                }
                            },
                        }}
                    >
                        {(field) => <field.TextField type='text' label='Name' autoComplete='off' />}
                    </form.AppField>
                    <form.AppField
                        name='slug'
                        validators={{
                            onChange: ({ value }) =>
                                /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(value)
                                    ? undefined
                                    : 'Use lower-case letters, numbers and dashes.',
                        }}
                    >
                        {(field) => (
                            <field.TextField
                                type='text'
                                label='Slug'
                                description='Part of the store link, such as /store/minecraft.'
                                autoComplete='off'
                            />
                        )}
                    </form.AppField>
                    <form.AppField name='description'>
                        {(field) => <field.TextAreaField label='Description' rows={3} />}
                    </form.AppField>
                    <form.AppField name='visible'>
                        {(field) => (
                            <field.SwitchField
                                label='Visible in the store'
                                description='Hidden categories and their plans are not for sale.'
                            />
                        )}
                    </form.AppField>
                </div>
                <div className='mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end'>
                    <Button.Text type='button' isSecondary onClick={onClose}>
                        Cancel
                    </Button.Text>
                    <form.AppForm>
                        <form.SubmitButton>{category ? 'Save changes' : 'Create category'}</form.SubmitButton>
                    </form.AppForm>
                </div>
            </Form>
        </Dialog>
    );
}
