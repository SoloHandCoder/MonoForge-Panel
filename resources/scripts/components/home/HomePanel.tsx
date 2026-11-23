import type { ReactNode } from 'react';
import { rise, useReveal } from './useReveal';

/** The Versions page: the server types, the chosen one and its version. */
function VersionsFragment() {
    const types = ['Vanilla', 'Paper', 'Purpur', 'Fabric', 'Forge', 'NeoForge'];

    return (
        <div data-ui role='img' aria-label='The Versions page with Paper 1.21.4 chosen and the Install button.'>
            <div className='ui-bar justify-between'>
                <span className='font-semibold'>Versions</span>
                <span className='ui-muted'>Runs Vanilla 1.21.1</span>
            </div>
            <div className='grid grid-cols-[1fr_1.1fr]'>
                <div className='ui-split'>
                    {types.map((type) => (
                        <div key={type} className='ui-row' aria-selected={type === 'Paper'}>
                            {type}
                        </div>
                    ))}
                </div>
                <div className='flex flex-col gap-3 p-4'>
                    <span className='ui-muted'>Version</span>
                    <span className='ui-field flex justify-between'>
                        1.21.4 <span className='ui-muted'>latest</span>
                    </span>
                    <span className='ui-muted'>Take a backup first</span>
                    <span className='ui-field'>On</span>
                    <span className='ui-button mt-auto'>Install Paper 1.21.4</span>
                </div>
            </div>
        </div>
    );
}

/** Plugins & mods: a search over Modrinth and Hangar. */
function PluginsFragment() {
    const results = [
        { name: 'Simple Voice Chat', from: 'Modrinth', kind: 'Plugin' },
        { name: 'Chunky', from: 'Hangar', kind: 'Plugin' },
        { name: 'LuckPerms', from: 'Modrinth', kind: 'Plugin' },
        { name: 'Fabulously Optimized', from: 'Modrinth', kind: 'Modpack' },
    ];

    return (
        <div
            data-ui
            role='img'
            aria-label='Plugins and mods: search results from Modrinth and Hangar with Install buttons.'
        >
            <div className='ui-bar'>
                <span className='ui-field ui-muted flex-1'>Search plugins, mods and modpacks</span>
            </div>
            {results.map((result) => (
                <div key={result.name} className='ui-row'>
                    <span className='min-w-0 flex-1'>
                        <span className='block truncate font-medium'>{result.name}</span>
                        <span className='ui-muted'>
                            {result.kind} on {result.from}
                        </span>
                    </span>
                    <span className='ui-button ui-button--ghost'>Install</span>
                </div>
            ))}
        </div>
    );
}

/** The file editor with a Skript file open, highlighted as the editor does it. */
function EditorFragment() {
    return (
        <div data-ui role='img' aria-label='The file editor with a Skript script open, its syntax highlighted.'>
            <div className='ui-bar'>
                <span className='ui-muted truncate'>plugins/Skript/scripts/</span>
                <span className='font-medium'>welcome.sk</span>
                <span className='ui-button ml-auto'>Save</span>
            </div>
            <pre className='ui-term ui-code m-0 overflow-hidden px-4 py-3'>
                <span className='sk-comment'># greet everyone by name</span>
                {'\n'}
                <span className='sk-event'>on join</span>:{'\n'}
                {'    '}
                <span className='sk-keyword'>if</span> <span className='sk-expr'>player</span> has played before:
                {'\n'}
                {'        '}
                <span className='sk-keyword'>send</span> <span className='sk-string'>"Welcome back, </span>
                <span className='sk-expr'>%player%</span>
                <span className='sk-string'>!"</span> to <span className='sk-expr'>player</span>
                {'\n'}
                {'    '}
                <span className='sk-keyword'>else</span>:{'\n'}
                {'        '}
                <span className='sk-keyword'>broadcast</span> <span className='sk-string'>"Say hi to </span>
                <span className='sk-expr'>%player%</span>
                <span className='sk-string'>, it is their first night"</span>
            </pre>
        </div>
    );
}

/** Backups, with the one taken before an update and the nightly ones. */
function BackupsFragment() {
    const backups = [
        { name: 'Before Paper 1.21.4', size: '1.4 GB', when: 'Today, 19:52', locked: true },
        { name: 'Nightly', size: '1.3 GB', when: 'Today, 04:00', locked: false },
        { name: 'Nightly', size: '1.3 GB', when: 'Yesterday, 04:00', locked: false },
    ];

    return (
        <div
            data-ui
            role='img'
            aria-label='The backups list: one locked backup before an update and two nightly backups.'
        >
            <div className='ui-bar justify-between'>
                <span className='font-semibold'>Backups</span>
                <span className='ui-muted'>3 of 5</span>
            </div>
            {backups.map((backup) => (
                <div key={backup.when} className='ui-row'>
                    <span className='min-w-0 flex-1'>
                        <span className='block truncate font-medium'>
                            {backup.name}
                            {backup.locked && <span className='ui-muted'> · locked</span>}
                        </span>
                        <span className='ui-muted'>
                            {backup.when} · {backup.size}
                        </span>
                    </span>
                    <span className='ui-button ui-button--ghost'>Restore</span>
                </div>
            ))}
        </div>
    );
}

