import { useState } from 'react';
import { ArrowUpCircle, Package, Trash2 } from 'lucide-react';

import {
    useInstalledPlugins,
    useRemoveInstalledPlugin,
    useUpdateInstalledPlugin,
    type InstalledPlugin,
    type PlatformOverrides,
    type PluginEnvironment,
} from '@/api/server/plugins/queries';
import { Alert } from '@/components/elements/alert';
import Badge from '@/components/elements/Badge';
import Button from '@/components/elements/Button';
import { Dialog } from '@/components/elements/dialog';
import Spinner from '@/components/elements/Spinner';
import { bytesToString } from '@/lib/formatters';

interface Props {
    uuid: string;
    environment: PluginEnvironment;
    overrides: PlatformOverrides;
    canManage: boolean;
}

function InstalledRow({
    jar,
    canManage,
    updating,
    onUpdate,
    onRemove,
}: {
    jar: InstalledPlugin;
    canManage: boolean;
    updating: boolean;
    onUpdate: () => void;
    onRemove: () => void;
}) {
    return (
        <li className='flex flex-col gap-3 rounded-lg bg-card p-4 shadow-sm sm:flex-row sm:items-center'>
            <div className='flex min-w-0 flex-1 items-start gap-3'>
                {jar.project?.icon_url ? (
                    <img
                        src={jar.project.icon_url}
                        alt=''
                        loading='lazy'
                        referrerPolicy='no-referrer'
                        className='h-10 w-10 shrink-0 rounded-md bg-muted object-cover'
                    />
                ) : (
                    <span className='flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-muted'>
                        <Package aria-hidden className='h-5 w-5 text-muted-foreground' />
                    </span>
                )}
                <div className='min-w-0'>
                    <p className='flex flex-wrap items-center gap-2 font-medium text-foreground'>
                        {jar.project ? (
                            <a
                                href={jar.project.url}
                                target='_blank'
                                rel='noopener noreferrer'
                                className='truncate hover:underline'
                            >
                                {jar.project.title}
                            </a>
                        ) : (
                            <span className='truncate'>{jar.file_name}</span>
                        )}
                        {jar.version && (
                            <span className='text-xs font-normal text-muted-foreground'>
                                {jar.version.version_number}
                            </span>
                        )}
                        {jar.update && (
                            <Badge tone='info' size='sm' variant='soft'>
                                Update: {jar.update.version_number}
                            </Badge>
                        )}
                    </p>
                    <p className='truncate text-xs text-muted-foreground'>
                        {jar.project ? `${jar.file_name} · ` : 'Not found on Modrinth · '}
                        {bytesToString(jar.size)}
                    </p>
                </div>
            </div>
            {canManage && (
                <div className='flex shrink-0 items-center gap-2'>
                    {jar.update && (
                        <Button
                            size='xsmall'
                            isLoading={updating}
                            className='inline-flex items-center gap-1'
                            onClick={onUpdate}
                        >
                            <ArrowUpCircle aria-hidden className='h-4 w-4' />
                            Update<span className='sr-only'> {jar.file_name}</span>
                        </Button>
                    )}
                    <Button.Danger
                        size='xsmall'
                        isSecondary
                        disabled={updating}
                        className='inline-flex items-center gap-1'
                        onClick={onRemove}
                    >
                        <Trash2 aria-hidden className='h-4 w-4' />
                        Remove<span className='sr-only'> {jar.file_name}</span>
                    </Button.Danger>
                </div>
            )}
        </li>
    );
}

function summary(jars: InstalledPlugin[], directory: string): string {
    if (jars.length === 0) {
        return `No jars in ${directory}/ yet.`;
    }

    const updates = jars.filter((jar) => jar.update !== null).length;
    const count = `${jars.length} ${jars.length === 1 ? 'jar' : 'jars'} in ${directory}/`;

    return updates > 0 ? `${count}, ${updates} with an update.` : `${count}.`;
}

function RemoveDialog({
    jar,
    directory,
    pending,
    onClose,
    onConfirm,
}: {
    jar: InstalledPlugin | null;
    directory: string;
    pending: boolean;
    onClose: () => void;
    onConfirm: (jar: InstalledPlugin) => void;
}) {
    return (
        <Dialog.Confirm
            open={jar !== null}
            title={`Remove ${jar?.project?.title ?? jar?.file_name ?? ''}?`}
            confirm='Remove'
            pending={pending}
            onClose={onClose}
            onConfirmed={() => jar && onConfirm(jar)}
        >
            {jar?.file_name} is deleted from {directory}/. Restart the server for it to take effect.
        </Dialog.Confirm>
    );
}

/** The jars in plugins/ or mods/, with updates from Modrinth. */
function InstalledList({ uuid, directory, overrides, canManage }: Omit<Props, 'environment'> & { directory: string }) {
    const installed = useInstalledPlugins(uuid, overrides, true);
    const updater = useUpdateInstalledPlugin(uuid, overrides);
    const remover = useRemoveInstalledPlugin(uuid, overrides);
    const [updating, setUpdating] = useState<string | null>(null);
    const [removing, setRemoving] = useState<InstalledPlugin | null>(null);
    const jars = installed.data?.data ?? [];

    const update = (jar: InstalledPlugin) => {
        setUpdating(jar.file_name);
        updater
            .update(jar.file_name)
            .catch(() => null)
            .finally(() => setUpdating(null));
    };

    return (
        <div className='mt-4 space-y-3'>
            {installed.isError && (
                <Alert type='danger' title='Could not list the files'>
                    The panel could not read {directory}/. Check that the node is online.
                </Alert>
            )}
            {installed.isPending && (
                <div role='status' className='flex flex-col items-center gap-2 py-8 text-sm text-muted-foreground'>
                    <Spinner size='large' />
                    Checking {directory}/ against Modrinth…
                </div>
            )}
            {installed.data && (
                <>
                    <p className='text-sm text-muted-foreground' aria-live='polite'>
                        {summary(jars, directory)}
                    </p>
                    <ul className='space-y-2'>
                        {jars.map((jar) => (
                            <InstalledRow
                                key={jar.file_name}
                                jar={jar}
                                canManage={canManage}
                                updating={updating === jar.file_name}
                                onUpdate={() => update(jar)}
                                onRemove={() => setRemoving(jar)}
                            />
                        ))}
                    </ul>
                </>
            )}
            <RemoveDialog
                jar={removing}
                directory={directory}
                pending={remover.isPending}
                onClose={() => setRemoving(null)}
                onConfirm={(jar) =>
                    remover
                        .remove(jar.file_name)
                        .then(() => setRemoving(null))
                        .catch(() => null)
                }
            />
        </div>
    );
}

export default function InstalledTab({ environment, ...props }: Props) {
    const { directory } = environment.platform;

    if (directory === null) {
        return (
            <Alert type='info' title='Choose a loader'>
                Pick the loader your server runs above to see its plugins or mods.
            </Alert>
        );
    }

    return <InstalledList {...props} directory={directory} />;
}
