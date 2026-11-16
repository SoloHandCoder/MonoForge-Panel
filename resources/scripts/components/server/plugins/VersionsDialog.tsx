import { useState } from 'react';

import {
    loaderLabel,
    modpackMismatch,
    isNoBackupError,
    useInstallModpack,
    useInstallPlugin,
    usePluginVersions,
    type PlatformOverrides,
    type PluginPlatform,
    type PluginProject,
    type PluginProjectType,
    type PluginVersion,
} from '@/api/server/plugins/queries';
import { Alert } from '@/components/elements/alert';
import Badge from '@/components/elements/Badge';
import Button from '@/components/elements/Button';
import { Dialog } from '@/components/elements/dialog';
import Spinner from '@/components/elements/Spinner';
import { bytesToString } from '@/lib/formatters';

interface Props {
    uuid: string;
    type: PluginProjectType;
    project: PluginProject | null;
    platform: PluginPlatform;
    overrides: PlatformOverrides;
    canManage: boolean;
    onClose: () => void;
}

/** At most this many game versions are listed for a version; the rest are counted. */
const SHOWN_GAME_VERSIONS = 4;

function gameVersions(version: PluginVersion): string {
    const versions = [...version.game_versions].reverse();
    const shown = versions.slice(0, SHOWN_GAME_VERSIONS).join(', ');

    return versions.length > SHOWN_GAME_VERSIONS ? `${shown} +${versions.length - SHOWN_GAME_VERSIONS}` : shown;
}

