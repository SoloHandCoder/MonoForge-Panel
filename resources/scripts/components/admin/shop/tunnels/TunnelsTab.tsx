import { useState } from 'react';
import { getCoreRowModel, useReactTable, type ColumnDef } from '@tanstack/react-table';
import { Import, RefreshCw, RotateCcw, Waypoints } from 'lucide-react';
import { httpErrorToHuman } from '@/api/http';
import {
    useAdminTunnels,
    useAdminTunnelSettings,
    useCreateTunnel,
    useImportTunnels,
    useRecreateTunnel,
    useRetryTunnel,
    useUpdateTunnelSettings,
    type ServerTunnel,
    type TunnelImportOutcome,
    type TunnelImportSummary,
    type TunnelSettings,
    type TunnelStatus,
} from '@/api/admin/tunnels/queries';
import { useAppForm, Form } from '@/components/form';
import AdminListEmpty from '@/components/admin/AdminListEmpty';
import Badge from '@/components/elements/Badge';
import Button from '@/components/elements/Button';
import { NewButton } from '@/components/elements/NewButton';
import Code from '@/components/elements/Code';
import CopyOnClick from '@/components/elements/CopyOnClick';
import { Dialog } from '@/components/elements/dialog';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import TitledGreyBox from '@/components/elements/TitledGreyBox';
import DataTable from '@/components/elements/table/DataTable';
import { actionsColumn, RowActionButton, RowActions } from '@/components/elements/table/RowActions';
import { TextInput } from '@/components/form/controls';
import type { UiTone } from '@/themes/skins';

const emptyTunnels: ServerTunnel[] = [];

const statusLabels = {
    pending: 'Pending',
    active: 'Active',
    failed: 'Failed',
    deleted: 'Deleted',
} satisfies Record<TunnelStatus, string>;

const statusTones = {
    pending: 'info',
    active: 'success',
    failed: 'danger',
    deleted: 'neutral',
} satisfies Record<TunnelStatus, UiTone>;

const outcomeLabels = {
    imported: 'Imported',
    already_linked: 'Already linked',
    server_has_tunnel: 'Server has a tunnel',
    no_match: 'No matching server',
    ambiguous: 'Ambiguous',
    unsupported: 'Not supported',
} satisfies Record<TunnelImportOutcome, string>;

const outcomeTones = {
    imported: 'success',
    already_linked: 'neutral',
    server_has_tunnel: 'neutral',
    no_match: 'warning',
    ambiguous: 'warning',
    unsupported: 'neutral',
} satisfies Record<TunnelImportOutcome, UiTone>;

const filters: { value: TunnelStatus | ''; label: string }[] = [
    { value: '', label: 'All' },
    { value: 'failed', label: 'Failed' },
    { value: 'pending', label: 'Pending' },
    { value: 'active', label: 'Active' },
    { value: 'deleted', label: 'Deleted' },
];

function TunnelActionsCell({ tunnel }: { tunnel: ServerTunnel }) {
    const retry = useRetryTunnel();
    const recreate = useRecreateTunnel();
    const { id, server_name: name, imported_at: importedAt } = tunnel.attributes;
    const label = name ?? `server #${tunnel.attributes.server_id}`;

    return (
        <RowActions>
            <RowActionButton
                icon={RefreshCw}
                label='Retry now'
                aria-label={`Retry the tunnel of ${label}`}
                disabled={retry.isPending}
                onClick={() => retry.mutate(id)}
            />
            <Dialog.ConfirmTrigger
                title='Recreate tunnel'
                confirm='Recreate tunnel'
                onConfirmed={async (_event, close) => {
                    try {
                        await recreate.mutateAsync(id);
                        close();
                    } catch {
                        // Error toast is handled by the mutation.
                    }
                }}
                trigger={({ onClick }) => (
                    <RowActionButton
                        icon={RotateCcw}
                        label='Recreate'
                        danger
                        aria-label={`Recreate the tunnel of ${label}`}
                        onClick={onClick}
                    />
                )}
            >
                <p className='text-sm leading-relaxed text-muted-foreground'>
                    This deletes the tunnel of <strong className='text-foreground'>{label}</strong> on playit.gg and
                    creates a new one. Players have to use the new address.
                    {!!importedAt && ' This tunnel was made by hand and imported; it is deleted on playit.gg too.'}
                </p>
            </Dialog.ConfirmTrigger>
        </RowActions>
    );
}

