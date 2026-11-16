import { ChevronLeft, ChevronRight, Compass, Search, X } from 'lucide-react';
import { type ReactNode, useDeferredValue, useEffect, useId, useState } from 'react';
import {
    DISCOVERY_SEARCH_MAX,
    DISCOVERY_SORTS,
    useServerDiscovery,
    type DiscoverySort,
    type ServerDiscoveryList,
} from '@/api/public/serverDiscovery';
import Icon from '@/components/elements/Icon';
import Spinner from '@/components/elements/Spinner';
import HelpLayout, { HelpCard } from '@/components/help/HelpLayout';
import ServerCard from '@/components/serverPage/ServerCard';
import { setPageTitle } from '@/views/pageTitle';

const fieldClass =
    'h-11 w-full rounded-xl border border-border bg-card px-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-accent focus:outline-hidden';

interface FilterBarProps {
    search: string;
    game: string;
    sort: DiscoverySort;
    games: ServerDiscoveryList['games'];
    onSearch: (value: string) => void;
    onGame: (value: string) => void;
    onSort: (value: DiscoverySort) => void;
}

function FilterBar({ search, game, sort, games, onSearch, onGame, onSort }: FilterBarProps) {
    const ids = { search: useId(), game: useId(), sort: useId() };

    return (
        <div role='search' className='grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)]'>
            <div className='relative'>
                <label htmlFor={ids.search} className='sr-only'>
                    Search servers by name
                </label>
                <Icon
                    icon={Search}
                    aria-hidden
                    className='pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground'
                />
                <input
                    id={ids.search}
                    type='search'
                    value={search}
                    maxLength={DISCOVERY_SEARCH_MAX}
                    autoComplete='off'
                    placeholder='Search by name'
                    onChange={(event) => onSearch(event.target.value)}
                    className={`${fieldClass} pr-10 pl-9 [&::-webkit-search-cancel-button]:hidden`}
                />
                {search && (
                    <button
                        type='button'
                        aria-label='Clear the search'
                        onClick={() => onSearch('')}
                        className='absolute top-1/2 right-2 -translate-y-1/2 rounded-md p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground'
                    >
                        <Icon icon={X} className='size-4' aria-hidden />
                    </button>
                )}
            </div>
            <div>
                <label htmlFor={ids.game} className='sr-only'>
                    Game
                </label>
                <select
                    id={ids.game}
                    value={game}
                    onChange={(event) => onGame(event.target.value)}
                    className={fieldClass}
                >
                    <option value=''>All games</option>
                    {games.map((option) => (
                        <option key={option.slug} value={option.slug}>
                            {option.name}
                        </option>
                    ))}
                </select>
            </div>
            <div>
                <label htmlFor={ids.sort} className='sr-only'>
                    Sort by
                </label>
                <select
                    id={ids.sort}
                    value={sort}
                    onChange={(event) => onSort(event.target.value as DiscoverySort)}
                    className={fieldClass}
                >
                    {DISCOVERY_SORTS.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </select>
            </div>
        </div>
    );
}

function Pagination({ page, hasMore, onPage }: { page: number; hasMore: boolean; onPage: (page: number) => void }) {
    if (page === 1 && !hasMore) {
        return null;
    }

    const buttonClass =
        'inline-flex items-center gap-1.5 rounded-lg border border-border bg-card px-3 py-2 text-sm font-medium text-foreground transition-colors hover:bg-muted disabled:cursor-not-allowed disabled:opacity-50';

    return (
        <nav aria-label='Pages' className='flex items-center justify-center gap-3'>
            <button type='button' className={buttonClass} disabled={page <= 1} onClick={() => onPage(page - 1)}>
                <Icon icon={ChevronLeft} aria-hidden className='size-4' />
                Previous
            </button>
            <span className='text-sm text-muted-foreground tabular-nums'>Page {page}</span>
            <button type='button' className={buttonClass} disabled={!hasMore} onClick={() => onPage(page + 1)}>
                Next
                <Icon icon={ChevronRight} aria-hidden className='size-4' />
            </button>
        </nav>
    );
}

/**
 * The public server discovery page at /servers: every published server page whose owner lists
 * it, with a search by name, a game filter and a sort, 24 a page. The panel answers from one
 * cached query and never asks the game servers while someone browses.
 */
export default function ServerDiscoveryPage() {
    const [search, setSearch] = useState('');
    const [game, setGame] = useState('');
    const [sort, setSort] = useState<DiscoverySort>('players');
    const [page, setPage] = useState(1);
    const deferredSearch = useDeferredValue(search);
    const { data, error, isPending, isFetching, refetch } = useServerDiscovery({
        game: game || undefined,
        search: deferredSearch,
        sort,
        page,
    });

    useEffect(() => {
        setPageTitle('Discover game servers | MonoForge');
    }, []);

    // A change of filter starts again at the first page.
    const filter =
        <T,>(set: (value: T) => void) =>
        (value: T) => {
            set(value);
            setPage(1);
        };

    let body: ReactNode;

    if (isPending) {
        body = <Spinner size='large' centered />;
    } else if (error && !data) {
        body = (
            <HelpCard className='text-center text-sm text-muted-foreground'>
                <p>The server list could not be loaded.</p>
                <button
                    type='button'
                    onClick={() => refetch()}
                    className='mt-3 font-medium text-accent underline underline-offset-2'
                >
                    Try again
                </button>
            </HelpCard>
        );
    } else if (data.data.length === 0) {
        body = (
            <HelpCard className='text-center'>
                <Icon icon={Compass} aria-hidden className='mx-auto mb-3 size-8 text-muted-foreground' />
                <p className='font-semibold text-foreground'>
                    {search.trim() || game ? 'No servers match these filters.' : 'No servers are listed yet.'}
                </p>
                <p className='mt-1 text-sm text-muted-foreground'>
                    Server owners can list theirs by publishing a public page from the server&rsquo;s Public page tab.
                </p>
            </HelpCard>
        );
    } else {
        body = (
            <>
                <ul className='grid gap-4 sm:grid-cols-2 lg:grid-cols-3' aria-busy={isFetching}>
                    {data.data.map((server) => (
                        <li key={server.slug} className='min-w-0'>
                            <ServerCard server={server} />
                        </li>
                    ))}
                </ul>
                <Pagination page={data.meta.page} hasMore={data.meta.has_more} onPage={setPage} />
            </>
        );
    }

    return (
        <HelpLayout wide>
            <div className='flex flex-col gap-6'>
                <div>
                    <h1 className='text-2xl font-bold text-foreground sm:text-3xl'>Discover servers</h1>
                    <p className='mt-2 max-w-2xl text-sm text-muted-foreground sm:text-base'>
                        Game servers hosted on MonoForge. See who is online, copy the address and join.
                    </p>
                </div>
                <FilterBar
                    search={search}
                    game={game}
                    sort={sort}
                    games={data?.games ?? []}
                    onSearch={filter(setSearch)}
                    onGame={filter(setGame)}
                    onSort={filter(setSort)}
                />
                <div aria-live='polite' className='sr-only'>
                    {data ? `${data.data.length} servers shown` : ''}
                </div>
                {body}
            </div>
        </HelpLayout>
    );
}
