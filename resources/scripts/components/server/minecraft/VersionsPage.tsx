import { useId, useMemo, useState } from 'react';
import { toast } from 'sonner';

import { httpErrorToHuman } from '@/api/http';
import { createServerBackupInput, useCreateServerBackup, useServerBackups } from '@/api/server/backups/queries';
import {
    softwareLabel,
    useInstallMinecraftVersion,
    useMinecraftSoftware,
    useMinecraftVersions,
    waitForMinecraftInstall,
    type MinecraftSoftwareInfo,
    type MinecraftSoftwareOption,
    type MinecraftVersion,
} from '@/api/server/minecraft/versions';
import { useCurrentServer, useCurrentServerUuid } from '@/api/server/queries';
import { Alert } from '@/components/elements/alert';
import Button from '@/components/elements/Button';
import { Dialog } from '@/components/elements/dialog';
import PageHeading from '@/components/elements/PageHeading';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import Spinner from '@/components/elements/Spinner';
import Checkbox from '@/components/ui/Checkbox';
import Switch from '@/components/ui/Switch';
import { cn } from '@/lib/cn';
import { useServerPermission } from '@/sdk/core';

/** Where the switch stands, as the page shows it. */
export type SwitchState =
    | { kind: 'idle' }
    | { kind: 'working'; label: string }
    | { kind: 'done'; label: string; installer: boolean }
    | { kind: 'failed'; message: string };

/** How a version reads in the list: "1.21.4", or "21.4.100-beta (Minecraft 1.21.4)" for a loader version. */
export function versionLabel(version: MinecraftVersion): string {
    const name = version.id === version.minecraft ? version.id : `${version.id} (Minecraft ${version.minecraft})`;

    return version.type === 'snapshot' ? `${name} · snapshot` : name;
}

/** What the panel detected the server runs, in words. */
export function currentSoftwareText(current: MinecraftSoftwareInfo['current']): string {
    if (!current.software) {
        return 'The panel could not tell which software this server runs.';
    }

    const version = current.minecraft_version ? ` ${current.minecraft_version}` : '';

    return `This server runs ${softwareLabel(current.software)}${version}, as far as the panel can tell.`;
}

function SoftwarePicker({
    options,
    value,
    onChange,
}: {
    options: readonly MinecraftSoftwareOption[];
    value: string | null;
    onChange: (software: string) => void;
}) {
    const id = useId();

    return (
        <fieldset>
            <legend className='mb-2 text-sm font-medium text-foreground'>Software</legend>
            <div role='radiogroup' aria-label='Software' className='grid gap-2 sm:grid-cols-2 lg:grid-cols-3'>
                {options.map((option) => {
                    const selected = value === option.id;

                    return (
                        <label
                            key={option.id}
                            className={cn(
                                'flex min-h-11 cursor-pointer flex-col gap-1 rounded-lg border p-3 text-sm motion-safe:transition-colors',
                                selected
                                    ? 'border-primary bg-primary/10'
                                    : 'border-border bg-card hover:border-primary/60',
                                !option.available && 'cursor-not-allowed opacity-60'
                            )}
                        >
                            <span className='flex items-center gap-2'>
                                <input
                                    type='radio'
                                    name='minecraft-software'
                                    value={option.id}
                                    checked={selected}
                                    disabled={!option.available}
                                    onChange={() => onChange(option.id)}
                                    aria-labelledby={`${id}-${option.id}`}
                                    aria-describedby={`${id}-${option.id}-about`}
                                    className='h-4 w-4'
                                />
                                <span id={`${id}-${option.id}`} className='font-medium text-foreground'>
                                    {option.label}
                                </span>
                            </span>
                            <span id={`${id}-${option.id}-about`} className='text-xs text-muted-foreground'>
                                {option.available ? option.description : option.unavailable_reason}
                            </span>
                        </label>
                    );
                })}
            </div>
        </fieldset>
    );
}

