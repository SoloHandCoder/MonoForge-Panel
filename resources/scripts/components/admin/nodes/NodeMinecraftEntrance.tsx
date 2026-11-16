import { useStore } from '@tanstack/react-form';
import { Copy } from 'lucide-react';
import { Form, useAppForm } from '@/components/form';
import {
    applyProxyProtocolInput,
    proxyPortError,
    updateAdminNodeMinecraftEntranceInput,
    useAdminNodeMinecraftEntrance,
    useApplyProxyProtocol,
    useUpdateAdminNodeMinecraftEntrance,
    type NodeMinecraftEntrance as Entrance,
} from '@/api/admin/nodes/minecraftEntrance';
import { Alert } from '@/components/elements/alert';
import Button from '@/components/elements/Button';
import CopyOnClick from '@/components/elements/CopyOnClick';
import Icon from '@/components/elements/Icon';
import Spinner from '@/components/elements/Spinner';
import TitledGreyBox from '@/components/elements/TitledGreyBox';
import { formatDate } from '@/lib/dates';

const DOMAIN = /^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]*[a-z0-9]$/i;

export function domainError(domain: string): string | undefined {
    const value = domain.trim().replace(/\.$/, '');

    if (value === '') {
        return 'Enter the base domain, such as play.example.com.';
    }

    return DOMAIN.test(value) ? undefined : 'Enter a domain such as play.example.com, without https:// or a port.';
}

function Copyable({ label, text }: { label: string; text: string }) {
    return (
        <div>
            <p className='mb-1.5 text-xs font-medium tracking-wide text-muted-foreground uppercase'>{label}</p>
            <div className='relative'>
                <CopyOnClick text={text} showInNotification={false}>
                    <Button.Text
                        type='button'
                        size='xsmall'
                        isSecondary
                        aria-label={`Copy: ${label}`}
                        className='absolute right-2 top-2'
                    >
                        <Icon icon={Copy} className='h-3.5 w-3.5' />
                    </Button.Text>
                </CopyOnClick>
                <pre className='whitespace-pre-wrap break-all rounded-sm border border-border bg-sunken p-3 pr-12 font-mono text-xs leading-relaxed text-foreground'>
                    {text}
                </pre>
            </div>
        </div>
    );
}

function DnsRecords({ entrance }: { entrance: Entrance }) {
    if (entrance.dns.length === 0) {
        return (
            <p className='text-sm text-muted-foreground'>
                The DNS records appear here once the entrance has an address
                {entrance.mode === 'playit' ? ' (the playit tunnel is created when you save)' : ''}.
            </p>
        );
    }

    return (
        <ul className='space-y-2'>
            {entrance.dns.map((record) => (
                <li
                    key={`${record.type}-${record.name}`}
                    className='rounded-sm border border-border bg-sunken p-3 text-xs'
                >
                    <div className='flex flex-wrap items-center gap-x-2 gap-y-1 font-mono'>
                        <span className='font-semibold text-foreground'>{record.type}</span>
                        <span className='break-all text-foreground'>{record.name}</span>
                        <span aria-hidden className='text-muted-foreground'>
                            →
                        </span>
                        <span className='break-all text-foreground'>{record.value}</span>
                        <CopyOnClick text={`${record.name} ${record.type} ${record.value}`} showInNotification={false}>
                            <Button.Text
                                type='button'
                                size='xsmall'
                                isSecondary
                                aria-label={`Copy the ${record.type} record ${record.name}`}
                            >
                                <Icon icon={Copy} className='h-3 w-3' />
                            </Button.Text>
                        </CopyOnClick>
                    </div>
                    <p className='mt-1 text-muted-foreground'>{record.note}</p>
                </li>
            ))}
        </ul>
    );
}

