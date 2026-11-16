import { useMemo, useState } from 'react';
import { Network } from 'lucide-react';
import {
    actionsColumn,
    Badge,
    Button,
    Code,
    CopyOnClick,
    DeleteAction,
    Dialog,
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
    Input,
    InputSpinner,
    ListToolbar,
    NewButton,
    RowActions,
    Spinner,
    SpinnerOverlay,
    Table,
    TextArea,
    useDebouncedCallback,
    type TableColumn,
} from '@/sdk';
import type {
    ServerAllocationItem,
    ServerAllocationsModel,
    ServerMinecraftHostnameModel,
    ViewProps,
} from '@/sdk/theme';
import { usePending } from '../usePending';

interface CellProps {
    model: ServerAllocationsModel;
    allocation: ServerAllocationItem;
}

function NotesCell({ model, allocation }: CellProps) {
    const [saving, track] = usePending();
    const save = useDebouncedCallback((notes: string) => track(model.actions.setNotes(allocation.id, notes)), 750);

    return (
        <InputSpinner visible={saving}>
            <TextArea
                aria-label={`Notes for ${allocation.ip}:${allocation.port}`}
                className='min-h-9 resize-y bg-input hover:border-input-border border-transparent'
                placeholder='Notes'
                defaultValue={allocation.notes || undefined}
                onChange={(event) => save(event.currentTarget.value)}
                onBlur={save.flush}
            />
        </InputSpinner>
    );
}

function DeleteAllocationButton({ model, allocation }: CellProps) {
    return (
        <Dialog.ConfirmTrigger
            title='Remove Allocation'
            confirm='Delete'
            onConfirmed={(_event, close) => model.actions.remove(allocation.id).then(close)}
            trigger={({ onClick }) => (
                <DeleteAction
                    aria-label={`Delete allocation ${allocation.address}:${allocation.port}`}
                    onClick={onClick}
                />
            )}
        >
            This allocation will be immediately removed from your server.
        </Dialog.ConfirmTrigger>
    );
}

function ActionsCell({ model, allocation }: CellProps) {
    const [settingPrimary, track] = usePending();

    if (allocation.primary) {
        return (
            <Badge tone='info' className='inline-flex rounded-full font-medium'>
                Primary
            </Badge>
        );
    }

    return (
        <RowActions>
            {model.permissions.update && (
                <Button.Text
                    size='xsmall'
                    disabled={settingPrimary}
                    onClick={() => track(model.actions.setPrimary(allocation.id))}
                >
                    Make Primary
                </Button.Text>
            )}
            {model.permissions.delete && <DeleteAllocationButton model={model} allocation={allocation} />}
        </RowActions>
    );
}

function allocationColumns(model: ServerAllocationsModel): TableColumn<ServerAllocationItem>[] {
    return [
        {
            id: 'address',
            label: 'Address',
            sortValue: (allocation) => allocation.address,
            cell: (allocation) => (
                <CopyOnClick text={allocation.address}>
                    <Code dark className='max-w-52 truncate'>
                        {allocation.address}
                    </Code>
                </CopyOnClick>
            ),
        },
        {
            id: 'port',
            label: 'Port',
            sortValue: (allocation) => allocation.port,
            cell: (allocation) => <Code dark>{allocation.port}</Code>,
            headerClassName: 'w-24',
            cellClassName: 'w-24',
        },
        {
            id: 'notes',
            label: 'Notes',
            cell: (allocation) => <NotesCell model={model} allocation={allocation} />,
            headerClassName: 'hidden md:table-cell',
            cellClassName: 'hidden md:table-cell min-w-56',
        },
        actionsColumn(2, (allocation) => <ActionsCell model={model} allocation={allocation} />, 'w-52'),
    ];
}

/** A DNS label of 3 to 40 lowercase letters, digits and single inner hyphens (checked again by the panel). */
const HOSTNAME = /^[a-z0-9](?:[a-z0-9]|-(?!-)){1,38}[a-z0-9]$/;