function VersionSelect({
    uuid,
    software,
    snapshots,
    value,
    onChange,
}: {
    uuid: string;
    software: MinecraftSoftwareOption;
    snapshots: boolean;
    value: string;
    onChange: (version: string) => void;
}) {
    const id = useId();
    const versions = useMinecraftVersions(uuid, software.id, snapshots && software.has_snapshots);

    if (versions.isPending) {
        return <Spinner size='small' />;
    }

    if (versions.error) {
        return <Alert type='danger'>{httpErrorToHuman(versions.error)}</Alert>;
    }

    return (
        <div className='w-full sm:max-w-sm'>
            <label htmlFor={id} className='mb-1 block text-xs font-medium text-muted-foreground'>
                {software.label} version
            </label>
            <select
                id={id}
                className='h-10 w-full rounded-md border border-border bg-background px-2 text-sm text-foreground'
                value={value}
                onChange={(event) => onChange(event.currentTarget.value)}
            >
                <option value=''>Choose a version</option>
                {(versions.data ?? []).map((item) => (
                    <option key={item.id} value={item.id}>
                        {versionLabel(item)}
                    </option>
                ))}
            </select>
        </div>
    );
}

function BackupChoice({
    uuid,
    checked,
    onChange,
}: {
    uuid: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    const id = useId();
    const limit = useCurrentServer((server) => server.attributes.feature_limits.backups) ?? 0;
    const backups = useServerBackups(uuid, 1);
    const used = backups.data?.meta.pagination.total ?? 0;

    if (backups.isPending) {
        return <Spinner size='small' />;
    }

    if (used >= limit) {
        return (
            <p className='text-sm text-muted-foreground'>
                There is no free backup slot. Make room on the Backups page first if you want one.
            </p>
        );
    }

    return (
        <label htmlFor={id} className='flex min-h-9 cursor-pointer items-center gap-2 text-sm'>
            <Checkbox id={id} checked={checked} onChange={onChange} />
            Make a backup first
        </label>
    );
}

function SwitchStatus({ state }: { state: SwitchState }) {
    switch (state.kind) {
        case 'working':
            return (
                <Alert type='info' title={`Switching to ${state.label}`}>
                    The server is stopped and the new jar downloaded and checked. This can take a minute.
                </Alert>
            );
        case 'done':
            return (
                <Alert type='success' title={`Switched to ${state.label}`}>
                    {state.installer
                        ? 'The server is reinstalling with the installer. Watch the console and start it once it is done.'
                        : 'Start the server to run the new version.'}
                </Alert>
            );
        case 'failed':
            return (
                <Alert type='danger' title='The switch failed'>
                    {state.message}
                </Alert>
            );
        case 'idle':
            return null;
    }
}

/** Backs up if asked, queues the switch and follows it to the end. */
function useVersionSwitch(uuid: string) {
    const [state, setState] = useState<SwitchState>({ kind: 'idle' });
    const installer = useInstallMinecraftVersion(uuid);
    const createBackup = useCreateServerBackup();

    const run = async (software: MinecraftSoftwareOption, version: string, backup: boolean) => {
        const label = `${software.label} ${version}`;

        setState({ kind: 'working', label });

        try {
            if (backup) {
                await createBackup.mutateAsync(
                    createServerBackupInput(uuid, { name: `Before ${label}`, isLocked: false })
                );
            }

            const queued = await installer.install(software.id, version);

            // Forge and NeoForge reinstall the server, which locks its pages until that is done.
            if (software.needs_installer) {
                setState({ kind: 'done', label, installer: true });

                return;
            }

            const progress = await waitForMinecraftInstall(uuid, queued.attributes.id);

            if (progress.status === 'failed') {
                setState({ kind: 'failed', message: progress.message ?? 'The switch failed.' });

                return;
            }

            setState({ kind: 'done', label, installer: false });
            toast.success(`Switched to ${label}`);
        } catch (error) {
            setState({ kind: 'failed', message: httpErrorToHuman(error) });
        }
    };

    return { state, run };
}

function VersionSection({
    uuid,
    software,
    onInstall,
    working,
}: {
    uuid: string;
    software: MinecraftSoftwareOption;
    onInstall: (version: string) => void;
    working: boolean;
}) {
    const canManage = useServerPermission('versions.manage');
    const [snapshots, setSnapshots] = useState(false);
    const [version, setVersion] = useState('');

    return (
        <section aria-label='Version' className='space-y-3 rounded-lg bg-card p-4 shadow-sm'>
            {software.has_snapshots && (
                <Switch
                    checked={snapshots}
                    onChange={(next) => {
                        setSnapshots(next);
                        setVersion('');
                    }}
                    label='Show snapshots'
                    description='Snapshots are test builds; they can break worlds.'
                />
            )}
            <VersionSelect
                uuid={uuid}
                software={software}
                snapshots={snapshots}
                value={version}
                onChange={setVersion}
            />
            {software.needs_installer && (
                <p className='text-xs text-muted-foreground'>
                    {software.label} is installed by reinstalling the server with this version. Your world and configs
                    are kept.
                </p>
            )}
            {canManage ? (
                <Button disabled={version === '' || working} isLoading={working} onClick={() => onInstall(version)}>
                    Install
                </Button>
            ) : (
                <p className='text-sm text-muted-foreground'>
                    Switching versions needs the Versions permission. Ask the server owner.
                </p>
            )}
        </section>
    );
}

/** The Minecraft Versions page: switch the server between Vanilla, Paper, Fabric, Forge and the rest. */
export default function VersionsPage() {
    const uuid = useCurrentServerUuid() ?? '';
    const canBackup = useServerPermission('backup.create');
    const info = useMinecraftSoftware(uuid);
    const [software, setSoftware] = useState<string | null>(null);
    const [pending, setPending] = useState<string | null>(null);
    const [backup, setBackup] = useState(false);
    const { state, run } = useVersionSwitch(uuid);
    const options = useMemo(() => info.data?.software ?? [], [info.data]);
    const chosen = options.find((option) => option.id === software) ?? null;

    return (
        <ServerContentBlock title='Versions'>
            <PageHeading
                title='Versions'
                description='Switch the Minecraft software and version. The world, configs and plugins stay; only the server jar changes.'
            />
            <div className='mt-6 space-y-6'>
                {info.isPending && <Spinner size='large' centered />}
                {info.error && (
                    <Alert type='danger' title='Could not load the versions'>
                        {httpErrorToHuman(info.error)}
                    </Alert>
                )}
                {info.data && (
                    <>
                        <p className='text-sm text-muted-foreground'>{currentSoftwareText(info.data.current)}</p>
                        <SoftwarePicker options={options} value={software} onChange={setSoftware} />
                        {chosen && (
                            <VersionSection
                                key={chosen.id}
                                uuid={uuid}
                                software={chosen}
                                working={state.kind === 'working'}
                                onInstall={setPending}
                            />
                        )}
                        <div aria-live='polite'>
                            <SwitchStatus state={state} />
                        </div>
                    </>
                )}
            </div>
            <Dialog.Confirm
                open={pending !== null}
                title={`Install ${chosen?.label ?? ''} ${pending ?? ''}?`}
                confirm='Install'
                onClose={() => setPending(null)}
                onConfirmed={() => {
                    if (chosen && pending) {
                        setPending(null);
                        void run(chosen, pending, backup && canBackup);
                    }
                }}
            >
                <p className='text-sm'>
                    This stops the server and replaces its server jar
                    {chosen?.needs_installer ? ', then reinstalls it with the installer' : ''}. Your world is kept, but
                    plugins and mods may not work on another version. Back up first.
                </p>
                {canBackup && (
                    <div className='mt-3'>
                        <BackupChoice uuid={uuid} checked={backup} onChange={setBackup} />
                    </div>
                )}
            </Dialog.Confirm>
        </ServerContentBlock>
    );
}
