import { useStore } from '@tanstack/react-form';
import { Scale } from 'lucide-react';
import { httpErrorToHuman } from '@/api/http';
import {
    useAdminShopMultipliers,
    useAdminShopOptions,
    useDeleteShopMultiplier,
    useSaveShopMultiplier,
    type ShopMultiplier,
    type ShopMultiplierScope,
    type ShopOptions,
} from '@/api/admin/shop/queries';
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
import { formatMultiplier, formatTokens } from '@/lib/shopPricing';

const SAMPLE_PRICE = 500;

const scopeOptions = [
    { value: 'location', label: 'Location' },
    { value: 'node', label: 'Node' },
];

const targetName = (options: ShopOptions, scope: ShopMultiplierScope, id: number): string => {
    if (scope === 'node') {
        return options.nodes.find((node) => node.id === id)?.name ?? `Node #${id}`;
    }

    const location = options.locations.find((item) => item.id === id);

    if (!location) {
        return `Location #${id}`;
    }

    return location.long ? `${location.short} · ${location.long}` : location.short;
};

interface MultiplierFormValues {
    scope: ShopMultiplierScope;
    scope_id: number | null;
    multiplier: number | null;
    label: string;
}

interface FormProps {
    open: boolean;
    onClose: () => void;
    options: ShopOptions;
    taken: ShopMultiplier[];
    multiplier?: ShopMultiplier;
}

function MultiplierFormDialog({ open, onClose, options, taken, multiplier }: FormProps) {
    const save = useSaveShopMultiplier();
    const defaultValues: MultiplierFormValues = {
        scope: multiplier?.scope ?? 'location',
        scope_id: multiplier?.scope_id ?? null,
        multiplier: multiplier?.multiplier ?? 1.5,
        label: multiplier?.label ?? '',
    };
    const form = useAppForm({
        defaultValues,
        onSubmit: async ({ value }) => {
            try {
                await save.mutateAsync({
                    id: multiplier?.id,
                    values: {
                        scope: value.scope,
                        scope_id: value.scope_id ?? 0,
                        multiplier: value.multiplier ?? 1,
                        label: value.label.trim() || null,
                    },
                });
                onClose();
            } catch {
                // Error toast is handled by the mutation.
            }
        },
    });
    const isSubmitting = useStore(form.store, (state) => state.isSubmitting);
    const scope = useStore(form.store, (state) => state.values.scope);
    const factor = useStore(form.store, (state) => state.values.multiplier ?? 1);

    // Each location and node takes one multiplier, so the ones already set are not offered again.
    const isTaken = (id: number) =>
        taken.some((item) => item.scope === scope && item.scope_id === id && item.id !== multiplier?.id);
    const targets =
        scope === 'node'
            ? options.nodes.map((node) => ({ value: node.id, label: node.name, disabled: isTaken(node.id) }))
            : options.locations.map((location) => ({
                  value: location.id,
                  label: targetName(options, 'location', location.id),
                  disabled: isTaken(location.id),
              }));

    return (
        <Dialog
            open={open}
            title={multiplier ? 'Edit price multiplier' : 'New price multiplier'}
            preventExternalClose={isSubmitting}
            hideCloseIcon={isSubmitting}
            onClose={onClose}
        >
            <SpinnerOverlay visible={isSubmitting} />
            <Form form={form} className='m-0'>
                <div className='grid gap-5'>
                    <form.AppField name='scope' listeners={{ onChange: () => form.setFieldValue('scope_id', null) }}>
                        {(field) => (
                            <field.SelectField
                                label='Applies to'
                                description='A node multiplier wins over the multiplier of its location.'
                                options={scopeOptions}
                            />
                        )}
                    </form.AppField>
                    <form.AppField
                        name='scope_id'
                        validators={{ onChange: ({ value }) => (value === null ? 'Pick one.' : undefined) }}
                    >
                        {(field) => (
                            <field.SelectField
                                label={scope === 'node' ? 'Node' : 'Location'}
                                options={targets}
                                placeholder={scope === 'node' ? 'Pick a node' : 'Pick a location'}
                            />
                        )}
                    </form.AppField>
                    <form.AppField
                        name='multiplier'
                        validators={{
                            onChange: ({ value }) =>
                                value === null || value <= 0 || value > 999.99 ? 'Between 0.01 and 999.99.' : undefined,
                        }}
                    >
                        {(field) => (
                            <field.NumberField
                                label='Multiplier'
                                description='1 is the plan price, 1.5 is 50% more, 0.8 is 20% less.'
                                min={0.01}
                                max={999.99}
                                format={{ maximumFractionDigits: 2 }}
                            />
                        )}
                    </form.AppField>
                    <p className='rounded-md border border-border bg-muted/40 p-3 text-sm text-foreground'>
                        A {formatTokens(SAMPLE_PRICE)} plan costs{' '}
                        <strong className='tabular-nums'>
                            {formatTokens(Math.max(1, Math.ceil(SAMPLE_PRICE * factor - 1e-6)))}
                        </strong>{' '}
                        a month here.
                    </p>
                    <form.AppField name='label'>
                        {(field) => (
                            <field.TextField
                                type='text'
                                label='Label'
                                description='Shown to players next to the price, such as "Pro hardware".'
                                autoComplete='off'
                            />
                        )}
                    </form.AppField>
                </div>
                <div className='mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end'>
                    <Button.Text type='button' isSecondary onClick={onClose}>
                        Cancel
                    </Button.Text>
                    <form.AppForm>
                        <form.SubmitButton>{multiplier ? 'Save changes' : 'Create multiplier'}</form.SubmitButton>
                    </form.AppForm>
                </div>
            </Form>
        </Dialog>
    );
}

