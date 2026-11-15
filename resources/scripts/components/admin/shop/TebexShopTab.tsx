import { getCoreRowModel, useReactTable, type ColumnDef } from '@tanstack/react-table';
import { Coins } from 'lucide-react';
import { useState } from 'react';
import {
    useAdminTebexPayments,
    useAdminTebexSettings,
    useAdminTokenPacks,
    useDeleteAdminTokenPack,
    useUpdateAdminTebexSettings,
    type AdminTokenPack,
    type TebexPayment,
    type TebexPaymentStatus,
    type TebexSettings,
} from '@/api/admin/shop/tebex';
import { httpErrorToHuman } from '@/api/http';
import AdminContentBlock from '@/components/admin/AdminContentBlock';
import AdminListEmpty from '@/components/admin/AdminListEmpty';
import TokenPackFormDialog from '@/components/admin/shop/TokenPackFormDialog';
import Badge from '@/components/elements/Badge';
import Button from '@/components/elements/Button';
import Code from '@/components/elements/Code';
import CopyOnClick from '@/components/elements/CopyOnClick';
import { Dialog } from '@/components/elements/dialog';
import { NewButton } from '@/components/elements/NewButton';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import TitledGreyBox from '@/components/elements/TitledGreyBox';
import DataTable from '@/components/elements/table/DataTable';
import { actionsColumn, DeleteAction, EditAction, RowActions } from '@/components/elements/table/RowActions';
import { Form, useAppForm } from '@/components/form';
import { formatDate } from '@/lib/dates';
import type { UiTone } from '@/themes/primitives';

const emptyPacks: AdminTokenPack[] = [];
// The pack list has no filter to clear.
const noop = () => {};

const parseIps = (value: string): string[] =>
    value
        .split(/[\s,]+/)
        .map((ip) => ip.trim())
        .filter((ip) => ip !== '');

function TebexSettingsForm({ settings }: { settings: TebexSettings }) {
    const update = useUpdateAdminTebexSettings();
    const usesDefaultIps = settings.webhook_ips.join(',') === settings.default_webhook_ips.join(',');
    const form = useAppForm({
        defaultValues: {
            publicToken: settings.public_token,
            privateKey: '',
            webhookSecret: '',
            webhookIps: usesDefaultIps ? '' : settings.webhook_ips.join('\n'),
        },
        onSubmit: async ({ value }) => {
            try {
                await update.mutateAsync({
                    public_token: value.publicToken.trim(),
                    private_key: value.privateKey,
                    webhook_secret: value.webhookSecret,
                    webhook_ips: parseIps(value.webhookIps),
                });
                form.reset({ ...value, privateKey: '', webhookSecret: '' });
            } catch {
                // Error toast is handled by the mutation.
            }
        },
    });

    return (
        <TitledGreyBox title='Tebex Headless store'>
            <Form form={form} className='m-0'>
                <div className='grid gap-6'>
                    <form.AppField name='publicToken'>
                        {(field) => (
                            <field.TextField
                                type='text'
                                id='tebex_public_token'
                                label='Public token'
                                description='Tebex creator panel → Integrations → API keys. Leave blank to turn the token store off.'
                                autoComplete='off'
                            />
                        )}
                    </form.AppField>
                    <form.AppField name='privateKey'>
                        {(field) => (
                            <field.TextField
                                type='password'
                                id='tebex_private_key'
                                label='Private key'
                                description={
                                    settings.private_key_set
                                        ? 'A key is stored. Leave blank to keep it.'
                                        : 'No key is stored yet. Optional for buying packs.'
                                }
                                autoComplete='new-password'
                            />
                        )}
                    </form.AppField>
                    <form.AppField name='webhookSecret'>
                        {(field) => (
                            <field.TextField
                                type='password'
                                id='tebex_webhook_secret'
                                label='Webhook secret'
                                description={
                                    settings.webhook_secret_set
                                        ? 'A secret is stored. Leave blank to keep it.'
                                        : 'Required: no tokens are credited until it is set. Find it under Developers → Webhooks.'
                                }
                                autoComplete='new-password'
                            />
                        )}
                    </form.AppField>
                    <form.AppField name='webhookIps'>
                        {(field) => (
                            <field.TextAreaField
                                label='Allowed webhook addresses'
                                description={`One per line. Leave blank for the addresses Tebex documents (${settings.default_webhook_ips.join(', ')}).`}
                                rows={3}
                            />
                        )}
                    </form.AppField>
                    <div className='text-sm'>
                        <p className='text-muted-foreground'>
                            Webhook URL to add under Developers → Webhooks → Endpoints, with the payment events
                            (completed, refunded, dispute opened, won and lost):
                        </p>
                        <div className='mt-2'>
                            <CopyOnClick text={settings.webhook_url}>
                                <Code className='break-all'>{settings.webhook_url}</Code>
                            </CopyOnClick>
                        </div>
                        <p className='mt-3 text-muted-foreground'>
                            Tebex has no sandbox. To test, turn on Test Mode under Settings → Checkout in Tebex: its
                            test payments send real webhooks and really credit tokens. Turn it off before going live.
                        </p>
                    </div>
                </div>
                <div className='mt-6 flex justify-end'>
                    <form.AppForm>
                        <form.SubmitButton>Save Tebex settings</form.SubmitButton>
                    </form.AppForm>
                </div>
            </Form>
        </TitledGreyBox>
    );
}

