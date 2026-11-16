import { useStore } from '@tanstack/react-form';
import { Copy } from 'lucide-react';
import { Form, useAppForm } from '@/components/form';
import {
    checkAdminNodeGatewayInput,
    parseGatewayPorts,
    updateAdminNodeGatewayInput,
    useAdminNodeGateway,
    useCheckAdminNodeGateway,
    useUpdateAdminNodeGateway,
    type NodeGateway,
    type NodeGatewayCheck,
} from '@/api/admin/nodes/gateway';
import { Alert } from '@/components/elements/alert';
import Button from '@/components/elements/Button';
import CopyOnClick from '@/components/elements/CopyOnClick';
import Icon from '@/components/elements/Icon';
import Spinner from '@/components/elements/Spinner';
import TitledGreyBox from '@/components/elements/TitledGreyBox';

/** A host name with at least two labels, or an IPv4 address; never a URL or a port. */
const HOST = /^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i;

export function hostError(host: string): string | undefined {
    const value = host.trim().replace(/\.$/, '');

    if (value === '') {
        return 'Enter the public host players join, such as play.example.com.';
    }

    return HOST.test(value)
        ? undefined
        : 'Enter a host name such as play.example.com or an IPv4 address, without https:// or a port.';
}

function CommandBlock({ label, text }: { label: string; text: string }) {
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

function CheckResult({ result }: { result: NodeGatewayCheck }) {
    return (
        <div className='mt-4 space-y-3 text-sm' aria-live='polite'>
            <p className='text-foreground'>
                <span className='font-medium'>{result.forwarded.total}</span> allocations forwarded
                {result.forwarded.ports ? (
                    <span className='font-mono text-muted-foreground'> ({result.forwarded.ports})</span>
                ) : null}
                , {result.forwarded.assigned} in use.
            </p>
            <p className={result.not_forwarded.total > 0 ? 'text-warning' : 'text-muted-foreground'}>
                <span className='font-medium'>{result.not_forwarded.total}</span> not forwarded
                {result.not_forwarded.ports ? <span className='font-mono'> ({result.not_forwarded.ports})</span> : null}
                , {result.not_forwarded.assigned} in use.
            </p>
            {result.servers_not_forwarded.length > 0 && (
                <div>
                    <p className='text-muted-foreground'>Servers players cannot reach through the gateway:</p>
                    <ul className='mt-1 list-disc space-y-0.5 pl-5'>
                        {result.servers_not_forwarded.map((server) => (
                            <li key={`${server.server_id}-${server.port}`} className='break-words'>
                                {server.server_name} <span className='font-mono'>:{server.port}</span>
                                {server.primary ? '' : ' (extra port)'}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}

function GatewayForm({ nodeId, gateway }: { nodeId: number; gateway: NodeGateway }) {
    const update = useUpdateAdminNodeGateway(nodeId);
    const check = useCheckAdminNodeGateway();
    const form = useAppForm({
        defaultValues: { enabled: gateway.enabled, host: gateway.host ?? '', ports: gateway.ports },
        onSubmit: async ({ value }) => {
            try {
                const saved = await update.mutateAsync(
                    updateAdminNodeGatewayInput(nodeId, {
                        enabled: value.enabled,
                        host: value.host.trim() || undefined,
                        ports: value.ports.trim() || undefined,
                    })
                );

                form.reset({ enabled: saved.enabled, host: saved.host ?? '', ports: saved.ports });
            } catch {
                // Error toast is handled by the mutation.
            }
        },
    });
    const enabled = useStore(form.store, (state) => state.values.enabled);
    const ports = useStore(form.store, (state) => state.values.ports);
    const portsValid = parseGatewayPorts(ports).ok;

    return (
        <Form form={form}>
            <TitledGreyBox title='Game gateway' className='mt-6'>
                <p className='text-sm text-muted-foreground'>
                    A small VPS (such as Oracle Cloud&apos;s free tier) receives the game ports and forwards them over
                    WireGuard to this node. Players join <span className='font-mono'>your-host:port</span> and the
                    node&apos;s own address stays hidden. While it is on, this node&apos;s servers get no playit.gg
                    tunnels; existing ones are kept on playit and come back when you turn it off.
                </p>
                {gateway.active && (
                    <Alert type='info' className='mt-4'>
                        Players join through {gateway.host}. playit.gg tunnels are paused on this node.
                    </Alert>
                )}
                <div className='mt-6'>
                    <form.AppField name='enabled'>
                        {(field) => (
                            <field.SwitchField
                                label='Use the game gateway'
                                description='Show the gateway address to players instead of playit.gg tunnels.'
                            />
                        )}
                    </form.AppField>
                </div>
                <div className='mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2'>
                    <form.AppField
                        name='host'
                        validators={{
                            onChange: ({ value, fieldApi }) =>
                                fieldApi.form.getFieldValue('enabled') || value.trim() !== ''
                                    ? hostError(value)
                                    : undefined,
                        }}
                    >
                        {(field) => (
                            <field.TextField
                                type='text'
                                id='gateway_host'
                                label='Public host'
                                placeholder='play.example.com'
                                description='The DNS-only A record pointing at the VPS, or its IPv4 address. Never the node’s own address.'
                            />
                        )}
                    </form.AppField>
                    <form.AppField
                        name='ports'
                        validators={{
                            onChange: ({ value }) => {
                                const parsed = parseGatewayPorts(value);

                                return parsed.ok ? undefined : parsed.error;
                            },
                        }}
                    >
                        {(field) => (
                            <field.TextField
                                type='text'
                                id='gateway_ports'
                                label='Forwarded ports'
                                placeholder='25565-25700'
                                description='The same list as --ports: up to 7 ports or ranges, 1024 to 65535 (TCP and UDP).'
                            />
                        )}
                    </form.AppField>
                </div>
                <div className='mt-6 flex flex-wrap justify-end gap-3'>
                    <Button
                        type='button'
                        isSecondary
                        isLoading={check.isPending}
                        disabled={!portsValid}
                        onClick={() => check.mutate(checkAdminNodeGatewayInput(nodeId, ports.trim()))}
                    >
                        Check allocations
                    </Button>
                    <form.AppForm>
                        <form.SubmitButton>{enabled ? 'Save gateway' : 'Save'}</form.SubmitButton>
                    </form.AppForm>
                </div>
                {check.data && <CheckResult result={check.data} />}

                <div className='mt-8 space-y-4'>
                    <p className='text-sm font-medium text-foreground'>Set up the gateway</p>
                    <p className='text-sm text-muted-foreground'>
                        Download the script on both machines, then run the commands in this order. The VPS prints its IP
                        and key, and the node prints its key: paste them where the placeholders are. Allow UDP 51820 and
                        the ports above (TCP and UDP) in the VPS provider&apos;s firewall too.
                    </p>
                    <CommandBlock label='Download (VPS and node)' text={gateway.commands.download} />
                    <CommandBlock label='1. On the VPS' text={gateway.commands.vps_init} />
                    <CommandBlock label='2. On the node' text={gateway.commands.node_init} />
                    <CommandBlock label='3. On the VPS' text={gateway.commands.vps_add_node} />
                    <CommandBlock label='Check the link (either machine)' text={gateway.commands.status} />
                </div>
            </TitledGreyBox>
        </Form>
    );
}

/** The node's game gateway card (VPS + WireGuard instead of playit.gg tunnels). */
export default function NodeGatewaySettings({ nodeId }: { nodeId: number }) {
    const { data, isError } = useAdminNodeGateway(nodeId);

    if (isError) {
        return null;
    }

    return data ? <GatewayForm key={nodeId} nodeId={nodeId} gateway={data} /> : <Spinner size='small' centered />;
}