/** The server's name in its node's shared Minecraft entrance, which the owner may change. */
function MinecraftHostname({ hostname }: { hostname: ServerMinecraftHostnameModel }) {
    const [value, setValue] = useState(hostname.hostname);
    const [saving, track] = usePending();
    const name = value.trim().toLowerCase();
    const valid = HOSTNAME.test(name);

    return (
        <div className='mb-4 rounded-lg border border-border bg-card p-4 text-sm'>
            <p className='text-muted-foreground'>
                Players join this server as{' '}
                <span className='font-mono text-foreground'>
                    {hostname.address ?? `${hostname.hostname}.${hostname.domain}`}
                </span>
                {hostname.address ? '' : ' (address pending)'}, through the shared entrance of its node.
            </p>
            {hostname.canEdit && (
                <form
                    className='mt-3 flex flex-wrap items-end gap-2'
                    onSubmit={(event) => {
                        event.preventDefault();
                        if (valid && name !== hostname.hostname) {
                            track(hostname.rename(name));
                        }
                    }}
                >
                    <label className='flex min-w-0 flex-1 flex-col gap-1'>
                        <span className='text-xs font-medium text-muted-foreground'>Name</span>
                        <span className='flex min-w-0 items-center gap-1'>
                            <Input
                                value={value}
                                maxLength={40}
                                aria-label='Name in the address'
                                aria-invalid={!valid}
                                className='min-w-0 flex-1 font-mono sm:max-w-56'
                                onChange={(event) => setValue(event.currentTarget.value)}
                            />
                            <span className='truncate font-mono text-muted-foreground'>.{hostname.domain}</span>
                        </span>
                    </label>
                    <Button
                        type='submit'
                        size='small'
                        disabled={!valid || name === hostname.hostname}
                        isLoading={saving}
                    >
                        Change
                    </Button>
                    {!valid && (
                        <p className='w-full text-xs text-destructive'>
                            3 to 40 lowercase letters, digits and single hyphens, starting and ending with a letter or
                            digit.
                        </p>
                    )}
                </form>
            )}
        </div>
    );
}

export default function Allocations({ model, className }: ViewProps<'server.allocations'>) {
    const [creating, track] = usePending();
    const columns = useMemo(() => allocationColumns(model), [model]);

    if (model.loading) {
        return (
            <div className={className}>
                <Spinner size='large' centered />
            </div>
        );
    }

    const newAllocationButton = <NewButton onClick={() => track(model.actions.create())}>New allocation</NewButton>;

    return (
        <div className={className}>
            <SpinnerOverlay visible={creating} />
            {!model.playitAddress && model.addressPending && (
                <div className='mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-border bg-card p-4 text-sm'>
                    <span className='text-muted-foreground'>Public address</span>
                    <span className='font-medium'>Address pending</span>
                    <span className='text-xs text-muted-foreground'>
                        The public address appears here as soon as the server has one: a playit.gg tunnel, or a port the
                        game gateway forwards.
                    </span>
                </div>
            )}
            {model.playitAddress && (
                <div className='mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-border bg-card p-4 text-sm'>
                    <span className='text-muted-foreground'>Public address</span>
                    <CopyOnClick text={model.playitAddress} showInNotification>
                        <Code dark className='break-all'>
                            {model.playitAddress}
                        </Code>
                    </CopyOnClick>
                    <span className='text-xs text-muted-foreground'>
                        Players connect here; the ports below are the node&apos;s own.
                    </span>
                </div>
            )}
            {model.minecraftHostname && (
                <MinecraftHostname key={model.minecraftHostname.hostname} hostname={model.minecraftHostname} />
            )}
            {model.canManage && (
                <ListToolbar
                    summary={`You are currently using ${model.allocations.length} of ${model.limit} allowed allocations for this server.`}
                >
                    {model.canCreate && newAllocationButton}
                </ListToolbar>
            )}
            <Table
                rows={model.allocations}
                columns={columns}
                keyOf={(allocation) => String(allocation.id)}
                emptyState={
                    <Empty size='compact'>
                        <EmptyHeader>
                            <EmptyMedia variant='icon'>
                                <Network />
                            </EmptyMedia>
                            <EmptyTitle>No allocations</EmptyTitle>
                            <EmptyDescription>
                                This server doesn&apos;t have any network allocations assigned.
                            </EmptyDescription>
                        </EmptyHeader>
                        {model.canCreate && <EmptyContent>{newAllocationButton}</EmptyContent>}
                    </Empty>
                }
            />
        </div>
    );
}
