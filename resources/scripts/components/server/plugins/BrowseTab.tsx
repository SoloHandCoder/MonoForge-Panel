import { useId, useState } from 'react';
import { Download, ExternalLink, Package, Search } from 'lucide-react';

import {
    PLUGIN_SOURCE_LABELS,
    usePluginSearch,
    type PlatformOverrides,
    type PluginEnvironment,
    type PluginProject,
    type PluginProjectType,
    type PluginSearchResults,
    type PluginSort,
    type PluginSource,
} from '@/api/server/plugins/queries';
import { Alert } from '@/components/elements/alert';
import Badge from '@/components/elements/Badge';
import Button from '@/components/elements/Button';
import Pagination from '@/components/elements/Pagination';
import Spinner from '@/components/elements/Spinner';
import { TextInput } from '@/components/form/controls';
import Select from '@/components/ui/Select';
import { cn } from '@/lib/cn';
import { useDebouncedValue } from '@/plugins/useDebouncedValue';
import VersionsDialog from './VersionsDialog';

const SORTS: { value: PluginSort; label: string }[] = [
    { value: 'relevance', label: 'Best match' },
    { value: 'downloads', label: 'Most downloads' },
    { value: 'updated', label: 'Recently updated' },
    { value: 'newest', label: 'Newest' },
];

const isSource = (value: string): value is PluginSource => value in PLUGIN_SOURCE_LABELS;

const downloads = (count: number): string =>
    new Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 }).format(count);

const NOUNS = { plugin: 'plugins', mod: 'mods', modpack: 'modpacks' } as const satisfies Record<
    PluginProjectType,
    string
>;

interface Props {
    uuid: string;
    type: PluginProjectType;
    environment: PluginEnvironment;
    overrides: PlatformOverrides;
    canManage: boolean;
}

function ProjectRow({
    project,
    actionLabel,
    onPick,
}: {
    project: PluginProject;
    actionLabel: string;
    onPick: () => void;
}) {
    return (
        <li className='flex flex-col gap-3 rounded-lg bg-card p-4 shadow-sm sm:flex-row sm:items-center'>
            <div className='flex min-w-0 flex-1 items-start gap-3'>
                {project.icon_url ? (
                    <img
                        src={project.icon_url}
                        alt=''
                        loading='lazy'
                        referrerPolicy='no-referrer'
                        className='h-12 w-12 shrink-0 rounded-md bg-muted object-cover'
                    />
                ) : (
                    <span className='flex h-12 w-12 shrink-0 items-center justify-center rounded-md bg-muted'>
                        <Package aria-hidden className='h-6 w-6 text-muted-foreground' />
                    </span>
                )}
                <div className='min-w-0'>
                    <p className='flex flex-wrap items-center gap-2 font-medium text-foreground'>
                        <span className='truncate'>{project.title}</span>
                        {project.client_only && (
                            <Badge tone='warning' size='sm' variant='soft'>
                                Client-side only
                            </Badge>
                        )}
                    </p>
                    <p className='text-xs text-muted-foreground'>
                        {project.author ? `by ${project.author} · ` : ''}
                        {downloads(project.downloads)} downloads
                    </p>
                    <p className='mt-1 line-clamp-2 text-sm text-muted-foreground'>{project.description}</p>
                </div>
            </div>
            <div className='flex shrink-0 items-center gap-2'>
                <a
                    href={project.url}
                    target='_blank'
                    rel='noopener noreferrer'
                    className='inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground'
                >
                    <ExternalLink aria-hidden className='h-4 w-4' />
                    <span>
                        Details<span className='sr-only'> about {project.title} (opens a new tab)</span>
                    </span>
                </a>
                <Button size='small' className='inline-flex items-center gap-2' onClick={onPick}>
                    <Download aria-hidden className='h-4 w-4' />
                    {actionLabel}
                    <span className='sr-only'> {project.title}</span>
                </Button>
            </div>
        </li>
    );
}