const columns = [
    {
        id: 'server',
        header: 'Server',
        cell: ({ row }) => (
            <div className='min-w-0'>
                <p className='truncate font-medium text-foreground'>
                    {row.original.attributes.server_name ?? `Server #${row.original.attributes.server_id}`}
                </p>
                <p className='truncate text-xs text-muted-foreground'>{row.original.attributes.node_name ?? 'n/a'}</p>
            </div>
        ),
        meta: { headerClassName: 'w-auto', cellClassName: 'w-auto' },
    },
    {
        id: 'address',
        header: 'Address',
        cell: ({ row }) =>
            row.original.attributes.address ? (
                <CopyOnClick text={row.original.attributes.address}>
                    <Code className='break-all'>{row.original.attributes.address}</Code>
                </CopyOnClick>
            ) : (
                <span className='text-xs text-muted-foreground'>None yet</span>
            ),
        meta: { headerClassName: 'hidden w-64 @xl:table-cell', cellClassName: 'hidden w-64 @xl:table-cell' },
    },
    {
        id: 'status',
        header: 'Status',
        cell: ({ row }) => (
            <div className='min-w-0'>
                <div className='flex flex-wrap gap-1'>
                    <Badge tone={statusTones[row.original.attributes.status]} variant='soft' size='sm'>
                        {statusLabels[row.original.attributes.status]}
                    </Badge>
                    {!!row.original.attributes.imported_at && (
                        <Badge tone='neutral' variant='soft' size='sm'>
                            Imported
                        </Badge>
                    )}
                </div>
                {!!row.original.attributes.last_error && (
                    <p className='mt-1 text-xs break-words text-destructive'>{row.original.attributes.last_error}</p>
                )}
            </div>
        ),
        meta: { headerClassName: 'w-56', cellClassName: 'w-56' },
    },
    actionsColumn<ServerTunnel>(2, (tunnel) => <TunnelActionsCell tunnel={tunnel} />),
] satisfies ColumnDef<ServerTunnel>[];

function CreateTunnelForm() {
    const create = useCreateTunnel();
    const [serverId, setServerId] = useState('');
    const id = Number.parseInt(serverId, 10);

    return (
        <form
            className='flex flex-wrap items-center gap-2'
            onSubmit={(event) => {
                event.preventDefault();
                if (id > 0) {
                    create.mutate(id, { onSuccess: () => setServerId('') });
                }
            }}
        >
            <TextInput
                aria-label='Server ID'
                inputMode='numeric'
                placeholder='Server ID'
                className='w-32'
                value={serverId}
                onChange={(event) => setServerId(event.currentTarget.value.replaceAll(/\D/g, ''))}
            />
            <Button type='submit' size='small' isSecondary isLoading={create.isPending} disabled={!(id > 0)}>
                Create tunnel
            </Button>
        </form>
    );
}