function CreatePackButton() {
    return (
        <Dialog.Trigger trigger={({ onClick }) => <NewButton onClick={onClick}>New token pack</NewButton>}>
            {({ open, onClose }) => <TokenPackFormDialog open={open} onClose={onClose} />}
        </Dialog.Trigger>
    );
}

function PackActionsCell({ pack }: { pack: AdminTokenPack }) {
    const remove = useDeleteAdminTokenPack();

    return (
        <RowActions>
            <Dialog.Trigger
                trigger={({ onClick }) => <EditAction aria-label={`Edit ${pack.name}`} onClick={onClick} />}
            >
                {({ open, onClose }) => <TokenPackFormDialog pack={pack} open={open} onClose={onClose} />}
            </Dialog.Trigger>
            <Dialog.ConfirmTrigger
                title='Delete token pack'
                confirm='Delete token pack'
                onConfirmed={async (_event, close) => {
                    try {
                        await remove.mutateAsync(pack.id);
                        close();
                    } catch {
                        // Error toast is handled by the mutation.
                    }
                }}
                trigger={({ onClick }) => <DeleteAction aria-label={`Delete ${pack.name}`} onClick={onClick} />}
            >
                <p className='text-sm leading-relaxed text-muted-foreground'>
                    This deletes <strong className='text-foreground'>{pack.name}</strong>. A later Tebex payment for
                    package {pack.tebex_package_id} credits no tokens and is flagged for review. To stop selling it,
                    turn off &quot;On sale&quot; instead.
                </p>
            </Dialog.ConfirmTrigger>
        </RowActions>
    );
}

const packColumns = [
    {
        id: 'name',
        header: 'Pack',
        cell: ({ row }) => (
            <div className='min-w-0'>
                <p className='truncate font-medium text-foreground'>{row.original.name}</p>
                <p className='truncate text-xs text-muted-foreground'>Tebex package {row.original.tebex_package_id}</p>
            </div>
        ),
        meta: { headerClassName: 'w-auto', cellClassName: 'w-auto' },
    },
    {
        id: 'tokens',
        header: 'Tokens',
        cell: ({ row }) => (
            <span className='tabular-nums'>
                {row.original.tokens.toLocaleString()}
                {row.original.bonus_tokens > 0 && (
                    <span className='text-success'> +{row.original.bonus_tokens.toLocaleString()}</span>
                )}
            </span>
        ),
        meta: { headerClassName: 'w-32', cellClassName: 'w-32' },
    },
    {
        id: 'visible',
        header: 'Status',
        cell: ({ row }) => (
            <Badge tone={row.original.visible ? 'success' : 'neutral'} variant='soft' size='sm'>
                {row.original.visible ? 'On sale' : 'Hidden'}
            </Badge>
        ),
        meta: { headerClassName: 'w-28', cellClassName: 'w-28' },
    },
    actionsColumn<AdminTokenPack>(2, (pack) => <PackActionsCell pack={pack} />),
] satisfies ColumnDef<AdminTokenPack>[];

function TokenPacksTable() {
    const { data, error, isFetching, refetch } = useAdminTokenPacks();
    const table = useReactTable({
        data: data ?? emptyPacks,
        columns: packColumns,
        getCoreRowModel: getCoreRowModel(),
        getRowId: (pack) => String(pack.id),
    });

    if (error) {
        return <ServerError message={httpErrorToHuman(error)} onRetry={() => void refetch()} />;
    }

    return (
        <section className='mt-8'>
            <div className='mb-3 flex items-center justify-between gap-3'>
                <h3 className='text-base font-semibold text-foreground'>Token packs</h3>
                <CreatePackButton />
            </div>
            {data ? (
                <DataTable
                    table={table}
                    tableClassName='table-fixed'
                    isFetching={isFetching}
                    emptyState={
                        <AdminListEmpty
                            icon={Coins}
                            noun='token packs'
                            onClearFilter={noop}
                            description='Create a fixed package such as "500 tokens" in Tebex, then add it here.'
                            action={<CreatePackButton />}
                        />
                    }
                />
            ) : (
                <Spinner size='large' centered />
            )}
        </section>
    );
}

const statusTones = {
    pending: 'neutral',
    completed: 'success',
    refunded: 'warning',
    chargeback: 'danger',
    disputed: 'warning',
    declined: 'neutral',
    unmatched: 'danger',
} satisfies Record<TebexPaymentStatus, UiTone>;

