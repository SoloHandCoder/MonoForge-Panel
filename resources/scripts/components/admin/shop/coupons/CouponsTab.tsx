import { useState } from 'react';
import { useStore } from '@tanstack/react-form';
import { TicketPercent } from 'lucide-react';
import {
    couponTypeLabels,
    couponValueLabel,
    useAdminCoupons,
    useCouponRedemptions,
    useCreateCoupon,
    useDeleteCoupon,
    useUpdateCoupon,
    type AdminCoupon,
    type AdminCouponInput,
    type CouponType,
} from '@/api/admin/shop/coupons';
import { useAdminShopCategories, useAdminShopPlans } from '@/api/admin/shop/queries';
import { httpErrorToHuman } from '@/api/http';
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
import { TextInput } from '@/components/form/controls';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { formatDate } from '@/lib/dates';

const CODE_PATTERN = /^[A-Z0-9_-]{3,32}$/;

const valueLabels = {
    percent: 'Percent off',
    fixed: 'Tokens off',
    tokens: 'Tokens credited',
} satisfies Record<CouponType, string>;

const typeOptions = (['percent', 'fixed', 'tokens'] as const).map((type) => ({
    value: type,
    label: couponTypeLabels[type],
}));

/** `datetime-local` inputs hold local wall time without a zone; the API takes ISO 8601. */
const toLocalInput = (iso: string | null): string => (iso ? formatDate(iso, 'YYYY-MM-DDTHH:mm') : '');

const toIso = (local: string): string | null => {
    if (!local) {
        return null;
    }

    const date = new Date(local);

    return Number.isNaN(date.getTime()) ? null : date.toISOString();
};

export interface CouponFormValues {
    code: string;
    type: CouponType;
    value: number | null;
    description: string;
    maxRedemptions: number | null;
    validFrom: string;
    validUntil: string;
    planIds: (string | number)[];
    categoryIds: (string | number)[];
    newCustomersOnly: boolean;
    enabled: boolean;
}

const NEW_COUPON: CouponFormValues = {
    code: '',
    type: 'percent',
    value: 10,
    description: '',
    maxRedemptions: null,
    validFrom: '',
    validUntil: '',
    planIds: [],
    categoryIds: [],
    newCustomersOnly: false,
    enabled: true,
};

export const couponFormValues = (coupon?: AdminCoupon): CouponFormValues =>
    coupon
        ? {
              code: coupon.code,
              type: coupon.type,
              value: coupon.value,
              description: coupon.description ?? '',
              maxRedemptions: coupon.max_redemptions,
              validFrom: toLocalInput(coupon.valid_from),
              validUntil: toLocalInput(coupon.valid_until),
              planIds: coupon.plan_ids,
              categoryIds: coupon.category_ids,
              newCustomersOnly: coupon.new_customers_only,
              enabled: coupon.enabled,
          }
        : NEW_COUPON;

/** The form as the API takes it: an upper-case code, ISO dates, and no plan limits on a free-tokens coupon. */
export const couponRequest = (values: CouponFormValues): AdminCouponInput => {
    const discount = values.type !== 'tokens';

    return {
        code: values.code.trim().toUpperCase(),
        type: values.type,
        value: values.value ?? 1,
        description: values.description.trim() || null,
        max_redemptions: values.maxRedemptions && values.maxRedemptions > 0 ? values.maxRedemptions : null,
        valid_from: toIso(values.validFrom),
        valid_until: toIso(values.validUntil),
        plan_ids: discount ? values.planIds.map(Number) : [],
        category_ids: discount ? values.categoryIds.map(Number) : [],
        new_customers_only: values.newCustomersOnly,
        enabled: values.enabled,
    };
};

export const validateCouponValue = (type: CouponType, value: number | null): string | undefined => {
    if (value === null || value < 1) {
        return 'At least 1.';
    }

    if (type === 'percent' && value > 100) {
        return 'At most 100%.';
    }

    return type === 'tokens' && value > 1_000_000 ? 'At most 1,000,000 tokens.' : undefined;
};

