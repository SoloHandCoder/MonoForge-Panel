import { useId, useState } from 'react';
import { httpErrorToHuman } from '@/api/http';
import {
    createSupportCategoryInput,
    deleteSupportCategoryInput,
    updateSupportCategoryInput,
    useAdminSupportCategories,
    useCreateSupportCategory,
    useDeleteSupportCategory,
    useUpdateSupportCategory,
    type AdminSupportCategory,
} from '@/api/admin/support/queries';
import Button from '@/components/elements/Button';
import { Dialog } from '@/components/elements/dialog';
import Label from '@/components/elements/Label';
import { NewButton } from '@/components/elements/NewButton';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import { DeleteAction, EditAction, RowActions } from '@/components/elements/table/RowActions';
import { NumberInput, TextInput } from '@/components/form/controls';

function CategoryDialog({
    open,
    onClose,
    category,
    nextSort,
}: {
    open: boolean;
    onClose: () => void;
    category?: AdminSupportCategory;
    nextSort: number;
}) {
    const id = useId();
    const create = useCreateSupportCategory();
    const update = useUpdateSupportCategory();
    const [name, setName] = useState(category?.name ?? '');
    const [sort, setSort] = useState<number>(category?.sort ?? nextSort);
    const pending = create.isPending || update.isPending;
    const valid = name.trim().length > 0 && name.trim().length <= 64;

    const submit = async () => {
        if (!valid) {
            return;
        }

        try {
            if (category) {
                await update.mutateAsync(updateSupportCategoryInput(category.id, name, sort));
            } else {
                await create.mutateAsync(createSupportCategoryInput(name, sort));
            }

            onClose();
        } catch {
            // The mutation shows the error.
        }
    };

    return (
        <Dialog
            open={open}
            title={category ? 'Edit category' : 'New category'}
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
                    <Label htmlFor={`${id}-name`}>Name</Label>
                    <TextInput
                        id={`${id}-name`}
                        value={name}
                        maxLength={64}
                        onValueChange={(value: string) => setName(value)}
                    />
                </div>
                <div>
                    <Label htmlFor={`${id}-sort`}>Order</Label>
                    <NumberInput
                        id={`${id}-sort`}
                        value={sort}
                        min={0}
                        max={1000}
                        onValueChange={(value) => setSort(value ?? 0)}
                    />
                    <p className='input-help'>Lower numbers come first in the player&apos;s list.</p>
                </div>
                <div className='flex flex-col-reverse gap-2 sm:flex-row sm:justify-end'>
                    <Button.Text type='button' isSecondary onClick={onClose} disabled={pending}>
                        Cancel
                    </Button.Text>
                    <Button type='submit' isLoading={pending} disabled={!valid}>
                        {category ? 'Save changes' : 'Create category'}
                    </Button>
                </div>
            </form>
        </Dialog>
    );
}

/** The categories players choose for a ticket. */
export default function SupportCategoriesTab() {
    const { data, error } = useAdminSupportCategories();
    const remove = useDeleteSupportCategory();

    if (error && !data) {
        return <ServerError message={httpErrorToHuman(error)} />;
    }

    if (!data) {
        return <Spinner size='large' centered />;
    }

    const nextSort = Math.max(0, ...data.data.map((category) => category.sort)) + 1;

    return (
        <div className='space-y-4'>
            <div className='flex justify-end'>
                <Dialog.Trigger trigger={({ onClick }) => <NewButton onClick={onClick}>New category</NewButton>}>
                    {({ open, onClose }) => <CategoryDialog open={open} onClose={onClose} nextSort={nextSort} />}
                </Dialog.Trigger>
            </div>
            <ul
                className='divide-y divide-border overflow-hidden rounded-lg border border-border bg-card'
                aria-label='Categories'
            >
                {data.data.map((category) => (
                    <li key={category.id} className='flex items-center gap-4 px-4 py-3'>
                        <div className='min-w-0 flex-1'>
                            <p className='font-medium text-foreground'>{category.name}</p>
                            <p className='text-xs text-muted-foreground'>
                                Order {category.sort} · {category.tickets}{' '}
                                {category.tickets === 1 ? 'ticket' : 'tickets'}
                            </p>
                        </div>
                        <RowActions>
                            <Dialog.Trigger
                                trigger={({ onClick }) => (
                                    <EditAction aria-label={`Edit ${category.name}`} onClick={onClick} />
                                )}
                            >
                                {({ open, onClose }) => (
                                    <CategoryDialog
                                        open={open}
                                        onClose={onClose}
                                        category={category}
                                        nextSort={nextSort}
                                    />
                                )}
                            </Dialog.Trigger>
                            <Dialog.ConfirmTrigger
                                title='Delete category'
                                confirm='Delete category'
                                onConfirmed={async (_event, close) => {
                                    try {
                                        await remove.mutateAsync(
                                            deleteSupportCategoryInput(category.id, category.name)
                                        );
                                        close();
                                    } catch {
                                        // The mutation shows the error.
                                    }
                                }}
                                trigger={({ onClick }) => (
                                    <DeleteAction aria-label={`Delete ${category.name}`} onClick={onClick} />
                                )}
                            >
                                <p className='text-sm text-muted-foreground'>
                                    This deletes <strong className='text-foreground'>{category.name}</strong>. Its
                                    tickets stay, without a category.
                                </p>
                            </Dialog.ConfirmTrigger>
                        </RowActions>
                    </li>
                ))}
            </ul>
        </div>
    );
}