/** Schedules' quick setup: a daily restart with a warning, and backups every six hours. */
function SchedulesFragment() {
    return (
        <div
            data-ui
            role='img'
            aria-label='Schedules quick setup: restart at 06:00 with a five minute warning, back up every 6 hours.'
        >
            <div className='ui-bar'>
                <span className='font-semibold'>Quick setup</span>
            </div>
            <div className='grid gap-3 p-4'>
                <span className='ui-muted'>Restart at</span>
                <span className='ui-field'>06:00, every day</span>
                <span className='ui-muted'>Warn players (minutes ahead)</span>
                <span className='ui-field'>5</span>
                <span className='ui-muted'>Back up</span>
                <span className='ui-field'>Every 6 hours, keep 4</span>
            </div>
        </div>
    );
}

/** The assistant answering a crash, as the crash doctor does after a failed start. */
function AssistantFragment() {
    return (
        <div data-ui role='img' aria-label='The AI assistant explaining a crash: a mod needs Fabric API, with the fix.'>
            <div className='ui-bar'>
                <span className='font-semibold'>Assistant</span>
                <span className='ui-muted'>Crash doctor</span>
            </div>
            <div className='grid gap-3 p-4'>
                <p className='ui-field m-0 justify-self-end'>It crashed after I added a mod</p>
                <p className='m-0'>
                    The crash log says <strong>Sodium</strong> needs <strong>Fabric API</strong>, which is not
                    installed. Install Fabric API from Plugins &amp; mods, then start the server again.
                </p>
                <span className='ui-button justify-self-start'>Open Plugins &amp; mods</span>
            </div>
        </div>
    );
}

/** A support ticket with a staff reply. */
function SupportFragment() {
    return (
        <div data-ui role='img' aria-label='A support ticket about a world that will not load, answered by staff.'>
            <div className='ui-bar justify-between'>
                <span className='font-semibold'>World won’t load after a restore</span>
                <span className='ui-pill'>Answered</span>
            </div>
            <div className='grid gap-3 p-4'>
                <p className='m-0'>
                    <span className='ui-muted'>You · </span>Restored last night’s backup and now the world is empty?
                </p>
                <p className='m-0'>
                    <span className='ui-muted'>Staff · </span>The restore put it in world-1. Rename it to world in Files
                    and start again, or ping us on Discord and we’ll do it with you.
                </p>
            </div>
        </div>
    );
}

interface Feature {
    title: string;
    text: string;
    picture: ReactNode;
}

/** Six in either case, so the two columns never end on a gap; with the assistant on, it takes the schedules' place. */
export function features(assistant: boolean): Feature[] {
    return [
        {
            title: 'Every Minecraft version, one click',
            text: 'Switch between Vanilla, Paper, Purpur, Fabric, Forge and NeoForge and pick the version. The panel offers a backup before it installs.',
            picture: <VersionsFragment />,
        },
        {
            title: 'Plugins, mods and modpacks',
            text: 'Search Modrinth and Hangar from the server’s page and install what you find, or upload your own files.',
            picture: <PluginsFragment />,
        },
        {
            title: 'A file editor that knows Skript',
            text: 'Edit configs and scripts in the browser, with highlighting for Skript and HyperScript. No FTP client needed.',
            picture: <EditorFragment />,
        },
        {
            title: 'Backups you can lock',
            text: 'Take one before a big change, restore it in a click, and lock the ones you never want rotated out.',
            picture: <BackupsFragment />,
        },
        assistant
            ? {
                  title: 'An assistant for crashes',
                  text: 'When a start fails, the crash doctor reads the log and says what broke in plain words, with the fix.',
                  picture: <AssistantFragment />,
              }
            : {
                  title: 'Schedules without cron',
                  text: 'Quick setup makes the usual ones for you: a daily restart with a warning to players, and regular backups.',
                  picture: <SchedulesFragment />,
              },
        {
            title: 'People on Discord',
            text: 'Ask on our Discord or open a support ticket from the panel. Real people answer, about setup, mods and anything else.',
            picture: <SupportFragment />,
        },
    ];
}

/** What running a server looks like: six parts of the panel, each shown as itself. */
export default function HomePanel({ assistant }: { assistant: boolean }) {
    const ref = useReveal<HTMLElement>();

    return (
        <section
            ref={ref}
            id='panel'
            data-reveal
            aria-labelledby='panel-title'
            className='home-section home-wrap bg-(--home-surface)'
        >
            <div className='home-grid gap-y-6'>
                <h2 id='panel-title' className='home-heading home-rise col-span-12 lg:col-span-5'>
                    Run it all from your browser
                </h2>
                <p className='home-lead home-rise col-span-12 lg:col-span-6 lg:col-start-7' style={rise(1)}>
                    The panel is the same one you will use: a console you can read, your files, your versions and your
                    backups, built for players rather than system admins.
                </p>
            </div>
            <ul className='mt-16 grid grid-cols-1 gap-x-(--home-gutter) gap-y-20 lg:grid-cols-2'>
                {features(assistant).map((feature, index) => (
                    <li
                        key={feature.title}
                        className='home-rise grid grid-cols-1 gap-6 sm:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)]'
                        style={rise(index % 2)}
                    >
                        <div className='min-w-0'>{feature.picture}</div>
                        <div>
                            <h3 className='home-lead font-semibold'>{feature.title}</h3>
                            <p className='home-body mt-2'>{feature.text}</p>
                        </div>
                    </li>
                ))}
            </ul>
        </section>
    );
}
