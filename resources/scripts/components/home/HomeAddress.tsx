import { Fragment, useId, useState, type ReactNode } from 'react';
import type { PublicCategory } from '@/api/public/storePlans';
import { BlockArt, TreeArt } from './GameArt';
import { EXAMPLE_NAME } from './HomeHero';
import { cheapestMonthly } from './HomePlans';
import { serverAddress, NAME_MAX } from './serverAddress';
import { rise, useReveal } from './useReveal';

interface Game {
    name: string;
    match: string;
    blurb: string;
    art: ReactNode;
}

export const FEATURED_GAMES: Game[] = [
    {
        name: 'Minecraft: Java Edition',
        match: 'minecraft',
        blurb: 'Survival with friends, a modded world or a plugin-packed server, on the server type you pick.',
        art: <BlockArt className='size-12' />,
    },
    {
        name: 'Terraria',
        match: 'terraria',
        blurb: 'Dig, build and fight bosses together on a dedicated world that stays online when you log off.',
        art: <TreeArt className='size-12' />,
    },
];

/** The host the public pages are served from: this panel's own. */
const panelHost = () => window.location.host;

/**
 * A crop of a public server page as `/s/<name>` shows it: the name, its state, how to join and the
 * votes. It follows what the visitor types.
 */
function PageFragment({ game, name, slug, host }: { game: Game; name: string; slug: string; host: string | null }) {
    const minecraft = game.match === 'minecraft';

    return (
        <div data-ui className='flex w-full flex-col'>
            <div className='ui-bar'>
                <span className='ui-muted truncate'>
                    {panelHost()}/s/<span className='text-foreground'>{slug}</span>
                </span>
            </div>
            <div className='flex flex-col gap-5 p-5 sm:p-7'>
                <div className='flex items-center gap-4'>
                    <span className='grid size-16 shrink-0 place-items-center rounded-xl bg-(--active-item)'>
                        {game.art}
                    </span>
                    <div className='min-w-0'>
                        <p className='truncate text-2xl font-bold'>{name}</p>
                        <p className='mt-1 flex flex-wrap items-center gap-2'>
                            <span className='ui-pill'>
                                <span className='ui-dot' aria-hidden />
                                Online
                            </span>
                            <span className='ui-muted'>{minecraft ? '3 / 20 players' : '2 / 8 players'}</span>
                        </p>
                    </div>
                </div>
                <p className='ui-muted'>
                    {minecraft
                        ? 'Vanilla survival for the Friday crew. Be nice to the creepers.'
                        : 'Expert mode, one big world, the Wall of Flesh is next.'}
                </p>
                <div className='rounded-lg border border-border p-4'>
                    <p className='ui-muted font-medium'>How to join</p>
                    <div className='mt-2 flex items-center gap-2'>
                        {minecraft ? (
                            <code className='ui-field ui-code min-w-0 flex-1 truncate'>{host ?? '…'}</code>
                        ) : (
                            <span className='ui-field ui-muted min-w-0 flex-1 truncate'>
                                Your playit.gg address and port
                            </span>
                        )}
                        <span className='ui-button'>Copy address</span>
                    </div>
                </div>
                <div className='flex items-center justify-between rounded-lg border border-border p-4'>
                    <div>
                        <p className='ui-muted font-medium'>Votes</p>
                        <p className='text-xl font-semibold tabular-nums'>12</p>
                    </div>
                    <span className='ui-button ui-button--ghost'>Vote</span>
                </div>
            </div>
        </div>
    );
}

/**
 * The games, and the one thing a visitor can try: name a server and see the page address and the
 * join address it would get. A worked example is shown before anything is typed.
 */