function PaymentRow({ payment }: { payment: TebexPayment }) {
    return (
        <tr className='border-t border-border'>
            <td className='py-2 pr-3'>
                <p className='truncate font-mono text-xs text-foreground'>{payment.transaction_id}</p>
                <p className='truncate text-xs text-muted-foreground'>
                    {payment.created_at ? formatDate(payment.created_at, 'MMM D, HH:mm') : ''}
                </p>
            </td>
            <td className='py-2 pr-3 text-sm'>{payment.user ? payment.user.username : 'No user'}</td>
            <td className='py-2 pr-3 text-sm tabular-nums'>
                {payment.amount} {payment.currency}
            </td>
            <td className='py-2 pr-3 text-sm tabular-nums'>
                {payment.tokens.toLocaleString()}
                {payment.tokens_reversed > 0 && (
                    <span className='text-destructive'> −{payment.tokens_reversed.toLocaleString()}</span>
                )}
            </td>
            <td className='py-2'>
                <Badge tone={statusTones[payment.status]} variant='soft' size='sm'>
                    {payment.status}
                </Badge>
            </td>
        </tr>
    );
}

function PaymentsBody({ page, flagged, onPage }: { page: number; flagged: boolean; onPage: (page: number) => void }) {
    const { data, error, refetch } = useAdminTebexPayments(page, flagged);

    if (error) {
        return <ServerError message={httpErrorToHuman(error)} onRetry={() => void refetch()} />;
    }

    if (!data) {
        return <Spinner size='large' centered />;
    }

    if (data.data.length === 0) {
        return <p className='text-sm text-muted-foreground'>No payments yet.</p>;
    }

    return (
        <div className='overflow-x-auto'>
            <table className='w-full min-w-[36rem] text-left'>
                <thead>
                    <tr className='text-xs text-muted-foreground'>
                        <th className='pb-2 font-medium'>Transaction</th>
                        <th className='pb-2 font-medium'>User</th>
                        <th className='pb-2 font-medium'>Paid</th>
                        <th className='pb-2 font-medium'>Tokens</th>
                        <th className='pb-2 font-medium'>Status</th>
                    </tr>
                </thead>
                <tbody>
                    {data.data.map((payment) => (
                        <PaymentRow key={payment.id} payment={payment} />
                    ))}
                </tbody>
            </table>
            {data.meta.last_page > 1 && (
                <div className='mt-3 flex items-center justify-end gap-2 text-sm'>
                    <Button.Text type='button' size='xsmall' disabled={page <= 1} onClick={() => onPage(page - 1)}>
                        Previous
                    </Button.Text>
                    <span className='tabular-nums text-muted-foreground'>
                        {page} / {data.meta.last_page}
                    </span>
                    <Button.Text
                        type='button'
                        size='xsmall'
                        disabled={page >= data.meta.last_page}
                        onClick={() => onPage(page + 1)}
                    >
                        Next
                    </Button.Text>
                </div>
            )}
        </div>
    );
}

function PaymentsList() {
    const [page, setPage] = useState(1);
    const [flagged, setFlagged] = useState(false);

    return (
        <section className='mt-8'>
            <div className='mb-3 flex flex-wrap items-center justify-between gap-3'>
                <h3 className='text-base font-semibold text-foreground'>Payments</h3>
                <Button.Text
                    type='button'
                    size='xsmall'
                    isSecondary={!flagged}
                    onClick={() => {
                        setFlagged(!flagged);
                        setPage(1);
                    }}
                >
                    {flagged ? 'Showing flagged only' : 'Show flagged only'}
                </Button.Text>
            </div>
            <p className='mb-3 text-xs text-muted-foreground'>
                Flagged payments were refunded, disputed or matched no user or token pack. Refunds and lost disputes
                take the tokens back automatically, which can leave a balance below zero.
            </p>
            <PaymentsBody page={page} flagged={flagged} onPage={setPage} />
        </section>
    );
}

function SettingsSection() {
    const { data, error, refetch } = useAdminTebexSettings();

    if (data) {
        return <TebexSettingsForm settings={data} />;
    }

    return error ? (
        <ServerError message={httpErrorToHuman(error)} onRetry={() => void refetch()} />
    ) : (
        <Spinner size='large' centered />
    );
}

/**
 * The shop's "Token packs & Tebex" sub-tab: the Tebex store, the token packs it sells and the
 * payments it reported. Task R's Shop shell links to it at `/admin/shop/tebex`.
 */
export default function TebexShopTab() {
    return (
        <AdminContentBlock
            title='Admin · Shop · Token packs & Tebex'
            heading='Token packs & Tebex'
            description='Sell token packs through Tebex. Players pay on Tebex and the tokens arrive once Tebex confirms the payment.'
        >
            <SettingsSection />
            <TokenPacksTable />
            <PaymentsList />
        </AdminContentBlock>
    );
}
