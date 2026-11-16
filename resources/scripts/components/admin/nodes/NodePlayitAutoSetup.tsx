import { useState, type ReactNode } from 'react';
import { Copy, ExternalLink } from 'lucide-react';
import {
    playitSwitchWaits,
    useCreatePlayitSwitchToken,
    useDismissOldTunnels,
    useFinishPlayitSwitch,
    usePlayitClaimPolling,
    usePlayitSwitch,
    useReconcilePlayitSwitch,
    useRemoveOldTunnels,
    useStartPlayitClaim,
    useUndoPlayitSwitch,
    type PlayitSwitchState,
    type PlayitSwitchToken,
} from '@/api/admin/tunnels/queries';
import { Alert } from '@/components/elements/alert';
import Button from '@/components/elements/Button';
import CopyOnClick from '@/components/elements/CopyOnClick';
import { Dialog } from '@/components/elements/dialog';
import Icon from '@/components/elements/Icon';
import Spinner from '@/components/elements/Spinner';
import TitledGreyBox from '@/components/elements/TitledGreyBox';
import { formatDate } from '@/lib/dates';

const when = (iso: string | null): string => (iso ? formatDate(iso, 'MMM D, HH:mm') : '');

/** The commands to run on the node; the token is pasted into the script's hidden prompt, never into a command. */
export function switchCommands(script: PlayitSwitchState['script']): string {
    const panel = new URL(script.url).origin;
    const lines = [`curl -fsSL -o playit-switch-agent.sh ${script.url}`];

    if (script.sha256) {
        lines.push(`echo "${script.sha256}  playit-switch-agent.sh" | sha256sum -c -`);
    }

    lines.push(`sudo bash playit-switch-agent.sh --panel ${panel}`);

    return lines.join('\n');
}

function CodeBlock({ text, label }: { text: string; label: string }) {
    return (
        <div className='relative'>
            <CopyOnClick text={text} showInNotification={false}>
                <Button.Text
                    type='button'
                    size='xsmall'
                    isSecondary
                    aria-label={label}
                    className='absolute right-2 top-2'
                >
                    <Icon icon={Copy} className='h-3.5 w-3.5' />
                </Button.Text>
            </CopyOnClick>
            <pre className='whitespace-pre-wrap break-all rounded-sm border border-border bg-sunken p-4 pr-12 font-mono text-xs leading-relaxed text-foreground'>
                {text}
            </pre>
        </div>
    );
}

function Step({ number, title, children }: { number: number; title: string; children: ReactNode }) {
    return (
        <li className='flex gap-3'>
            <span
                aria-hidden
                className='flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-border text-xs font-medium'
            >
                {number}
            </span>
            <div className='min-w-0 flex-1'>
                <p className='text-sm font-medium text-foreground'>
                    <span className='sr-only'>Step {number}: </span>
                    {title}
                </p>
                <div className='mt-2 space-y-3 text-sm text-muted-foreground'>{children}</div>
            </div>
        </li>
    );
}

function TokenDialog({
    token,
    state,
    onClose,
}: {
    token: PlayitSwitchToken | null;
    state: PlayitSwitchState;
    onClose: () => void;
}) {
    return (
        <Dialog open={token !== null} title='Switch the node to the new agent' onClose={onClose}>
            {token && (
                <div className='space-y-4 text-sm text-muted-foreground'>
                    <p>
                        On the node, as a user who can use sudo, run these commands. The script backs up the current
                        playit config, points the running agent at the new key, restarts it and checks it comes online.
                        If anything fails it puts the old config back.
                    </p>
                    <CodeBlock text={switchCommands(state.script)} label='Copy the switch commands' />
                    <p>
                        When the script asks for the token, paste this one. It is not shown while you paste and is not
                        saved in your shell history. It works once, until {when(token.expires_at)}. This is the only
                        time the panel shows it.
                    </p>
                    <CodeBlock text={token.token} label='Copy the switch token' />
                    <p>
                        To undo the switch on the node later: <code>sudo bash playit-switch-agent.sh --rollback</code>
                    </p>
                </div>
            )}
        </Dialog>
    );
}

