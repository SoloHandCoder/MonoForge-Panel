import { useStore } from '@tanstack/react-form';
import { PackagePlus } from 'lucide-react';
import { httpErrorToHuman } from '@/api/http';
import {
    shopAddonResources,
    useAdminShopAddons,
    useDeleteShopAddon,
    useSaveShopAddon,
    type ShopAddon,
    type ShopAddonInput,
    type ShopAddonResource,
} from '@/api/admin/shop/queries';
import { addonResourceLabels, formatAddonAmount, slugify } from '@/components/admin/shop/format';
import Badge from '@/components/elements/Badge';
import Button from '@/components/elements/Button';
import Card from '@/components/elements/Card';
import { Dialog } from '@/components/elements/dialog';
import { NewButton } from '@/components/elements/NewButton';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import SpinnerOverlay from '@/components/elements/SpinnerOverlay';
import { DeleteAction, EditAction, RowActions } from '@/components/elements/table/RowActions';
import { Form, useAppForm } from '@/components/form';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { formatTokens } from '@/lib/shopPricing';

const resourceOptions = shopAddonResources.map((resource) => ({
    value: resource,
    label: addonResourceLabels[resource],
}));

const amountLabel = {
    memory: 'Memory per unit (MB)',
    disk: 'Disk per unit (MB)',
    cpu: 'CPU per unit (%)',
    backups: 'Backups per unit',
    databases: 'Databases per unit',
    allocations: 'Ports per unit',
} satisfies Record<ShopAddonResource, string>;

interface AddonFormValues {
    key: string;
    name: string;
    description: string;
    resource: ShopAddonResource;
    amount: number | null;
    price_tokens: number | null;
    max_units: number | null;
    enabled: boolean;
}

const newAddon: AddonFormValues = {
    key: '',
    name: '',
    description: '',
    resource: 'backups',
    amount: 1,
    price_tokens: 50,
    max_units: 5,
    enabled: true,
};

const addonValues = (addon: ShopAddon | undefined): AddonFormValues =>
    addon ? { ...addon, description: addon.description ?? '' } : newAddon;

const addonInput = (value: AddonFormValues): ShopAddonInput => ({
    key: value.key.trim(),
    name: value.name.trim(),
    description: value.description.trim() || null,
    resource: value.resource,
    amount: value.amount ?? 1,
    price_tokens: value.price_tokens ?? 0,
    max_units: value.max_units ?? 1,
    enabled: value.enabled,
});

function AddonPreview({ values }: { values: AddonFormValues }) {
    return (
        <p className='rounded-md border border-border bg-muted/40 p-3 text-sm text-foreground'>
            Each unit: <strong>{formatAddonAmount(values.resource, values.amount ?? 0)}</strong> for{' '}
            <strong className='tabular-nums'>{formatTokens(values.price_tokens ?? 0)}</strong> every 30 days, up to{' '}
            {values.max_units ?? 1} per server.
        </p>
    );
}

function AddonFormDialog({ open, onClose, addon }: { open: boolean; onClose: () => void; addon?: ShopAddon }) {
    const save = useSaveShopAddon();
    const form = useAppForm({
        defaultValues: addonValues(addon),
        onSubmit: async ({ value }) => {
            try {
                await save.mutateAsync({ id: addon?.id, values: addonInput(value) });
                onClose();
            } catch {
                // Error toast is handled by the mutation.
            }
        },
    });
    const isSubmitting = useStore(form.store, (state) => state.isSubmitting);
    const preview = useStore(form.store, (state) => state.values);

    return (
        <Dialog
            open={open}
            title={addon ? 'Edit add-on' : 'New add-on'}
            preventExternalClose={isSubmitting}
            hideCloseIcon={isSubmitting}
            onClose={onClose}
        >
            <SpinnerOverlay visible={isSubmitting} />
            <Form form={form} className='m-0'>
                <div className='grid gap-5'>
                    <div className='grid gap-5 sm:grid-cols-2'>
                        <form.AppField
                            name='name'
                            validators={{ onChange: ({ value }) => (value.trim() ? undefined : 'Name is required.') }}
                            listeners={{
                                onChange: ({ value }) => {
                                    if (!addon && !form.getFieldMeta('key')?.isDirty) {
                                        form.setFieldValue('key', slugify(value).slice(0, 64), {
                                            dontUpdateMeta: true,
                                        });
                                    }
                                },
                            }}
                        >
                            {(field) => (
                                <field.TextField
                                    type='text'
                                    label='Name'
                                    placeholder='Extra backup'
                                    autoComplete='off'
                                />
                            )}
                        </form.AppField>
                        <form.AppField
                            name='key'
                            validators={{
                                onChange: ({ value }) =>
                                    /^[a-z0-9]+(?:[-_][a-z0-9]+)*$/.test(value)
                                        ? undefined
                                        : 'Lower-case letters, numbers, dashes and underscores.',
                            }}
                        >
                            {(field) => (
                                <field.TextField
                                    type='text'
                                    label='Key'
                                    description='Saved on every subscription that buys it; keep it stable.'
                                    autoComplete='off'
                                />
                            )}
                        </form.AppField>
                    </div>
                    <form.AppField name='description'>
                        {(field) => <field.TextAreaField label='Description' rows={2} />}
                    </form.AppField>
                    <div className='grid gap-5 sm:grid-cols-2'>
                        <form.AppField name='resource'>
                            {(field) => <field.SelectField label='Adds' options={resourceOptions} />}
                        </form.AppField>
                        <form.AppField
                            name='amount'
                            validators={{
                                onChange: ({ value }) => (value === null || value < 1 ? 'At least 1.' : undefined),
                            }}
                        >
                            {(field) => <field.NumberField label={amountLabel[preview.resource]} min={1} />}
                        </form.AppField>
                        <form.AppField
                            name='price_tokens'
                            validators={{
                                onChange: ({ value }) => (value === null || value < 0 ? 'Required.' : undefined),
                            }}
                        >
                            {(field) => (
                                <field.NumberField
                                    label='Price per unit (tokens / 30 days)'
                                    description='Scaled by the billing period, not by node multipliers.'
                                    min={0}
                                />
                            )}
                        </form.AppField>
                        <form.AppField
                            name='max_units'
                            validators={{
                                onChange: ({ value }) =>
                                    value === null || value < 1 || value > 100 ? 'Between 1 and 100.' : undefined,
                            }}
                        >
                            {(field) => <field.NumberField label='Most units per server' min={1} max={100} />}
                        </form.AppField>
                    </div>
                    <AddonPreview values={preview} />
                    <form.AppField name='enabled'>
                        {(field) => (
                            <field.SwitchField
                                label='Offered at checkout'
                                description='The checkout hides add-ons when none is offered.'
                            />
                        )}
                    </form.AppField>
                </div>
                <div className='mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end'>
                    <Button.Text type='button' isSecondary onClick={onClose}>
                        Cancel
                    </Button.Text>
                    <form.AppForm>
                        <form.SubmitButton>{addon ? 'Save changes' : 'Create add-on'}</form.SubmitButton>
                    </form.AppForm>
                </div>
            </Form>
        </Dialog>
    );
}