function ImportSummary({ summary }: { summary: TunnelImportSummary }) {
    return (
        <div className='grid gap-4 text-sm'>
            <p className='text-muted-foreground'>
                <strong className='text-foreground'>{summary.imported}</strong> imported,{' '}
                <strong className='text-foreground'>{summary.skipped}</strong> skipped.
            </p>
            {summary.errors.map((error) => (
                <p key={error.node_id} className='text-destructive'>
                    <strong>{error.node_name}:</strong> {error.message}
                </p>
            ))}
            {summary.results.length === 0 && summary.errors.length === 0 && (
                <p className='text-muted-foreground'>No playit.gg agent has any tunnels to import.</p>
            )}
            {summary.results.length > 0 && (
                <ul className='grid max-h-96 gap-2 overflow-y-auto'>
                    {summary.results.map((result, index) => (
                        <li
                            key={result.playit_tunnel_id ?? `${result.node_id}-${index}`}
                            className='rounded-md border border-border p-3'
                        >
                            <div className='flex flex-wrap items-center justify-between gap-2'>
                                <span className='min-w-0 truncate font-medium text-foreground'>
                                    {result.name ?? result.address ?? result.playit_tunnel_id ?? 'Unnamed tunnel'}
                                </span>
                                <Badge tone={outcomeTones[result.result]} variant='soft' size='sm'>
                                    {outcomeLabels[result.result]}
                                </Badge>
                            </div>
                            <p className='mt-1 text-xs text-muted-foreground'>
                                {result.node_name}
                                {result.local_port !== null && ` · port ${result.local_port}`}
                                {result.address !== null && ` · ${result.address}`}
                            </p>
                            <p className='mt-1 text-xs text-muted-foreground'>{result.reason}</p>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function ImportTunnelsButton() {
    const importTunnels = useImportTunnels();
    const [summary, setSummary] = useState<TunnelImportSummary>();

    return (
        <>
            <NewButton
                icon={Import}
                size='small'
                isSecondary
                isLoading={importTunnels.isPending}
                onClick={() => importTunnels.mutate(undefined, { onSuccess: setSummary })}
            >
                Import existing tunnels
            </NewButton>
            <Dialog
                open={summary !== undefined}
                title='Import existing tunnels'
                description='Tunnels made by hand on playit.gg, linked to the server on their port. The panel leaves imported tunnels alone on playit.gg unless their server is deleted or you recreate them.'
                onClose={() => setSummary(undefined)}
            >
                {summary && <ImportSummary summary={summary} />}
                <Dialog.Footer>
                    <Button type='button' onClick={() => setSummary(undefined)}>
                        Close
                    </Button>
                </Dialog.Footer>
            </Dialog>
        </>
    );
}

function TunnelList() {
    const [page, setPage] = useState(1);
    const [status, setStatus] = useState<TunnelStatus | ''>('');
    const { data, error, isFetching, refetch } = useAdminTunnels(page, status || undefined);
    const table = useReactTable({
        data: data?.data ?? emptyTunnels,
        columns,
        getCoreRowModel: getCoreRowModel(),
        getRowId: (tunnel) => String(tunnel.attributes.id),
    });
    const pages = data?.meta.pagination.total_pages ?? 1;

    if (error && !data) {
        return <ServerError message={httpErrorToHuman(error)} onRetry={() => void refetch()} />;
    }

    return (
        <>
            <div className='mb-3 flex flex-wrap items-center justify-between gap-3'>
                <div className='flex flex-wrap gap-1' role='group' aria-label='Filter by status'>
                    {filters.map((filter) => (
                        <Button.Text
                            key={filter.value}
                            type='button'
                            size='xsmall'
                            color={status === filter.value ? 'primary' : 'grey'}
                            aria-pressed={status === filter.value}
                            onClick={() => {
                                setStatus(filter.value);
                                setPage(1);
                            }}
                        >
                            {filter.label}
                        </Button.Text>
                    ))}
                </div>
                <div className='flex flex-wrap items-center gap-2'>
                    <ImportTunnelsButton />
                    <CreateTunnelForm />
                </div>
            </div>
            {data ? (
                <DataTable
                    table={table}
                    tableClassName='table-fixed'
                    isFetching={isFetching}
                    emptyState={
                        <AdminListEmpty
                            icon={Waypoints}
                            noun='tunnels'
                            filter={status || undefined}
                            onClearFilter={() => setStatus('')}
                            description='New servers on nodes with a playit.gg agent get a tunnel automatically. Set the agent on the node settings page.'
                        />
                    }
                />
            ) : (
                <Spinner size='large' centered />
            )}
            {pages > 1 && (
                <div className='mt-3 flex items-center justify-end gap-2 text-sm text-muted-foreground'>
                    <Button.Text size='xsmall' disabled={page <= 1} onClick={() => setPage(page - 1)}>
                        Previous
                    </Button.Text>
                    <span className='tabular-nums'>
                        Page {page} of {pages}
                    </span>
                    <Button.Text size='xsmall' disabled={page >= pages} onClick={() => setPage(page + 1)}>
                        Next
                    </Button.Text>
                </div>
            )}
        </>
    );
}

function SubdomainSettingsForm({ settings }: { settings: TunnelSettings }) {
    const update = useUpdateTunnelSettings();
    const form = useAppForm({
        defaultValues: {
            enabled: settings.subdomains_enabled,
            baseDomain: settings.base_domain,
            blocklist: settings.blocklist.join('\n'),
        },
        onSubmit: async ({ value }) => {
            try {
                await update.mutateAsync({
                    subdomains_enabled: value.enabled,
                    base_domain: value.baseDomain.trim(),
                    blocklist: value.blocklist
                        .split(/[\s,]+/)
                        .map((name) => name.trim())
                        .filter((name) => name !== ''),
                });
            } catch {
                // Error toast is handled by the mutation.
            }
        },
    });

    return (
        <Form form={form}>
            <TitledGreyBox title='Custom Subdomains' className='mt-6'>
                <p className='text-sm text-muted-foreground'>
                    Let players pick a name such as <Code>survival.monoforge.net</Code> at checkout. Creating the DNS
                    records needs playit.gg Premium and is coming soon; for now the chosen name is stored on the
                    server&apos;s tunnel.
                </p>
                <div className='mt-6'>
                    <form.AppField name='enabled'>
                        {(field) => (
                            <field.SwitchField
                                label='Offer custom subdomains'
                                description='Shows the subdomain input at checkout.'
                            />
                        )}
                    </form.AppField>
                </div>
                <div className='mt-6'>
                    <form.AppField name='baseDomain'>
                        {(field) => (
                            <field.TextField
                                type='text'
                                id='tunnel_base_domain'
                                label='Base Domain'
                                description='For example monoforge.net.'
                            />
                        )}
                    </form.AppField>
                </div>
                <div className='mt-6'>
                    <form.AppField name='blocklist'>
                        {(field) => (
                            <field.TextAreaField
                                id='tunnel_blocklist'
                                label='Reserved Names'
                                description='Names players cannot pick, one per line (such as www, panel, admin).'
                                rows={4}
                            />
                        )}
                    </form.AppField>
                </div>
                <div className='mt-6 text-right'>
                    <form.AppForm>
                        <form.SubmitButton>Save Settings</form.SubmitButton>
                    </form.AppForm>
                </div>
            </TitledGreyBox>
        </Form>
    );
}

function SubdomainSettings() {
    const { data } = useAdminTunnelSettings();

    return data ? <SubdomainSettingsForm settings={data} /> : <Spinner size='small' centered />;
}

/**
 * The admin Shop "Tunnels" sub-tab: every server's playit.gg tunnel with retry and recreate, and the
 * custom subdomain settings. It has no page chrome, so the Shop shell can render it in its sub-tab.
 */
export default function TunnelsTab() {
    return (
        <>
            <TunnelList />
            <SubdomainSettings />
        </>
    );
}