function VersionRow({
    version,
    type,
    platform,
    canManage,
    busy,
    onInstall,
}: {
    version: PluginVersion;
    type: PluginProjectType;
    platform: PluginPlatform;
    canManage: boolean;
    busy: boolean;
    onInstall: (version: PluginVersion) => void;
}) {
    const mismatch = type === 'modpack' ? modpackMismatch(version, platform) : null;

    return (
        <li className='flex flex-col gap-2 py-3 sm:flex-row sm:items-center'>
            <div className='min-w-0 flex-1'>
                <p className='flex flex-wrap items-center gap-2 text-sm font-medium text-foreground'>
                    <span className='truncate'>{version.name || version.version_number}</span>
                    {version.channel !== 'release' && (
                        <Badge tone='warning' size='sm' variant='soft'>
                            {version.channel}
                        </Badge>
                    )}
                    {mismatch && (
                        <Badge tone='danger' size='sm' variant='soft'>
                            May not match
                        </Badge>
                    )}
                </p>
                <p className='text-xs text-muted-foreground'>
                    {[
                        version.loaders.map(loaderLabel).join(', '),
                        version.game_versions.length > 0 ? `Minecraft ${gameVersions(version)}` : null,
                        version.file ? bytesToString(version.file.size) : null,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </p>
            </div>
            {canManage &&
                (version.installable ? (
                    <Button size='xsmall' disabled={busy} onClick={() => onInstall(version)}>
                        Install<span className='sr-only'> {version.version_number}</span>
                    </Button>
                ) : (
                    <span className='text-xs text-muted-foreground'>Download it from the catalog</span>
                ))}
        </li>
    );
}

const platformName = (platform: PluginPlatform): string =>
    `${loaderLabel(platform.loader)}${platform.game_version ? ` ${platform.game_version}` : ''}`;

function ModpackConfirm({
    project,
    version,
    platform,
    pending,
    withoutBackup,
    onClose,
    onConfirm,
}: {
    project: PluginProject;
    version: PluginVersion;
    platform: PluginPlatform;
    pending: boolean;
    withoutBackup: boolean;
    onClose: () => void;
    onConfirm: () => void;
}) {
    const mismatch = modpackMismatch(version, platform);

    return (
        <Dialog.Confirm
            open
            title={`Install ${project.title} ${version.version_number}?`}
            confirm={withoutBackup ? 'Install without a backup' : 'Install modpack'}
            pending={pending}
            onClose={onClose}
            onConfirmed={onConfirm}
        >
            <div className='space-y-3 text-sm'>
                {withoutBackup && (
                    <Alert type='danger' title='No backup can be made first'>
                        This server has no free backup slot, so nothing can be restored if the modpack breaks it. Free a
                        backup slot and try again, or install without a backup at your own risk.
                    </Alert>
                )}
                {mismatch && (
                    <Alert type='warning' title='Check the server first'>
                        {mismatch} Change the server&apos;s version or loader on the Startup page first if needed.
                    </Alert>
                )}
                <p>
                    The panel backs the server up first when it has a free backup slot, then downloads every mod and
                    replaces config files the modpack ships. Mods already in the mods folder stay.
                </p>
                <p>Stop the server before installing, and start it again when the install is done.</p>
            </div>
        </Dialog.Confirm>
    );
}

function VersionList({
    versions,
    type,
    platform,
    canManage,
    busy,
    onInstall,
}: {
    versions: PluginVersion[];
    type: PluginProjectType;
    platform: PluginPlatform;
    canManage: boolean;
    busy: boolean;
    onInstall: (version: PluginVersion) => void;
}) {
    if (versions.length === 0) {
        return (
            <p className='text-sm text-muted-foreground'>
                No version of this project runs on {platformName(platform)}. Pick another Minecraft version above to see
                more.
            </p>
        );
    }

    return (
        <ul className='max-h-[60vh] divide-y divide-border overflow-y-auto'>
            {versions.map((version) => (
                <VersionRow
                    key={version.id}
                    version={version}
                    type={type}
                    platform={platform}
                    canManage={canManage}
                    busy={busy}
                    onInstall={onInstall}
                />
            ))}
        </ul>
    );
}

/** A project's versions for the server, with an install button on each. */
export default function VersionsDialog({ uuid, type, project, platform, overrides, canManage, onClose }: Props) {
    const versions = usePluginVersions(
        uuid,
        project ? { source: project.source, type, project: project.id } : null,
        overrides
    );
    const plugin = useInstallPlugin(uuid, overrides);
    const modpack = useInstallModpack(uuid);
    const [confirming, setConfirming] = useState<PluginVersion | null>(null);
    const [withoutBackup, setWithoutBackup] = useState(false);
    const busy = plugin.isPending || modpack.isPending;

    const close = () => {
        if (!busy) {
            setConfirming(null);
            onClose();
        }
    };

    const install = (version: PluginVersion) => {
        if (type === 'modpack') {
            setConfirming(version);
        } else if (project) {
            plugin
                .install(project.source, project.id, version.id)
                .then(close)
                .catch(() => null);
        }
    };

    if (confirming && project) {
        return (
            <ModpackConfirm
                project={project}
                version={confirming}
                platform={platform}
                pending={modpack.isPending}
                withoutBackup={withoutBackup}
                onClose={() => {
                    setConfirming(null);
                    setWithoutBackup(false);
                }}
                onConfirm={() =>
                    modpack
                        .install(project.id, confirming.id, withoutBackup)
                        .then(() => {
                            setConfirming(null);
                            setWithoutBackup(false);
                            onClose();
                        })
                        .catch((error: Error) => {
                            if (isNoBackupError(error)) {
                                setWithoutBackup(true);
                            }
                        })
                }
            />
        );
    }

    return (
        <Dialog
            open={project !== null}
            onClose={close}
            preventExternalClose={busy}
            title={project?.title ?? 'Versions'}
            description={
                type === 'modpack'
                    ? 'Each modpack needs a loader and Minecraft version. Versions that may not match this server are marked.'
                    : `Versions for ${platformName(platform)}, newest first.`
            }
        >
            {versions.isError && (
                <Alert type='danger' title='Could not load versions'>
                    The catalog did not answer. Try again in a minute.
                </Alert>
            )}
            {versions.isLoading && <Spinner centered />}
            {versions.data && (
                <VersionList
                    versions={versions.data}
                    type={type}
                    platform={platform}
                    canManage={canManage}
                    busy={busy}
                    onInstall={install}
                />
            )}
            {!canManage && (
                <p className='mt-3 text-xs text-muted-foreground'>
                    Installing needs the plugins permission on this server.
                </p>
            )}
            {busy && (
                <p role='status' className='mt-3 flex items-center gap-2 text-sm text-muted-foreground'>
                    <Spinner size='small' /> Downloading and checking the file…
                </p>
            )}
        </Dialog>
    );
}
