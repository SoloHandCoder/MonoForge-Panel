import { useId, useState } from 'react';
import { RotateCcw } from 'lucide-react';

import {
    loaderLabel,
    modpackRunning,
    useModpackProgress,
    usePluginEnvironment,
    type PlatformOverrides,
    type ModpackProgress,
    type PluginEnvironment,
} from '@/api/server/plugins/queries';
import { useCurrentServerUuid } from '@/api/server/queries';
import { Alert } from '@/components/elements/alert';
import Button from '@/components/elements/Button';
import PageHeading from '@/components/elements/PageHeading';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import Spinner from '@/components/elements/Spinner';
import { TextInput } from '@/components/form/controls';
import Select from '@/components/ui/Select';
import { cn } from '@/lib/cn';
import { usePersistedState } from '@/plugins/usePersistedState';
import { useServerPermission } from '@/sdk/core';
import BrowseTab from './BrowseTab';
import InstalledTab from './InstalledTab';
import ModpackProgressPanel from './ModpackProgressPanel';

type Tab = 'browse' | 'installed' | 'modpacks';

const GAME_VERSION = /^\d{1,2}\.\d{1,2}(\.\d{1,2})?(-(pre|rc)\d{1,2})?$/;

const DETECTED_FROM = new Map<string, string>([
    ['files', 'the server files'],
    ['egg', 'the egg'],
    ['variable', 'a startup variable'],
    ['override', 'your choice'],
]);

const detectedFromLabel = (from: string[]): string =>
    from.map((source) => DETECTED_FROM.get(source) ?? source).join(' and ');

function PlatformBar({
    environment,
    overrides,
    onChange,
}: {
    environment: PluginEnvironment;
    overrides: PlatformOverrides;
    onChange: (overrides: PlatformOverrides) => void;
}) {
    const id = useId();
    const [version, setVersion] = useState(overrides.game_version ?? '');
    const { detected, platform } = environment;
    const overridden = !!overrides.loader || !!overrides.game_version;
    const versionInvalid = version !== '' && !GAME_VERSION.test(version);

    return (
        <section aria-label='Server platform' className='rounded-lg bg-card p-4 shadow-sm'>
            <p className='text-sm text-muted-foreground'>
                {detected.loader
                    ? `Detected ${loaderLabel(detected.loader)}${detected.game_version ? ` ${detected.game_version}` : ''} from ${detectedFromLabel(detected.detected_from) || 'the server'}.`
                    : 'The panel could not tell which loader this server runs. Choose it below.'}
            </p>
            <div className='mt-3 flex flex-col gap-3 sm:flex-row sm:items-end'>
                <div className='w-full sm:max-w-xs'>
                    <label htmlFor={`${id}-loader`} className='mb-1 block text-xs font-medium text-muted-foreground'>
                        Loader
                    </label>
                    <Select
                        id={`${id}-loader`}
                        options={environment.loaders.map((loader) => ({ value: loader, label: loaderLabel(loader) }))}
                        value={platform.loader ?? ''}
                        placeholder='Choose a loader'
                        searchPlaceholder='Search loaders…'
                        onChange={(value) =>
                            onChange({ ...overrides, loader: String(value) === detected.loader ? null : String(value) })
                        }
                    />
                </div>
                <div className='w-full sm:max-w-[12rem]'>
                    <label htmlFor={`${id}-version`} className='mb-1 block text-xs font-medium text-muted-foreground'>
                        Minecraft version
                    </label>
                    <TextInput
                        id={`${id}-version`}
                        inputMode='decimal'
                        placeholder={detected.game_version ?? '1.21.4'}
                        value={version}
                        $hasError={versionInvalid}
                        aria-invalid={versionInvalid}
                        aria-describedby={versionInvalid ? `${id}-version-error` : undefined}
                        onChange={(event) => setVersion(event.currentTarget.value.trim())}
                        onBlur={() => {
                            if (!versionInvalid) {
                                onChange({
                                    ...overrides,
                                    game_version: version === '' || version === detected.game_version ? null : version,
                                });
                            }
                        }}
                    />
                    {versionInvalid && (
                        <p id={`${id}-version-error`} className='mt-1 text-xs text-destructive'>
                            Use a version such as 1.21.4.
                        </p>
                    )}
                </div>
                {overridden && (
                    <Button.Text
                        size='small'
                        className='inline-flex items-center gap-2'
                        onClick={() => {
                            setVersion('');
                            onChange({});
                        }}
                    >
                        <RotateCcw aria-hidden className='h-4 w-4' /> Use detected
                    </Button.Text>
                )}
            </div>
        </section>
    );
}