function SearchResults({
    results,
    query,
    loading,
    stale,
    actionLabel,
    onPage,
    onPick,
}: {
    results: PluginSearchResults;
    query: string;
    loading: boolean;
    stale: boolean;
    actionLabel: string;
    onPage: (page: number) => void;
    onPick: (project: PluginProject) => void;
}) {
    if (results.data.length === 0) {
        return (
            <p className='rounded-lg bg-card p-6 text-center text-sm text-muted-foreground'>
                Nothing found{query ? ` for “${query}”` : ''}. Try another search or catalog.
            </p>
        );
    }

    return (
        <div aria-busy={loading} className={cn(stale && 'opacity-60')}>
            <Pagination data={results} onPageSelect={onPage}>
                {({ items }) => (
                    <ul className='space-y-2'>
                        {items.map((project) => (
                            <ProjectRow
                                key={`${project.source}:${project.id}`}
                                project={project}
                                actionLabel={actionLabel}
                                onPick={() => onPick(project)}
                            />
                        ))}
                    </ul>
                )}
            </Pagination>
        </div>
    );
}

/** Search a catalog and install a version, for plugins, mods or modpacks. */
export default function BrowseTab({ uuid, type, environment, overrides, canManage }: Props) {
    const id = useId();
    const sources = (environment.sources[type] ?? []).filter(isSource);
    const [source, setSource] = useState<PluginSource>(sources[0] ?? 'modrinth');
    const [query, setQuery] = useState('');
    const [sort, setSort] = useState<PluginSort>('relevance');
    const [page, setPage] = useState(1);
    const [picked, setPicked] = useState<PluginProject | null>(null);
    const debouncedQuery = useDebouncedValue(query, 400);
    const search = usePluginSearch(
        uuid,
        { source, type, query: debouncedQuery, page, sort },
        overrides,
        sources.length > 0
    );
    const noun = NOUNS[type];

    if (sources.length === 0) {
        return (
            <Alert type='info' title={`No catalog has ${noun} for this server`}>
                Choose another loader above if the detected one is wrong.
            </Alert>
        );
    }

    return (
        <div className='mt-4 space-y-4'>
            {type === 'modpack' && (
                <p className='text-sm text-muted-foreground'>
                    Modrinth modpacks install their mods and config files over the server&apos;s. The server is backed
                    up first when it has a free backup slot.
                </p>
            )}
            <div className='flex flex-col gap-2 lg:flex-row lg:items-center'>
                {sources.length > 1 && (
                    <div role='radiogroup' aria-label='Catalog' className='flex shrink-0 gap-1 rounded-md bg-card p-1'>
                        {sources.map((item) => (
                            <button
                                key={item}
                                type='button'
                                role='radio'
                                aria-checked={source === item}
                                className={cn(
                                    'rounded px-3 py-1.5 text-sm motion-safe:transition-colors',
                                    source === item
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground hover:text-foreground'
                                )}
                                onClick={() => {
                                    setSource(item);
                                    setPage(1);
                                }}
                            >
                                {PLUGIN_SOURCE_LABELS[item]}
                            </button>
                        ))}
                    </div>
                )}
                <div className='relative flex-1'>
                    <label htmlFor={`${id}-search`} className='sr-only'>
                        Search {noun}
                    </label>
                    <Search
                        aria-hidden
                        className='pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground'
                    />
                    <TextInput
                        id={`${id}-search`}
                        type='search'
                        className='pl-9'
                        placeholder={`Search ${PLUGIN_SOURCE_LABELS[source]} ${noun}…`}
                        value={query}
                        onChange={(event) => {
                            setQuery(event.currentTarget.value);
                            setPage(1);
                        }}
                    />
                </div>
                <div className='w-full lg:w-48'>
                    <label htmlFor={`${id}-sort`} className='sr-only'>
                        Sort by
                    </label>
                    <Select
                        id={`${id}-sort`}
                        options={SORTS}
                        value={sort}
                        placeholder='Sort'
                        onChange={(value) => {
                            setSort(SORTS.find((item) => item.value === value)?.value ?? 'relevance');
                            setPage(1);
                        }}
                    />
                </div>
            </div>

            {search.isError && (
                <Alert type='danger' title='Search failed'>
                    {PLUGIN_SOURCE_LABELS[source]} did not answer. Try again in a minute.
                </Alert>
            )}
            {search.isPending && <Spinner size='large' centered />}
            {search.data && (
                <SearchResults
                    results={search.data}
                    query={debouncedQuery}
                    loading={search.isFetching}
                    stale={search.isPlaceholderData}
                    actionLabel={canManage ? 'Install' : 'Versions'}
                    onPage={setPage}
                    onPick={setPicked}
                />
            )}

            <VersionsDialog
                uuid={uuid}
                type={type}
                project={picked}
                platform={environment.platform}
                overrides={overrides}
                canManage={canManage}
                onClose={() => setPicked(null)}
            />
        </div>
    );
}