function CouponFormDialog({ open, onClose, coupon }: { open: boolean; onClose: () => void; coupon?: AdminCoupon }) {
    const create = useCreateCoupon();
    const update = useUpdateCoupon();
    const plans = useAdminShopPlans();
    const categories = useAdminShopCategories();
    const used = (coupon?.redemptions_count ?? 0) > 0;
    const form = useAppForm({
        defaultValues: couponFormValues(coupon),
        onSubmit: async ({ value }) => {
            try {
                const body = couponRequest(value);

                await (coupon
                    ? update.mutateAsync({ path: { coupon_id: coupon.id }, body })
                    : create.mutateAsync({ body }));
                onClose();
            } catch {
                // Error toast is handled by the mutation.
            }
        },
    });
    const isSubmitting = useStore(form.store, (state) => state.isSubmitting);
    const type = useStore(form.store, (state) => state.values.type);

    return (
        <Dialog
            open={open}
            title={coupon ? `Edit coupon ${coupon.code}` : 'New coupon'}
            preventExternalClose={isSubmitting}
            hideCloseIcon={isSubmitting}
            onClose={onClose}
        >
            <SpinnerOverlay visible={isSubmitting} />
            <Form form={form} className='m-0'>
                <div className='grid gap-5'>
                    <div className='grid gap-5 sm:grid-cols-2'>
                        <form.AppField
                            name='code'
                            validators={{
                                onChange: ({ value }) =>
                                    CODE_PATTERN.test(value.trim().toUpperCase())
                                        ? undefined
                                        : '3 to 32 letters, digits, - or _.',
                            }}
                        >
                            {(field) => (
                                <field.TextField
                                    type='text'
                                    label='Code'
                                    placeholder='WELCOME'
                                    autoComplete='off'
                                    description='Players can type it in any case.'
                                />
                            )}
                        </form.AppField>
                        <form.AppField name='type'>
                            {(field) => (
                                <field.SelectField
                                    label='Type'
                                    options={typeOptions}
                                    disabled={used}
                                    description={used ? 'Fixed once the coupon was used.' : undefined}
                                />
                            )}
                        </form.AppField>
                    </div>
                    <form.AppField
                        name='value'
                        validators={{
                            onChangeListenTo: ['type'],
                            onChange: ({ value, fieldApi }) =>
                                validateCouponValue(fieldApi.form.getFieldValue('type'), value),
                        }}
                    >
                        {(field) => (
                            <field.NumberField
                                label={valueLabels[type]}
                                description={
                                    type === 'tokens'
                                        ? 'Added to the wallet when redeemed there; once per account and network. Root administrators only.'
                                        : 'Off the first period of a store purchase; renewals are full price. At least 1 token is always charged.'
                                }
                                min={1}
                                max={type === 'percent' ? 100 : undefined}
                            />
                        )}
                    </form.AppField>
                    <div className='grid gap-5 sm:grid-cols-2'>
                        <form.AppField name='maxRedemptions'>
                            {(field) => (
                                <field.NumberField
                                    label='Redemptions in total'
                                    description='Empty is no limit. Each account can use a coupon once.'
                                    min={1}
                                />
                            )}
                        </form.AppField>
                        <form.AppField name='description'>
                            {(field) => <field.TextField type='text' label='Note' placeholder='Launch promo' />}
                        </form.AppField>
                    </div>
                    <div className='grid gap-5 sm:grid-cols-2'>
                        <form.AppField name='validFrom'>
                            {(field) => (
                                <field.TextField
                                    type='datetime-local'
                                    label='Valid from'
                                    description='Empty works at once.'
                                />
                            )}
                        </form.AppField>
                        <form.AppField
                            name='validUntil'
                            validators={{
                                onChangeListenTo: ['validFrom'],
                                onChange: ({ value, fieldApi }) => {
                                    const from = toIso(fieldApi.form.getFieldValue('validFrom'));
                                    const until = toIso(value);

                                    return from && until && until <= from ? 'Must be after the start.' : undefined;
                                },
                            }}
                        >
                            {(field) => (
                                <field.TextField
                                    type='datetime-local'
                                    label='Valid until'
                                    description='Empty never ends.'
                                />
                            )}
                        </form.AppField>
                    </div>
                    {type !== 'tokens' && (
                        <div className='grid gap-5 sm:grid-cols-2'>
                            <form.AppField name='planIds'>
                                {(field) => (
                                    <field.MultiSelectField
                                        label='Only these plans'
                                        placeholder='Every plan'
                                        options={(plans.data ?? []).map((plan) => ({
                                            value: plan.id,
                                            label: plan.name,
                                        }))}
                                    />
                                )}
                            </form.AppField>
                            <form.AppField name='categoryIds'>
                                {(field) => (
                                    <field.MultiSelectField
                                        label='Or these categories'
                                        placeholder='Every category'
                                        options={(categories.data ?? []).map((category) => ({
                                            value: category.id,
                                            label: category.name,
                                        }))}
                                    />
                                )}
                            </form.AppField>
                        </div>
                    )}
                    <form.AppField name='newCustomersOnly'>
                        {(field) => (
                            <field.SwitchField
                                label='New customers only'
                                description='Refused to anyone who bought tokens or a server before.'
                            />
                        )}
                    </form.AppField>
                    <form.AppField name='enabled'>
                        {(field) => (
                            <field.SwitchField label='Enabled' description='Disable it to stop new redemptions.' />
                        )}
                    </form.AppField>
                </div>
                <div className='mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end'>
                    <Button.Text type='button' isSecondary onClick={onClose}>
                        Cancel
                    </Button.Text>
                    <form.AppForm>
                        <form.SubmitButton>{coupon ? 'Save changes' : 'Create coupon'}</form.SubmitButton>
                    </form.AppForm>
                </div>
            </Form>
        </Dialog>
    );
}

