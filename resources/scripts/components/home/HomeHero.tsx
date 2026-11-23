import type { CSSProperties, ReactNode } from 'react';
import { ENTRANCE_DOMAIN } from './serverAddress';

/** The example server the page follows from the console to its public page. */
export const EXAMPLE_NAME = 'Friday Survival';
export const EXAMPLE_SLUG = 'friday-survival';

type Tone = 'info' | 'warn' | 'chat' | 'done';

/** A Paper server starting and two friends joining, as the panel's console shows it. */
const LOG: { time: string; tone: Tone; text: string }[] = [
    { time: '19:58:02', tone: 'info', text: 'Starting minecraft server version 1.21.4' },
    { time: '19:58:03', tone: 'info', text: 'This server is running Paper version 1.21.4-232' },
    { time: '19:58:05', tone: 'info', text: 'Preparing level "world"' },
    { time: '19:58:09', tone: 'done', text: 'Done (6.214s)! For help, type "help"' },
    { time: '20:01:47', tone: 'info', text: 'mira_builds joined the game' },
    { time: '20:02:13', tone: 'chat', text: '<mira_builds> anyone up for the nether tonight?' },
    { time: '20:03:30', tone: 'info', text: 'tom_on_a_boat joined the game' },
    { time: '20:03:41', tone: 'chat', text: '<tom_on_a_boat> bringing the boat obviously' },
    { time: '20:05:02', tone: 'warn', text: "Can't keep up! Is the server overloaded? Running 2051ms behind" },
];

const step = (index: number) => ({ '--i': index }) as CSSProperties;

/**
 * A crop of the server console: the server bar with its address and the log. Drawn in HTML as
 * the panel draws it, so it is a picture of the product rather than a screenshot.
 */
export function ConsoleFragment() {
    return (
        <div
            data-ui
            role='img'
            aria-label={`The panel's console for the server ${EXAMPLE_NAME}: Paper 1.21.4 starts, then two players join and chat.`}
            className='flex w-full flex-col'
        >
            <div className='ui-bar'>
                <span className='ui-pill'>
                    <span className='ui-dot' aria-hidden />
                    Online
                </span>
                <span className='font-semibold'>{EXAMPLE_NAME}</span>
                <span className='ui-muted ml-auto hidden truncate sm:inline'>
                    {EXAMPLE_SLUG}.{ENTRANCE_DOMAIN}
                </span>
            </div>
            <pre className='ui-term ui-code m-0 overflow-hidden px-4 py-3 whitespace-pre'>
                {LOG.map((line, index) => (
                    <span key={line.time} className='home-log-line block' style={step(index)}>
                        <span className='t-time'>[{line.time} </span>
                        <span className={line.tone === 'warn' ? 't-warn' : 't-info'}>
                            {line.tone === 'warn' ? 'WARN' : 'INFO'}
                        </span>
                        <span className='t-time'>]: </span>
                        <span className={`t-${line.tone}`}>{line.text}</span>
                    </span>
                ))}
            </pre>
            <div className='ui-bar ui-bar--foot'>
                <code className='ui-code'>
                    <span className='ui-muted'>&gt; </span>say back in ten, feeding the cats
                </code>
            </div>
        </div>
    );
}

/**
 * The first screen: the name set across the width on two stepped lines, then what MonoForge is,
 * the way in and the console of a running server.
 */
export default function HomeHero({ action }: { action: ReactNode }) {
    return (
        <section aria-labelledby='home-title' className='home-hero home-wrap'>
            <h1 id='home-title' aria-label='MonoForge' className='home-monument pt-[clamp(1rem,3vw,2.5rem)]'>
                <span className='home-line'>
                    <span style={step(0)}>Mono</span>
                </span>
                <span className='home-line home-hero-forge'>
                    <span style={step(1)}>Forge</span>
                </span>
            </h1>
            <div className='home-hero-after home-grid flex-1 items-start gap-y-8 pt-10 md:pt-14'>
                <div className='col-span-12 md:col-span-5 lg:col-span-4'>
                    <p className='home-lead max-w-[30ch] font-medium'>
                        Minecraft Java and Terraria servers on our own machines in Switzerland, paid in tokens that
                        never expire.
                    </p>
                    <div className='mt-8 flex flex-wrap items-center gap-x-6 gap-y-4'>
                        {action}
                        <a href='#plans' className='home-link'>
                            See the plans
                        </a>
                    </div>
                </div>
                <div className='col-span-12 min-w-0 md:col-span-7 lg:col-span-7 lg:col-start-6'>
                    <ConsoleFragment />
                </div>
            </div>
        </section>
    );
}