function NewAddonButton() {
    return (
        <Dialog.Trigger trigger={({ onClick }) => <NewButton onClick={onClick}>New add-on</NewButton>}>
            {({ open, onClose }) => <AddonFormDialog open={open} onClose={onClose} />}
        </Dialog.Trigger>
    );
}

function AddonRow({ addon }: { addon: ShopAddon }) {
    const remove = useDeleteShopAddon();

    return (
        <li className='flex flex-col gap-2 py-3 sm:flex-row sm:items-center'>
            <div className='min-w-0 flex-1'>
                <div className='flex flex-wrap items-center gap-2'>
                    <p className='font-medium text-foreground'>{addon.name}</p>
                    <Badge tone='neutral' variant='soft' size='sm'>
                        {formatAddonAmount(addon.resource, addon.amount)}
                    </Badge>
                    {!addon.enabled && (
                        <Badge tone='warning' variant='soft' size='sm'>
                            Disabled
                        </Badge>
                    )}
                </div>
                <p className='mt-1 text-xs text-muted-foreground'>
                    <code>{addon.key}</code> · up to {addon.max_units} per server
                    {addon.description ? ` · ${addon.description}` : ''}
                </p>
            </div>
            <p className='shrink-0 text-sm tabular-nums text-foreground'>
                {formatTokens(addon.price_tokens)}
                <span className='text-muted-foreground'> / unit / 30 days</span>
            </p>
            <RowActions>
                <Dialog.Trigger
                    trigger={({ onClick }) => <EditAction aria-label={`Edit ${addon.name}`} onClick={onClick} />}
                >
                    {({ open, onClose }) => <AddonFormDialog open={open} onClose={onClose} addon={addon} />}
                </Dialog.Trigger>
                <Dialog.ConfirmTrigger
                    title='Delete add-on'
                    confirm='Delete add-on'
                    onConfirmed={async (_event, close) => {
                        try {
                            await remove.mutateAsync(addon.id);
                            close();
                        } catch {
                            // Error toast is handled by the mutation.
                        }
                    }}
                    trigger={({ onClick }) => <DeleteAction aria-label={`Delete ${addon.name}`} onClick={onClick} />}
                >
                    <p className='text-sm leading-relaxed text-muted-foreground'>
                        <strong className='text-foreground'>{addon.name}</strong> is no longer offered. Servers that
                        bought it keep it.
                    </p>
                </Dialog.ConfirmTrigger>
            </RowActions>
        </li>
    );
}

/** Optional extras players can add at checkout, priced per unit per 30 days. */
export default function AddonsTab() {
    const { data, error, refetch } = useAdminShopAddons();

    if (error) {
        return <ServerError message={httpErrorToHuman(error)} onRetry={() => void refetch()} />;
    }

    if (!data) {
        return <Spinner size='large' centered />;
    }

    if (data.length === 0) {
        return (
            <Empty>
                <EmptyHeader>
                    <EmptyMedia variant='icon'>
                        <PackagePlus />
                    </EmptyMedia>
                    <EmptyTitle>No add-ons</EmptyTitle>
                    <EmptyDescription>
                        Offer extras such as +1 backup or +1 GB memory. Without any, the checkout skips this step.
                    </EmptyDescription>
                </EmptyHeader>
                <EmptyContent>
                    <NewAddonButton />
                </EmptyContent>
            </Empty>
        );
    }

    return (
        <Card title='Add-ons' actions={<NewAddonButton />}>
            <ul className='divide-y divide-border'>
                {data.map((addon) => (
                    <AddonRow key={addon.id} addon={addon} />
                ))}
            </ul>
        </Card>
    );
}