function Pager({ page, lastPage, onPage }: { page: number; lastPage: number; onPage: (page: number) => void }) {
    if (lastPage <= 1) {
        return null;
    }

    return (
        <div className='mt-3 flex items-center justify-end gap-2 text-sm'>
            <Button.Text type='button' size='xsmall' disabled={page <= 1} onClick={() => onPage(page - 1)}>
                Previous
            </Button.Text>
            <span className='tabular-nums text-muted-foreground'>
                {page} / {lastPage}
            </span>
            <Button.Text type='button' size='xsmall' disabled={page >= lastPage} onClick={() => onPage(page + 1)}>
                Next
            </Button.Text>
        </div>
    );
}

/** The audit list: who used a coupon (or every coupon), what it was worth and on which server. */
function RedemptionsList({ coupon }: { coupon: AdminCoupon | null }) {
    const [page, setPage] = useState(1);
    const { data, error, refetch } = useCouponRedemptions(page, coupon?.id ?? null);

    if (error) {
        return <ServerError message={httpErrorToHuman(error)} onRetry={() => void refetch()} />;
    }

    if (!data) {
        return <Spinner size='large' centered />;
    }

    if (data.data.length === 0) {
        return <p className='text-sm text-muted-foreground'>No redemptions yet.</p>;
    }

    return (
        <div className='relative overflow-x-auto'>
            <table className='w-full min-w-[32rem] text-left text-sm'>
                <thead>
                    <tr className='text-xs text-muted-foreground'>
                        <th className='pb-2 font-medium'>When</th>
                        <th className='pb-2 font-medium'>Coupon</th>
                        <th className='pb-2 font-medium'>User</th>
                        <th className='pb-2 font-medium'>Tokens</th>
                        <th className='pb-2 font-medium'>Server</th>
                    </tr>
                </thead>
                <tbody>
                    {data.data.map((redemption) => (
                        <tr key={redemption.id} className='border-t border-border'>
                            <td className='py-2 pr-3 text-xs text-muted-foreground'>
                                {formatDate(redemption.created_at, 'MMM D, HH:mm')}
                            </td>
                            <td className='py-2 pr-3 font-mono text-xs'>{redemption.coupon.code}</td>
                            <td className='py-2 pr-3'>{redemption.user ? redemption.user.username : 'Deleted user'}</td>
                            <td className='py-2 pr-3 tabular-nums'>
                                {redemption.coupon.type === 'tokens' ? '+' : '−'}
                                {redemption.tokens.toLocaleString('en-US')}
                            </td>
                            <td className='py-2 text-xs text-muted-foreground'>{redemption.server?.name ?? '—'}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
            <Pager page={page} lastPage={data.meta.last_page} onPage={setPage} />
        </div>
    );
}

/** When a coupon works, in a few words. */
/** Whether a coupon works right now, and a word or two saying so. */
export interface CouponWindow {
    label: string;
    live: boolean;
}

export function couponWindow(coupon: AdminCoupon, now: Date = new Date()): CouponWindow {
    if (!coupon.enabled) {
        return { label: 'Disabled', live: false };
    }

    if (coupon.valid_from && new Date(coupon.valid_from) > now) {
        return { label: `From ${formatDate(coupon.valid_from, 'MMM D, YYYY')}`, live: false };
    }

    if (coupon.valid_until && new Date(coupon.valid_until) <= now) {
        return { label: 'Expired', live: false };
    }

    if (coupon.max_redemptions !== null && coupon.redemptions_count >= coupon.max_redemptions) {
        return { label: 'Used up', live: false };
    }

    return {
        label: coupon.valid_until ? `Until ${formatDate(coupon.valid_until, 'MMM D, YYYY')}` : 'Active',
        live: true,
    };
}

function CouponRow({ coupon }: { coupon: AdminCoupon }) {
    const remove = useDeleteCoupon();
    const status = couponWindow(coupon);
    const limits = [
        coupon.new_customers_only && 'new customers',
        (coupon.plan_ids.length > 0 || coupon.category_ids.length > 0) && 'some plans',
    ].filter(Boolean);

    return (
        <li className='flex flex-col gap-2 py-3 sm:flex-row sm:items-center'>
            <div className='min-w-0 flex-1'>
                <div className='flex flex-wrap items-center gap-2'>
                    <p className='font-mono font-medium text-foreground'>{coupon.code}</p>
                    <Badge tone={coupon.type === 'tokens' ? 'info' : 'success'} variant='soft' size='sm'>
                        {couponValueLabel(coupon.type, coupon.value)}
                    </Badge>
                    <Badge tone={status.live ? 'success' : 'neutral'} variant='soft' size='sm'>
                        {status.label}
                    </Badge>
                </div>
                <p className='mt-1 text-xs text-muted-foreground'>
                    {coupon.redemptions_count.toLocaleString('en-US')}
                    {coupon.max_redemptions === null ? '' : ` / ${coupon.max_redemptions.toLocaleString('en-US')}`} used
                    {limits.length > 0 && ` · ${limits.join(', ')}`}
                    {coupon.description && ` · ${coupon.description}`}
                </p>
            </div>
            <RowActions>
                <Dialog.Trigger
                    trigger={({ onClick }) => (
                        <Button.Text type='button' size='xsmall' onClick={onClick}>
                            Redemptions
                        </Button.Text>
                    )}
                >
                    {({ open, onClose }) => (
                        <Dialog open={open} onClose={onClose} title={`Redemptions of ${coupon.code}`}>
                            <RedemptionsList coupon={coupon} />
                        </Dialog>
                    )}
                </Dialog.Trigger>
                <Dialog.Trigger
                    trigger={({ onClick }) => <EditAction aria-label={`Edit ${coupon.code}`} onClick={onClick} />}
                >
                    {({ open, onClose }) => <CouponFormDialog open={open} onClose={onClose} coupon={coupon} />}
                </Dialog.Trigger>
                {coupon.redemptions_count === 0 && (
                    <Dialog.ConfirmTrigger
                        title='Delete coupon'
                        confirm='Delete coupon'
                        onConfirmed={async (_event, close) => {
                            try {
                                await remove.mutateAsync({ path: { coupon_id: coupon.id } });
                                close();
                            } catch {
                                // Error toast is handled by the mutation.
                            }
                        }}
                        trigger={({ onClick }) => (
                            <DeleteAction aria-label={`Delete ${coupon.code}`} onClick={onClick} />
                        )}
                    >
                        <p className='text-sm leading-relaxed text-muted-foreground'>
                            Players can no longer use{' '}
                            <strong className='font-mono text-foreground'>{coupon.code}</strong>. A coupon that was used
                            is kept for its audit trail; disable it instead.
                        </p>
                    </Dialog.ConfirmTrigger>
                )}
            </RowActions>
        </li>
    );
}

function NewCouponButton() {
    return (
        <Dialog.Trigger trigger={({ onClick }) => <NewButton onClick={onClick}>New coupon</NewButton>}>
            {({ open, onClose }) => <CouponFormDialog open={open} onClose={onClose} />}
        </Dialog.Trigger>
    );
}

/** Shop → Coupons: discount and free-tokens codes, and the audit list of their redemptions. */
export default function CouponsTab() {
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState('');
    const { data, error, refetch } = useAdminCoupons(page, search);

    if (error) {
        return <ServerError message={httpErrorToHuman(error)} onRetry={() => void refetch()} />;
    }

    if (!data) {
        return <Spinner size='large' centered />;
    }

    if (data.meta.total === 0 && search === '') {
        return (
            <Empty>
                <EmptyHeader>
                    <EmptyMedia variant='icon'>
                        <TicketPercent />
                    </EmptyMedia>
                    <EmptyTitle>No coupons yet</EmptyTitle>
                    <EmptyDescription>
                        A coupon takes a percentage or tokens off the first period of a server, or adds free tokens to a
                        wallet.
                    </EmptyDescription>
                </EmptyHeader>
                <EmptyContent>
                    <NewCouponButton />
                </EmptyContent>
            </Empty>
        );
    }

    return (
        <div className='grid gap-6'>
            <Card title='Coupons' actions={<NewCouponButton />}>
                <label htmlFor='coupon-search' className='sr-only'>
                    Search coupons
                </label>
                <TextInput
                    id='coupon-search'
                    value={search}
                    placeholder='Search by code'
                    autoComplete='off'
                    onChange={(event) => {
                        setSearch(event.currentTarget.value);
                        setPage(1);
                    }}
                />
                {data.data.length === 0 ? (
                    <p className='mt-4 text-sm text-muted-foreground'>No coupon matches.</p>
                ) : (
                    <ul className='mt-2 divide-y divide-border'>
                        {data.data.map((coupon) => (
                            <CouponRow key={coupon.id} coupon={coupon} />
                        ))}
                    </ul>
                )}
                <Pager page={page} lastPage={data.meta.last_page} onPage={setPage} />
            </Card>
            <Card title='Latest redemptions'>
                <RedemptionsList coupon={null} />
            </Card>
        </div>
    );
}