function NewMultiplierButton({ options, taken }: { options: ShopOptions; taken: ShopMultiplier[] }) {
    return (
        <Dialog.Trigger trigger={({ onClick }) => <NewButton onClick={onClick}>New multiplier</NewButton>}>
            {({ open, onClose }) => (
                <MultiplierFormDialog open={open} onClose={onClose} options={options} taken={taken} />
            )}
        </Dialog.Trigger>
    );
}

function MultiplierRow({
    multiplier,
    options,
    taken,
}: {
    multiplier: ShopMultiplier;
    options: ShopOptions;
    taken: ShopMultiplier[];
}) {
    const remove = useDeleteShopMultiplier();
    const name = targetName(options, multiplier.scope, multiplier.scope_id);

    return (
        <li className='flex flex-col gap-2 py-3 sm:flex-row sm:items-center'>
            <div className='min-w-0 flex-1'>
                <div className='flex flex-wrap items-center gap-2'>
                    <p className='font-medium text-foreground'>{name}</p>
                    <Badge tone={multiplier.scope === 'node' ? 'info' : 'neutral'} variant='soft' size='sm'>
                        {multiplier.scope === 'node' ? 'Node' : 'Location'}
                    </Badge>
                    {multiplier.label && <span className='text-xs text-muted-foreground'>{multiplier.label}</span>}
                </div>
                <p className='mt-1 text-xs text-muted-foreground tabular-nums'>
                    {formatTokens(SAMPLE_PRICE)} plan →{' '}
                    {formatTokens(Math.max(1, Math.ceil(SAMPLE_PRICE * multiplier.multiplier - 1e-6)))} / month
                </p>
            </div>
            <span className='shrink-0 text-lg font-semibold tabular-nums text-foreground'>
                {formatMultiplier(multiplier.multiplier)}
            </span>
            <RowActions>
                <Dialog.Trigger
                    trigger={({ onClick }) => (
                        <EditAction aria-label={`Edit the ${name} multiplier`} onClick={onClick} />
                    )}
                >
                    {({ open, onClose }) => (
                        <MultiplierFormDialog
                            open={open}
                            onClose={onClose}
                            options={options}
                            taken={taken}
                            multiplier={multiplier}
                        />
                    )}
                </Dialog.Trigger>
                <Dialog.ConfirmTrigger
                    title='Delete price multiplier'
                    confirm='Delete multiplier'
                    onConfirmed={async (_event, close) => {
                        try {
                            await remove.mutateAsync(multiplier.id);
                            close();
                        } catch {
                            // Error toast is handled by the mutation.
                        }
                    }}
                    trigger={({ onClick }) => (
                        <DeleteAction aria-label={`Delete the ${name} multiplier`} onClick={onClick} />
                    )}
                >
                    <p className='text-sm leading-relaxed text-muted-foreground'>
                        New servers on <strong className='text-foreground'>{name}</strong> go back to the plan price
                        {multiplier.scope === 'node' ? " (or its location's multiplier)" : ''}. Servers already bought
                        keep their price.
                    </p>
                </Dialog.ConfirmTrigger>
            </RowActions>
        </li>
    );
}

/** Location and node price multipliers, such as 1x on home hardware and 1.5x on a faster node. */
export default function MultipliersTab() {
    const multipliers = useAdminShopMultipliers();
    const options = useAdminShopOptions();

    const error = multipliers.error ?? options.error;

    if (error) {
        return (
            <ServerError
                message={httpErrorToHuman(error)}
                onRetry={() => {
                    void multipliers.refetch();
                    void options.refetch();
                }}
            />
        );
    }

    if (!multipliers.data || !options.data) {
        return <Spinner size='large' centered />;
    }

    const list = multipliers.data;

    if (list.length === 0) {
        return (
            <Empty>
                <EmptyHeader>
                    <EmptyMedia variant='icon'>
                        <Scale />
                    </EmptyMedia>
                    <EmptyTitle>Every node sells at 1×</EmptyTitle>
                    <EmptyDescription>
                        Add a multiplier to charge more (or less) on a location or a node, such as 1.5× on pro hardware.
                    </EmptyDescription>
                </EmptyHeader>
                <EmptyContent>
                    <NewMultiplierButton options={options.data} taken={list} />
                </EmptyContent>
            </Empty>
        );
    }

    return (
        <Card title='Price multipliers' actions={<NewMultiplierButton options={options.data} taken={list} />}>
            <p className='mb-2 text-sm text-muted-foreground'>
                Plans cost their price × the node&apos;s multiplier, else its location&apos;s, else 1×. Add-ons are not
                multiplied.
            </p>
            <ul className='divide-y divide-border'>
                {list.map((multiplier) => (
                    <MultiplierRow key={multiplier.id} multiplier={multiplier} options={options.data} taken={list} />
                ))}
            </ul>
        </Card>
    );
}