function TabList({
    tabs,
    tab,
    onSelect,
}: {
    tabs: { id: Tab; label: string }[];
    tab: Tab;
    onSelect: (tab: Tab) => void;
}) {
    return (
        <div role='tablist' aria-label='Plugins' className='flex gap-1 overflow-x-auto border-b border-border'>
            {tabs.map((item) => (
                <button
                    key={item.id}
                    type='button'
                    role='tab'
                    id={`plugins-tab-${item.id}`}
                    aria-selected={tab === item.id}
                    aria-controls={`plugins-panel-${item.id}`}
                    className={cn(
                        'whitespace-nowrap px-4 py-2 text-sm motion-safe:transition-colors',
                        tab === item.id
                            ? 'border-b-2 border-primary font-medium text-foreground'
                            : 'text-muted-foreground hover:text-foreground'
                    )}
                    onClick={() => onSelect(item.id)}
                >
                    {item.label}
                </button>
            ))}
        </div>
    );
}

interface PanelProps {
    uuid: string;
    environment: PluginEnvironment;
    overrides: PlatformOverrides;
    canManage: boolean;
}

function BrowsePanel({ uuid, environment, overrides, canManage }: PanelProps) {
    const { platform } = environment;

    if (!platform.type) {
        return (
            <Alert type='info' title='Choose a loader'>
                Pick the loader your server runs above to see plugins or mods for it.
            </Alert>
        );
    }

    return (
        <BrowseTab
            key={`${platform.loader}:${platform.game_version}`}
            uuid={uuid}
            type={platform.type === 'mod' ? 'mod' : 'plugin'}
            environment={environment}
            overrides={overrides}
            canManage={canManage}
        />
    );
}

function ModpacksPanel({
    uuid,
    environment,
    overrides,
    canManage,
    progress,
}: PanelProps & { progress: ModpackProgress | undefined }) {
    const { platform } = environment;

    return (
        <div className='space-y-4'>
            <ModpackProgressPanel progress={progress} />
            <BrowseTab
                key={`modpack:${platform.loader}:${platform.game_version}`}
                uuid={uuid}
                type='modpack'
                environment={environment}
                overrides={overrides}
                canManage={canManage && !modpackRunning(progress)}
            />
        </div>
    );
}

function PluginsContent({
    uuid,
    environment,
    overrides,
    onOverridesChange,
}: {
    uuid: string;
    environment: PluginEnvironment;
    overrides: PlatformOverrides;
    onOverridesChange: (overrides: PlatformOverrides) => void;
}) {
    const canManage = useServerPermission('plugins.manage');
    const [tab, setTab] = useState<Tab>('browse');
    const progress = useModpackProgress(uuid);
    const panel = { uuid, environment, overrides, canManage };
    const tabs: { id: Tab; label: string }[] = [
        { id: 'browse', label: environment.platform.type === 'mod' ? 'Browse mods' : 'Browse plugins' },
        { id: 'installed', label: 'Installed' },
        { id: 'modpacks', label: 'Modpacks' },
    ];

    return (
        <>
            <PlatformBar
                key={`${environment.detected.loader}:${environment.detected.game_version}`}
                environment={environment}
                overrides={overrides}
                onChange={onOverridesChange}
            />
            {modpackRunning(progress.data) && tab !== 'modpacks' && (
                <Alert type='info' title='A modpack is being installed'>
                    <button type='button' className='underline' onClick={() => setTab('modpacks')}>
                        See its progress
                    </button>
                </Alert>
            )}
            <TabList tabs={tabs} tab={tab} onSelect={setTab} />
            <div role='tabpanel' id={`plugins-panel-${tab}`} aria-labelledby={`plugins-tab-${tab}`}>
                {tab === 'browse' && <BrowsePanel {...panel} />}
                {tab === 'installed' && <InstalledTab {...panel} />}
                {tab === 'modpacks' && <ModpacksPanel {...panel} progress={progress.data} />}
            </div>
        </>
    );
}

/** One-click plugins, mods and Modrinth modpacks, at /server/$id/plugins. */
export default function PluginsPage() {
    const uuid = useCurrentServerUuid() ?? '';
    const [stored, setStored] = usePersistedState<PlatformOverrides>(`monoforge:plugins:${uuid}:platform`, {});
    const overrides = stored ?? {};
    const environment = usePluginEnvironment(uuid, overrides);

    return (
        <ServerContentBlock title='Plugins & mods'>
            <PageHeading
                title='Plugins & mods'
                description='Install plugins, mods and modpacks from Modrinth, Hangar and CurseForge. Files are checked before they reach your server.'
            />
            <div className='mt-6 space-y-4'>
                {environment.isError && !environment.data && (
                    <Alert type='danger' title='Could not read the server'>
                        The panel could not look at the server files. Check that the node is online, then reload.
                    </Alert>
                )}
                {environment.isPending && <Spinner size='large' centered />}
                {environment.data && (
                    <PluginsContent
                        uuid={uuid}
                        environment={environment.data}
                        overrides={overrides}
                        onOverridesChange={setStored}
                    />
                )}
            </div>
        </ServerContentBlock>
    );
}