export default function HomeAddress({
    categories,
    primary,
    secondary,
}: {
    categories: PublicCategory[];
    primary: ReactNode;
    secondary: ReactNode;
}) {
    const ref = useReveal<HTMLElement>();
    const inputId = useId();
    const [gameIndex, setGameIndex] = useState(0);
    const [name, setName] = useState('');
    const game = FEATURED_GAMES[gameIndex];
    const shown = name.trim() === '' ? EXAMPLE_NAME : name.trim();
    const address = serverAddress(shown);
    const category = categories.find((entry) => entry.slug.toLowerCase().includes(game.match));
    const from = category ? cheapestMonthly(category) : null;
    const others = categories.filter(
        (entry) => !FEATURED_GAMES.some((featured) => entry.slug.toLowerCase().includes(featured.match))
    );

    return (
        <section
            ref={ref}
            id='games'
            data-reveal
            aria-labelledby='games-title'
            className='home-section home-wrap home-grid gap-y-10'
        >
            <div className='home-rise col-span-12 lg:col-span-6 lg:self-center' style={rise(2)}>
                <PageFragment
                    game={game}
                    name={shown}
                    slug={address.slug || '…'}
                    host={address.ok ? address.host : null}
                />
            </div>
            <div className='col-span-12 flex flex-col gap-10 lg:col-span-5 lg:col-start-8 lg:justify-between'>
                <div>
                    <div role='radiogroup' aria-label='Game' className='home-rise flex flex-wrap gap-x-8 gap-y-2'>
                        {FEATURED_GAMES.map((entry, index) => (
                            <button
                                key={entry.match}
                                type='button'
                                role='radio'
                                aria-checked={index === gameIndex}
                                onClick={() => setGameIndex(index)}
                                className='home-choice'
                            >
                                {entry.name}
                            </button>
                        ))}
                    </div>
                    <h2 id='games-title' className='home-heading home-rise mt-10' style={rise(1)}>
                        Pick a game, name your server
                    </h2>
                    <p className='home-body home-rise mt-5 max-w-[42ch]' style={rise(2)}>
                        {game.blurb}
                    </p>
                    <p className='home-lead home-rise mt-3 font-semibold' style={rise(2)}>
                        {from === null ? 'Plans coming soon' : `From ${from.toLocaleString('en-US')} tokens a month`}
                    </p>
                    {others.length > 0 && (
                        <p className='home-body home-muted mt-2'>
                            Also on sale: {others.map((entry) => entry.name).join(', ')}.
                        </p>
                    )}
                </div>
                <div className='home-rise' style={rise(3)}>
                    <label htmlFor={inputId} className='home-body font-semibold'>
                        Server name
                    </label>
                    <input
                        id={inputId}
                        className='home-input mt-2'
                        value={name}
                        maxLength={NAME_MAX * 2}
                        placeholder={EXAMPLE_NAME}
                        autoComplete='off'
                        spellCheck={false}
                        onChange={(event) => setName(event.target.value)}
                    />
                    <p aria-live='polite' className='home-body mt-3'>
                        {address.ok ? (
                            <>
                                <span className='block'>
                                    Its page: <strong className='font-semibold break-all'>/s/{address.slug}</strong>
                                </span>
                                {game.match === 'minecraft' && (
                                    <span className='block'>
                                        Friends join at{' '}
                                        <strong className='font-semibold'>
                                            {address.host.split('.').map((part, index, parts) => (
                                                <Fragment key={parts.slice(0, index + 1).join('.')}>
                                                    {index > 0 && <wbr />}
                                                    <span className='whitespace-nowrap'>
                                                        {index > 0 && '.'}
                                                        {part}
                                                    </span>
                                                </Fragment>
                                            ))}
                                        </strong>
                                    </span>
                                )}
                            </>
                        ) : (
                            address.problem
                        )}
                    </p>
                    <p className='home-body home-muted mt-1'>
                        You keep the name if nobody has it yet, and can change both later.
                    </p>
                </div>
                <div className='home-rise grid grid-cols-2 gap-3 [&>a]:px-3' style={rise(4)}>
                    {primary}
                    {secondary}
                </div>
            </div>
        </section>
    );
}