/** Where the entrance stands: routed servers, the last sync, and what blocks it. */
function EntranceAlerts({ entrance }: { entrance: Entrance }) {
    return (
        <>
            {entrance.active && (
                <Alert type='info' className='mt-4'>
                    {entrance.servers} {entrance.servers === 1 ? 'server is' : 'servers are'} routed
                    {entrance.example_address ? `, such as ${entrance.example_address}` : ''}.{' '}
                    {entrance.synced_at
                        ? `The node last fetched its routes ${formatDate(entrance.synced_at, 'MMM D, HH:mm')}.`
                        : 'The node has not fetched its routes yet: install mc-router.sh below.'}
                </Alert>
            )}
            {entrance.tunnel_error && (
                <Alert type='warning' className='mt-4'>
                    playit.gg: {entrance.tunnel_error}
                </Alert>
            )}
            {entrance.gateway_forwards_entrance === false && (
                <Alert type='warning' className='mt-4'>
                    The game gateway does not forward port {entrance.entrance_port}: add it to the gateway ports and to
                    gateway.sh --ports.
                </Alert>
            )}
        </>
    );
}

function EntranceForm({ nodeId, entrance }: { nodeId: number; entrance: Entrance }) {
    const update = useUpdateAdminNodeMinecraftEntrance(nodeId);
    const apply = useApplyProxyProtocol();
    const form = useAppForm({
        defaultValues: {
            enabled: entrance.enabled,
            domain: entrance.domain ?? '',
            mode: entrance.mode ?? 'playit',
            entrancePort: entrance.entrance_port as number | null,
            proxyPort: entrance.proxy_port as number | null,
            proxyProtocol: entrance.proxy_protocol,
            srv: entrance.srv,
        },
        onSubmit: async ({ value }) => {
            try {
                await update.mutateAsync(
                    updateAdminNodeMinecraftEntranceInput(nodeId, {
                        enabled: value.enabled,
                        domain: value.domain.trim() || undefined,
                        mode: value.mode === 'gateway' ? 'gateway' : 'playit',
                        entrance_port: value.entrancePort ?? undefined,
                        proxy_port: value.proxyPort ?? undefined,
                        proxy_protocol: value.proxyProtocol,
                        srv: value.srv,
                    })
                );
            } catch {
                // Error toast is handled by the mutation.
            }
        },
    });
    const mode = useStore(form.store, (state) => state.values.mode);
    const enabled = useStore(form.store, (state) => state.values.enabled);

    return (
        <Form form={form}>
            <TitledGreyBox title='Shared Minecraft entrance' className='mt-6'>
                <p className='text-sm text-muted-foreground'>
                    Every Minecraft Java server on this node is joined as{' '}
                    <span className='font-mono'>name.your-domain</span> through one entrance (a single playit.gg tunnel
                    or the game gateway). A Gate proxy on the node routes players by the name they typed. Routed servers
                    get no playit tunnel of their own for the game port; extra ports such as voice chat keep theirs.
                </p>
                <EntranceAlerts entrance={entrance} />
                <div className='mt-6'>
                    <form.AppField name='enabled'>
                        {(field) => (
                            <field.SwitchField
                                label='Use the shared entrance'
                                description='Show players name.your-domain for this node’s Minecraft Java servers.'
                            />
                        )}
                    </form.AppField>
                </div>
                <div className='mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2'>
                    <form.AppField
                        name='domain'
                        validators={{
                            onChange: ({ value, fieldApi }) =>
                                fieldApi.form.getFieldValue('enabled') || value.trim() !== ''
                                    ? domainError(value)
                                    : undefined,
                        }}
                    >
                        {(field) => (
                            <field.TextField
                                type='text'
                                id='entrance_domain'
                                label='Base domain'
                                placeholder='play.example.com'
                                description='Servers become name.play.example.com.'
                            />
                        )}
                    </form.AppField>
                    <form.AppField name='mode'>
                        {(field) => (
                            <field.SelectField
                                id='entrance_mode'
                                label='Exposed through'
                                options={[
                                    { value: 'playit', label: 'playit.gg (one tunnel)' },
                                    { value: 'gateway', label: 'Game gateway (VPS)' },
                                ]}
                            />
                        )}
                    </form.AppField>
                    {mode === 'gateway' ? (
                        <form.AppField
                            name='entrancePort'
                            validators={{ onChange: ({ value }) => proxyPortError(value ?? 0) }}
                        >
                            {(field) => (
                                <field.NumberField
                                    id='entrance_port'
                                    label='Entrance port'
                                    min={1024}
                                    max={65535}
                                    description='The gateway forwards it and the proxy listens on it. 25565 needs no port in the address. No allocation may use it.'
                                />
                            )}
                        </form.AppField>
                    ) : (
                        <form.AppField
                            name='proxyPort'
                            validators={{ onChange: ({ value }) => proxyPortError(value ?? 0) }}
                        >
                            {(field) => (
                                <field.NumberField
                                    id='proxy_port'
                                    label='Proxy port (on the node)'
                                    min={1024}
                                    max={65535}
                                    description='The playit tunnel points at 127.0.0.1 on this port. No allocation may use it.'
                                />
                            )}
                        </form.AppField>
                    )}
                </div>
                <div className='mt-6 space-y-4'>
                    {mode === 'playit' && (
                        <form.AppField name='srv'>
                            {(field) => (
                                <field.SwitchField
                                    label='SRV records are set up'
                                    description='Show addresses without the playit port once each server has its SRV record (listed below).'
                                />
                            )}
                        </form.AppField>
                    )}
                    <form.AppField name='proxyProtocol'>
                        {(field) => (
                            <field.SwitchField
                                label='Forward player IPs (PROXY protocol)'
                                description='Only for Paper, Purpur and Folia servers, after "Apply to servers". Others keep seeing 127.0.0.1.'
                            />
                        )}
                    </form.AppField>
                </div>
                <div className='mt-6 flex flex-wrap justify-end gap-3'>
                    {entrance.proxy_protocol && entrance.active && (
                        <Button
                            type='button'
                            isSecondary
                            isLoading={apply.isPending}
                            onClick={() => apply.mutate(applyProxyProtocolInput(nodeId))}
                        >
                            Apply to servers
                        </Button>
                    )}
                    <form.AppForm>
                        <form.SubmitButton>{enabled ? 'Save entrance' : 'Save'}</form.SubmitButton>
                    </form.AppForm>
                </div>
                {apply.data && apply.data.servers.length > 0 && (
                    <ul className='mt-4 space-y-1 text-sm' aria-live='polite'>
                        {apply.data.servers.map((server) => (
                            <li key={server.server_id} className='break-words'>
                                {server.server_name}:{' '}
                                <span className='text-muted-foreground'>
                                    {
                                        {
                                            applied: 'gets player IPs',
                                            restart: 'restart it, then apply again',
                                            unsupported: 'its software cannot read PROXY protocol',
                                            failed: 'Wings could not change its config',
                                        }[server.result]
                                    }
                                </span>
                            </li>
                        ))}
                    </ul>
                )}

                <div className='mt-8 space-y-4'>
                    <p className='text-sm font-medium text-foreground'>DNS records (DNS only)</p>
                    <DnsRecords entrance={entrance} />
                    <p className='text-sm font-medium text-foreground'>Install the router on the node</p>
                    <p className='text-sm text-muted-foreground'>
                        It installs Gate and a timer that fetches the routes every minute with the node&apos;s Wings
                        token (read from the Wings config, never shown).
                    </p>
                    <Copyable label='Download' text={entrance.commands.download} />
                    <Copyable label='Install (or update)' text={entrance.commands.install} />
                    <Copyable label='Status' text={entrance.commands.status} />
                    <Copyable label='Remove' text={entrance.commands.remove} />
                </div>
            </TitledGreyBox>
        </Form>
    );
}

/** The node's shared Minecraft entrance card (Gate Lite routing by host name). */
export default function NodeMinecraftEntrance({ nodeId }: { nodeId: number }) {
    const { data, isError } = useAdminNodeMinecraftEntrance(nodeId);

    if (isError) {
        return null;
    }

    return data ? <EntranceForm key={nodeId} nodeId={nodeId} entrance={data} /> : <Spinner size='small' centered />;
}
