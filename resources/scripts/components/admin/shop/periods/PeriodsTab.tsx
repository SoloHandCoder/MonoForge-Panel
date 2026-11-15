import { useStore } from '@tanstack/react-form';
import { CalendarClock } from 'lucide-react';
import { httpErrorToHuman } from '@/api/http';
import {
    useAdminShopPeriods,
    useDeleteShopPeriod,
    useSaveShopPeriod,
    type ShopPeriod,
    type ShopPeriodInput,
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
import { formatTokens, modifierLabel, periodTotal, perThirtyDays, type PricingPeriod } from '@/lib/shopPricing';

/** The example plan price the previews use. */
const SAMPLE_PRICE = 500;

const modifierTone = (percent: number) => {
    if (percent < 0) {
        return 'success';
    }

    return percent > 0 ? 'warning' : 'neutral';
};

function PeriodExample({ period }: { period: PricingPeriod }) {
    const total = periodTotal(SAMPLE_PRICE, period);

    return (
        <span className='tabular-nums'>
            {formatTokens(total)} every {period.days} {period.days === 1 ? 'day' : 'days'}
            <span className='text-muted-foreground'> · {formatTokens(perThirtyDays(total, period))} / 30 days</span>
        </span>
    );
}

interface PeriodFormValues {
    name: string;
    days: number | null;
    price_modifier_percent: number | null;
    sort: number | null;
    enabled: boolean;
}

function PeriodFormDialog({ open, onClose, period }: { open: boolean; onClose: () => void; period?: ShopPeriod }) {
    const save = useSaveShopPeriod();
    const defaultValues: PeriodFormValues = {
        name: period?.name ?? '',
        days: period?.days ?? 30,
        price_modifier_percent: period?.price_modifier_percent ?? 0,
        sort: period?.sort ?? 0,
        enabled: period?.enabled ?? true,
    };
    const form = useAppForm({
        defaultValues,
        onSubmit: async ({ value }) => {
            const values: ShopPeriodInput = {
                name: value.name.trim(),
                days: value.days ?? 30,
                price_modifier_percent: value.price_modifier_percent ?? 0,
                sort: value.sort ?? 0,
                enabled: value.enabled,
            };

            try {
                await save.mutateAsync({ id: period?.id, values });
                onClose();
            } catch {
                // Error toast is handled by the mutation.
            }
        },
    });
    const isSubmitting = useStore(form.store, (state) => state.isSubmitting);
    const preview = useStore(form.store, (state) => ({
        days: Math.max(1, state.values.days ?? 1),
        price_modifier_percent: state.values.price_modifier_percent ?? 0,
    }));

    return (
        <Dialog
            open={open}
            title={period ? 'Edit billing period' : 'New billing period'}
            preventExternalClose={isSubmitting}
            hideCloseIcon={isSubmitting}
            onClose={onClose}
        >
            <SpinnerOverlay visible={isSubmitting} />
            <Form form={form} className='m-0'>
                <div className='grid gap-5'>
                    <form.AppField
                        name='name'
                        validators={{ onChange: ({ value }) => (value.trim() ? undefined : 'Name is required.') }}
                    >
                        {(field) => (
                            <field.TextField type='text' label='Name' placeholder='Quarterly' autoComplete='off' />
                        )}
                    </form.AppField>
                    <div className='grid gap-5 sm:grid-cols-2'>
                        <form.AppField
                            name='days'
                            validators={{
                                onChange: ({ value }) => (value === null || value < 1 ? 'At least 1 day.' : undefined),
                            }}
                        >
                            {(field) => <field.NumberField label='Days' min={1} max={3650} />}
                        </form.AppField>
                        <form.AppField
                            name='price_modifier_percent'
                            validators={{
                                onChange: ({ value }) =>
                                    value === null || value < -90 || value > 500 ? 'Between -90 and 500.' : undefined,
                            }}
                        >
                            {(field) => (
                                <field.NumberField
                                    label='Price modifier (%)'
                                    description='-20 is 20% off, 10 a 10% surcharge.'
                                    min={-90}
                                    max={500}
                                />
                            )}
                        </form.AppField>
                    </div>
                    <div className='rounded-md border border-border bg-muted/40 p-3 text-sm'>
                        <p className='text-xs uppercase tracking-wide text-muted-foreground'>
                            A {formatTokens(SAMPLE_PRICE)} plan costs
                        </p>
                        <p className='mt-1 text-foreground'>
                            <PeriodExample period={preview} />
                        </p>
                        <p className='mt-1 text-xs text-muted-foreground'>
                            {modifierLabel(preview.price_modifier_percent)}
                        </p>
                    </div>
                    <form.AppField name='sort'>
                        {(field) => (
                            <field.NumberField label='Order' description='Lower comes first in the store.' min={0} />
                        )}
                    </form.AppField>
                    <form.AppField name='enabled'>
                        {(field) => (
                            <field.SwitchField
                                label='Offered at checkout'
                                description='Servers already on this period keep renewing on it.'
                            />
                        )}
                    </form.AppField>
                </div>
                <div className='mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end'>
                    <Button.Text type='button' isSecondary onClick={onClose}>
                        Cancel
                    </Button.Text>
                    <form.AppForm>
                        <form.SubmitButton>{period ? 'Save changes' : 'Create period'}</form.SubmitButton>
                    </form.AppForm>
                </div>
            </Form>
        </Dialog>
    );
}

function NewPeriodButton() {
    return (
        <Dialog.Trigger trigger={({ onClick }) => <NewButton onClick={onClick}>New billing period</NewButton>}>
            {({ open, onClose }) => <PeriodFormDialog open={open} onClose={onClose} />}
        </Dialog.Trigger>
    );
}

function PeriodRow({ period }: { period: ShopPeriod }) {
    const remove = useDeleteShopPeriod();

    return (
        <li className='flex flex-col gap-2 py-3 sm:flex-row sm:items-center'>
            <div className='min-w-0 flex-1'>
                <div className='flex flex-wrap items-center gap-2'>
                    <p className='font-medium text-foreground'>{period.name}</p>
                    <Badge tone={modifierTone(period.price_modifier_percent)} variant='soft' size='sm'>
                        {modifierLabel(period.price_modifier_percent)}
                    </Badge>
                    {!period.enabled && (
                        <Badge tone='neutral' variant='soft' size='sm'>
                            Disabled
                        </Badge>
                    )}
                </div>
                <p className='mt-1 text-xs text-muted-foreground'>
                    {formatTokens(SAMPLE_PRICE)} plan: <PeriodExample period={period} />
                </p>
            </div>
            <RowActions>
                <Dialog.Trigger
                    trigger={({ onClick }) => <EditAction aria-label={`Edit ${period.name}`} onClick={onClick} />}
                >
                    {({ open, onClose }) => <PeriodFormDialog open={open} onClose={onClose} period={period} />}
                </Dialog.Trigger>
                <Dialog.ConfirmTrigger
                    title='Delete billing period'
                    confirm='Delete period'
                    onConfirmed={async (_event, close) => {
                        try {
                            await remove.mutateAsync(period.id);
                            close();
                        } catch {
                            // Error toast is handled by the mutation.
                        }
                    }}
                    trigger={({ onClick }) => <DeleteAction aria-label={`Delete ${period.name}`} onClick={onClick} />}
                >
                    <p className='text-sm leading-relaxed text-muted-foreground'>
                        Players can no longer pick <strong className='text-foreground'>{period.name}</strong>. Servers
                        already on it keep renewing every {period.days} days. To pause it instead, disable it.
                    </p>
                </Dialog.ConfirmTrigger>
            </RowActions>
        </li>
    );
}

/** Billing periods players pick at checkout, with what an example plan costs on each. */
export default function PeriodsTab() {
    const { data, error, refetch } = useAdminShopPeriods();

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
                        <CalendarClock />
                    </EmptyMedia>
                    <EmptyTitle>No billing periods yet</EmptyTitle>
                    <EmptyDescription>
                        Add at least one, such as Monthly (30 days). Longer periods can carry a discount.
                    </EmptyDescription>
                </EmptyHeader>
                <EmptyContent>
                    <NewPeriodButton />
                </EmptyContent>
            </Empty>
        );
    }

    return (
        <Card title='Billing periods' actions={<NewPeriodButton />}>
            <ul className='divide-y divide-border'>
                {data.map((period) => (
                    <PeriodRow key={period.id} period={period} />
                ))}
            </ul>
        </Card>
    );
}