function OldTunnels({ nodeId, state }: { nodeId: number; state: PlayitSwitchState }) {
    const remove = useRemoveOldTunnels(nodeId);
    const dismiss = useDismissOldTunnels(nodeId);

    if (state.old_tunnels.length === 0) {
        return null;
    }

    return (
        <div className='mt-4'>
            <p className='text-sm font-medium text-foreground'>Old tunnels on the previous agent</p>
            <p className='mt-1 text-sm text-muted-foreground'>
                These no longer carry players. Remove them so they do not count against your playit.gg tunnel limit.
                When the old key is read-only, delete them in the playit dashboard and then choose &quot;I removed
                them&quot;.
            </p>
            <ul className='mt-3 divide-y divide-border rounded-sm border border-border'>
                {state.old_tunnels.map((tunnel) => (
                    <li key={tunnel.id} className='flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 p-3'>
                        <span className='min-w-0 break-all text-sm text-foreground'>
                            {tunnel.name ?? tunnel.playit_tunnel_id}
                        </span>
                        <span className='min-w-0 break-all text-xs text-muted-foreground'>
                            {[
                                tunnel.server_name,
                                tunnel.local_port ? `port ${tunnel.local_port}` : null,
                                tunnel.address,
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        </span>
                    </li>
                ))}
            </ul>
            <div className='mt-3 flex flex-wrap justify-end gap-3'>
                <Dialog.ConfirmTrigger
                    title='Mark the old tunnels as removed?'
                    confirm='I removed them'
                    pending={dismiss.isPending}
                    onConfirmed={(_event, close) => dismiss.mutateAsync().then(close)}
                    trigger={({ onClick }) => (
                        <Button type='button' isSecondary onClick={onClick}>
                            I removed them
                        </Button>
                    )}
                >
                    The panel stops listing these tunnels. Nothing changes on playit.gg, so only do this after deleting
                    them in the playit dashboard.
                </Dialog.ConfirmTrigger>
                <Dialog.ConfirmTrigger
                    title='Remove the old tunnels?'
                    confirm='Remove old tunnels'
                    pending={remove.isPending}
                    onConfirmed={(_event, close) => remove.mutateAsync().then(close)}
                    trigger={({ onClick }) => (
                        <Button.Danger type='button' onClick={onClick}>
                            Remove old tunnels
                        </Button.Danger>
                    )}
                >
                    The panel deletes {state.old_tunnels.length} tunnel
                    {state.old_tunnels.length === 1 ? '' : 's'} of the previous agent on playit.gg. This cannot be
                    undone, and undoing the switch afterwards gives those servers new tunnels instead of their old
                    addresses.
                </Dialog.ConfirmTrigger>
            </div>
        </div>
    );
}

function SelfManaged({ nodeId, state }: { nodeId: number; state: PlayitSwitchState }) {
    const undo = useUndoPlayitSwitch(nodeId);
    const reconcile = useReconcilePlayitSwitch(nodeId);

    return (
        <>
            <Alert type='success' title='Automatic tunnels are on' className='mt-4'>
                This node runs the self-managed agent{state.claim?.agent_id ? ` ${state.claim.agent_id}` : ''}. The
                panel creates a playit.gg tunnel for every server on it, and for every new server.
            </Alert>
            {state.switched_at && (
                <Alert type='warning' title='Server addresses changed' className='mt-4'>
                    Since the switch on {when(state.switched_at)}, servers on this node have new playit.gg addresses.
                    The panel shows the new ones; tell your players to use them.
                </Alert>
            )}
            <OldTunnels nodeId={nodeId} state={state} />
            <div className='mt-4 flex flex-wrap justify-end gap-3'>
                <Button
                    type='button'
                    isSecondary
                    isLoading={reconcile.isPending}
                    onClick={() => reconcile.mutate()}
                >
                    Check status
                </Button>
                {state.can_undo && (
                    <Dialog.ConfirmTrigger
                        title='Go back to the previous agent?'
                        confirm='Undo switch'
                        pending={undo.isPending}
                        onConfirmed={(_event, close) => undo.mutateAsync().then(close)}
                        trigger={({ onClick }) => (
                            <Button type='button' isSecondary onClick={onClick}>
                                Undo switch
                            </Button>
                        )}
                    >
                        First run <code>sudo bash playit-switch-agent.sh --rollback</code> on the node, so it runs the
                        old agent again. The panel then deletes the tunnels it made on the new agent and gives the
                        servers their old tunnels and addresses back.
                    </Dialog.ConfirmTrigger>
                )}
            </div>
        </>
    );
}

type Claim = NonNullable<PlayitSwitchState['claim']>;

function StartButton({
    nodeId,
    label,
    secondary,
    replaces,
}: {
    nodeId: number;
    label: string;
    secondary: boolean;
    replaces: boolean;
}) {
    const start = useStartPlayitClaim(nodeId);

    return (
        <Dialog.ConfirmTrigger
            title='Set up automatic tunnels?'
            confirm='Start'
            pending={start.isPending}
            onConfirmed={(_event, close) => start.mutateAsync().then(close)}
            trigger={({ onClick }) => (
                <Button type='button' isSecondary={secondary} onClick={onClick}>
                    {label}
                </Button>
            )}
        >
            The panel asks playit.gg for a new agent that it may create tunnels on. Nothing changes on the node or for
            players yet: that only happens when you run the switch script on the node.
            {replaces && ' Any unfinished setup is replaced.'}
        </Dialog.ConfirmTrigger>
    );
}

function WaitingClaim({ nodeId, claim }: { nodeId: number; claim: Claim }) {
    if (!claim.claim_url) {
        return null;
    }

    return (
        <>
            <p>
                Open playit.gg, sign in with the account the node&apos;s tunnels belong to and accept the new agent.
                This page notices on its own
                {claim.expires_at ? `; the link works until ${when(claim.expires_at)}` : ''}.
            </p>
            <a
                href={claim.claim_url}
                target='_blank'
                rel='noopener noreferrer'
                className='inline-flex items-center gap-1 break-all font-medium text-primary underline'
            >
                {claim.claim_url}
                <Icon icon={ExternalLink} className='h-3.5 w-3.5 shrink-0' />
            </a>
            <p className='flex items-center gap-2' role='status'>
                <Spinner size='small' />
                {claim.status === 'accepted'
                    ? 'Accepted. Fetching the new agent key…'
                    : 'Waiting for you to accept on playit.gg…'}
            </p>
            {claim.last_error && <p className='text-xs'>Last answer from playit.gg: {claim.last_error}</p>}
            <div>
                <StartButton nodeId={nodeId} label='Start again' secondary replaces />
            </div>
        </>
    );
}

function ClaimStep({ nodeId, state }: { nodeId: number; state: PlayitSwitchState }) {
    const claim = state.claim;
    let body: ReactNode;

    if (claim === null) {
        body = (
            <>
                <p>
                    playit.gg only lets the panel read this node&apos;s current agent, so tunnels have to be made by
                    hand. A self-managed agent lets the panel create them itself.
                </p>
                <div>
                    <StartButton nodeId={nodeId} label='Set up automatic tunnels' secondary={false} replaces={false} />
                </div>
            </>
        );
    } else if (playitSwitchWaits.isWaiting(state)) {
        body = <WaitingClaim nodeId={nodeId} claim={claim} />;
    } else if (claim.status === 'ready') {
        body = (
            <p>
                Done: the new agent {claim.agent_id} belongs to your playit.gg account. Its key is stored encrypted and
                is never shown.
            </p>
        );
    } else {
        body = (
            <>
                <Alert type='danger'>
                    {claim.last_error ??
                        (claim.status === 'rejected' ? 'The claim was rejected.' : 'The claim expired.')}
                </Alert>
                <div>
                    <StartButton nodeId={nodeId} label='Start again' secondary={false} replaces />
                </div>
            </>
        );
    }

    return (
        <Step number={1} title='Claim a new agent on playit.gg'>
            {body}
        </Step>
    );
}

function SwitchStep({ nodeId, state }: { nodeId: number; state: PlayitSwitchState }) {
    const createToken = useCreatePlayitSwitchToken(nodeId);
    const [token, setToken] = useState<PlayitSwitchToken | null>(null);
    const claim = state.claim;

    if (claim?.status !== 'ready') {
        return (
            <Step number={2} title='Switch the node to the new agent'>
                <p>Available once the claim is accepted.</p>
            </Step>
        );
    }

    return (
        <Step number={2} title='Switch the node to the new agent'>
            <TokenDialog token={token} state={state} onClose={() => setToken(null)} />
            <p>
                The panel makes a one-time token and shows the commands to run on the node. The script fetches the new
                key with the token, backs up the current playit config and restarts playit with the new key. The
                node&apos;s current tunnels stop working then and every server gets a new address.
            </p>
            {claim.fetched_at && claim.report === null && (
                <p className='flex items-center gap-2' role='status'>
                    <Spinner size='small' />
                    The node fetched the key at {when(claim.fetched_at)}. Waiting for the script to report…
                </p>
            )}
            {claim.report === 'rolled_back' && (
                <Alert type='info'>
                    The script put the old playit config back at {when(claim.reported_at)}. Nothing changed for players.
                    Create a new token to try again.
                </Alert>
            )}
            {claim.token_expires_at && (
                <p>
                    A token is waiting to be used until {when(claim.token_expires_at)}. Creating a new one replaces it.
                </p>
            )}
            <div className='flex flex-wrap gap-3'>
                <Button
                    type='button'
                    isLoading={createToken.isPending}
                    disabled={createToken.isPending}
                    onClick={() => createToken.mutate(undefined, { onSuccess: setToken })}
                >
                    Show switch commands
                </Button>
            </div>
        </Step>
    );
}

function FinishStep({ nodeId, state }: { nodeId: number; state: PlayitSwitchState }) {
    const finish = useFinishPlayitSwitch(nodeId);
    const canFinish = state.claim?.status === 'ready' && state.claim.fetched_at !== null;

    return (
        <Step number={3} title='The panel creates the new tunnels'>
            <p>
                When the script reports success, the panel switches to the new agent, creates a tunnel for every server
                on this node and lists the old tunnels for removal.
            </p>
            {canFinish && (
                <div>
                    <Dialog.ConfirmTrigger
                        title='Finish the switch by hand?'
                        confirm='Finish switch'
                        pending={finish.isPending}
                        onConfirmed={(_event, close) => finish.mutateAsync().then(close)}
                        trigger={({ onClick }) => (
                            <Button type='button' isSecondary onClick={onClick}>
                                Finish switch by hand
                            </Button>
                        )}
                    >
                        Only do this when the script could not reach the panel but playit on the node already runs with
                        the new key. The panel stops using the old agent and every server on this node gets a new tunnel
                        and address. You can undo it afterwards.
                    </Dialog.ConfirmTrigger>
                </div>
            )}
        </Step>
    );
}

function Setup({ nodeId, state }: { nodeId: number; state: PlayitSwitchState }) {
    usePlayitClaimPolling(nodeId, state);

    return (
        <ol className='mt-4 space-y-5'>
            <ClaimStep nodeId={nodeId} state={state} />
            <SwitchStep nodeId={nodeId} state={state} />
            <FinishStep nodeId={nodeId} state={state} />
        </ol>
    );
}

function Body({ nodeId, state }: { nodeId: number; state: PlayitSwitchState | undefined }) {
    if (state === undefined) {
        return <Spinner size='small' centered />;
    }

    return state.self_managed ? <SelfManaged nodeId={nodeId} state={state} /> : <Setup nodeId={nodeId} state={state} />;
}

/** "Set up automatic tunnels": moves the node to a self-managed playit.gg agent, step by step. */
export default function NodePlayitAutoSetup({ nodeId }: { nodeId: number }) {
    const { data: state } = usePlayitSwitch(nodeId);

    return (
        <TitledGreyBox title='Automatic playit.gg Tunnels' className='mt-6'>
            <Body nodeId={nodeId} state={state} />
        </TitledGreyBox>
    );
}
