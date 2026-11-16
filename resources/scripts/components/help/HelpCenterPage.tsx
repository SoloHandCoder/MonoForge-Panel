import { Link } from '@tanstack/react-router';
import { BookOpen, LifeBuoy, Search, X } from 'lucide-react';
import { useDeferredValue, useEffect, useId, useMemo, useState } from 'react';
import { helpCategories, usePublicHelp, type HelpArticle } from '@/api/help';
import Icon from '@/components/elements/Icon';
import Spinner from '@/components/elements/Spinner';
import HelpLayout, { DISCORD_URL, HelpCard } from '@/components/help/HelpLayout';
import { MAX_QUERY, searchHelp, type HelpSearchResult } from '@/components/help/helpSearch';
import { setPageTitle } from '@/views/pageTitle';

const articleLinkClass =
    'group block rounded-xl px-3 py-2.5 no-underline transition-colors hover:bg-muted focus-visible:bg-muted focus-visible:outline-hidden';

function ArticleLink({ article, snippet }: { article: HelpArticle; snippet?: string }) {
    const text = snippet ?? article.summary;

    return (
        <Link to='/help/$slug' params={{ slug: article.slug }} className={articleLinkClass}>
            <span className='block font-medium text-foreground group-hover:text-accent'>{article.title}</span>
            {text && <span className='mt-0.5 block text-sm text-muted-foreground'>{text}</span>}
        </Link>
    );
}

function SearchBox({ query, onChange }: { query: string; onChange: (query: string) => void }) {
    const id = useId();

    return (
        <div className='relative'>
            <label htmlFor={id} className='sr-only'>
                Search the help center
            </label>
            <Icon
                icon={Search}
                aria-hidden
                className='pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-muted-foreground'
            />
            <input
                id={id}
                type='search'
                value={query}
                maxLength={MAX_QUERY}
                autoComplete='off'
                placeholder='Search the help center, such as "backup" or "address pending"'
                onChange={(event) => onChange(event.target.value)}
                className='h-14 w-full rounded-2xl border border-border bg-card pr-12 pl-12 text-base text-foreground shadow-lg placeholder:text-muted-foreground focus:border-accent focus:outline-hidden [&::-webkit-search-cancel-button]:hidden'
            />
            {query && (
                <button
                    type='button'
                    aria-label='Clear the search'
                    onClick={() => onChange('')}
                    className='absolute top-1/2 right-3 -translate-y-1/2 rounded-md p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground'
                >
                    <Icon icon={X} className='size-4' aria-hidden />
                </button>
            )}
        </div>
    );
}

function Results({ query, results }: { query: string; results: HelpSearchResult[] }) {
    return (
        <HelpCard>
            <h2 className='mb-3 text-sm font-medium text-muted-foreground' aria-live='polite'>
                {results.length === 0
                    ? `No articles match “${query.trim()}”.`
                    : `${results.length} ${results.length === 1 ? 'article' : 'articles'} for “${query.trim()}”`}
            </h2>
            {results.length > 0 ? (
                <ul className='-mx-3 grid gap-1'>
                    {results.map((result) => (
                        <li key={result.article.slug}>
                            <ArticleLink article={result.article} snippet={result.snippet} />
                        </li>
                    ))}
                </ul>
            ) : (
                <p className='text-sm text-muted-foreground'>
                    Try fewer or other words, or{' '}
                    <a href='/account/support' className='text-accent underline'>
                        open a support ticket
                    </a>
                    .
                </p>
            )}
        </HelpCard>
    );
}

function Categories({ articles }: { articles: HelpArticle[] }) {
    const categories = useMemo(() => helpCategories(articles), [articles]);

    return (
        <div className='grid gap-4 md:grid-cols-2'>
            {categories.map((category) => (
                <section key={category.name} aria-label={category.name}>
                    <HelpCard className='h-full p-5 sm:p-6'>
                        <h2 className='mb-2 flex items-center gap-2 font-header text-lg font-semibold text-foreground'>
                            <Icon icon={BookOpen} className='size-4 text-accent' aria-hidden />
                            {category.name}
                        </h2>
                        <ul className='-mx-3 grid gap-0.5'>
                            {category.articles.map((article) => (
                                <li key={article.slug}>
                                    <ArticleLink article={article} />
                                </li>
                            ))}
                        </ul>
                    </HelpCard>
                </section>
            ))}
        </div>
    );
}

/** The articles and the search, once they are loaded. Exported for tests. */
export function HelpCenterView({ articles }: { articles: HelpArticle[] }) {
    const [query, setQuery] = useState('');
    const deferred = useDeferredValue(query);
    const results = useMemo(() => searchHelp(articles, deferred), [articles, deferred]);
    const searching = deferred.trim() !== '';

    return (
        <div className='flex flex-col gap-6'>
            <SearchBox query={query} onChange={setQuery} />
            {searching ? <Results query={deferred} results={results} /> : <Categories articles={articles} />}
        </div>
    );
}

function HelpBody() {
    const { data, error, isPending, refetch } = usePublicHelp();

    if (isPending) {
        return <Spinner size='large' centered />;
    }

    if (error || !data) {
        return (
            <HelpCard>
                <p className='text-sm text-muted-foreground'>The help center could not be loaded.</p>
                <button type='button' onClick={() => void refetch()} className='mt-3 text-sm text-accent underline'>
                    Try again
                </button>
            </HelpCard>
        );
    }

    return <HelpCenterView articles={data} />;
}

/** The public help center at /help: a search over every article, and the articles by category. */
export default function HelpCenterPage() {
    useEffect(() => {
        setPageTitle('Help center | MonoForge');
    }, []);

    return (
        <HelpLayout wide>
            <div className='flex flex-col gap-6'>
                <div className='pt-2 text-center sm:pt-6'>
                    <h1 className='font-header text-3xl font-bold text-foreground sm:text-4xl'>How can we help?</h1>
                    <p className='mt-2 text-muted-foreground'>
                        Guides for your MonoForge account, servers, tokens and more.
                    </p>
                </div>
                <HelpBody />
                <HelpCard className='flex flex-col items-start gap-3 p-5 sm:flex-row sm:items-center sm:p-6'>
                    <Icon icon={LifeBuoy} className='size-6 shrink-0 text-accent' aria-hidden />
                    <p className='flex-1 text-sm text-muted-foreground'>
                        Can&apos;t find what you need? Open a support ticket from your account, or ask on our Discord.
                    </p>
                    <a
                        href={DISCORD_URL}
                        target='_blank'
                        rel='noopener noreferrer'
                        className='rounded-lg bg-primary px-3.5 py-2 text-sm font-semibold text-primary-foreground no-underline hover:bg-primary/90'
                    >
                        Join our Discord
                    </a>
                </HelpCard>
            </div>
        </HelpLayout>
    );
}
