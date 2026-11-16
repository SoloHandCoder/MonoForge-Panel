import { Link, useParams } from '@tanstack/react-router';
import { ArrowLeft, ArrowRight } from 'lucide-react';
import { useEffect, useMemo } from 'react';
import { helpCategories, usePublicHelp, type HelpArticle } from '@/api/help';
import Icon from '@/components/elements/Icon';
import Spinner from '@/components/elements/Spinner';
import HelpLayout, { HelpCard } from '@/components/help/HelpLayout';
import { cn } from '@/lib/cn';
import Markdown from '@/lib/markdown';
import { setPageTitle } from '@/views/pageTitle';

/** The other articles, by category, beside the one being read. */
function ArticleNav({ articles, current }: { articles: HelpArticle[]; current: string }) {
    const categories = useMemo(() => helpCategories(articles), [articles]);

    return (
        <nav aria-label='Help articles' className='flex flex-col gap-4 text-sm'>
            {categories.map((category) => (
                <div key={category.name}>
                    <p className='mb-1 px-3 text-xs font-medium tracking-wide text-muted-foreground uppercase'>
                        {category.name}
                    </p>
                    <ul className='grid gap-0.5'>
                        {category.articles.map((article) => (
                            <li key={article.slug}>
                                <Link
                                    to='/help/$slug'
                                    params={{ slug: article.slug }}
                                    aria-current={article.slug === current ? 'page' : undefined}
                                    className={cn(
                                        'block rounded-lg px-3 py-1.5 no-underline transition-colors',
                                        article.slug === current
                                            ? 'bg-accent text-accent-foreground'
                                            : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                                    )}
                                >
                                    {article.title}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </div>
            ))}
        </nav>
    );
}

function Neighbour({ article, direction }: { article: HelpArticle | undefined; direction: 'previous' | 'next' }) {
    if (!article) {
        return <span />;
    }

    const next = direction === 'next';

    return (
        <Link
            to='/help/$slug'
            params={{ slug: article.slug }}
            className={cn(
                'flex min-w-0 flex-col rounded-xl border border-border px-4 py-3 no-underline transition-colors hover:border-accent',
                next && 'items-end text-right'
            )}
        >
            <span className='flex items-center gap-1 text-xs text-muted-foreground'>
                {!next && <Icon icon={ArrowLeft} className='size-3' aria-hidden />}
                {next ? 'Next' : 'Previous'}
                {next && <Icon icon={ArrowRight} className='size-3' aria-hidden />}
            </span>
            <span className='truncate font-medium text-foreground'>{article.title}</span>
        </Link>
    );
}

/** One article with the list of the others. Exported for tests. */
export function HelpArticleView({ articles, slug }: { articles: HelpArticle[]; slug: string }) {
    const index = articles.findIndex((article) => article.slug === slug);
    const article = index === -1 ? undefined : articles[index];

    useEffect(() => {
        if (article) {
            setPageTitle(`${article.title} | MonoForge Help`);
        }
    }, [article]);

    if (!article) {
        return (
            <HelpCard>
                <h1 className='font-header text-2xl font-semibold text-foreground'>Article not found</h1>
                <p className='mt-2 text-sm text-muted-foreground'>
                    This help article does not exist or was taken down.{' '}
                    <Link to='/help' className='text-accent underline'>
                        Browse the help center
                    </Link>
                    .
                </p>
            </HelpCard>
        );
    }

    return (
        <div className='grid gap-6 lg:grid-cols-[15rem_minmax(0,1fr)]'>
            <aside className='order-2 lg:order-1'>
                <HelpCard className='p-3 sm:p-3 lg:sticky lg:top-6'>
                    <ArticleNav articles={articles} current={article.slug} />
                </HelpCard>
            </aside>
            <div className='order-1 flex min-w-0 flex-col gap-4 lg:order-2'>
                <Link
                    to='/help'
                    className='inline-flex items-center gap-1.5 self-start text-sm text-muted-foreground no-underline hover:text-foreground'
                >
                    <Icon icon={ArrowLeft} className='size-4' aria-hidden />
                    All articles
                </Link>
                <HelpCard>
                    <article>
                        <p className='mb-4 text-xs font-medium tracking-wide text-muted-foreground uppercase'>
                            {article.category}
                        </p>
                        <Markdown source={article.content} />
                    </article>
                </HelpCard>
                <div className='grid grid-cols-2 gap-3'>
                    <Neighbour article={articles[index - 1]} direction='previous' />
                    <Neighbour article={articles[index + 1]} direction='next' />
                </div>
            </div>
        </div>
    );
}

/** A help article at /help/{slug}; the server adds its title and description for search engines. */
export default function HelpArticlePage() {
    const params: { slug?: string } = useParams({ strict: false });
    const { data, error, isPending, refetch } = usePublicHelp();

    let body;

    if (isPending) {
        body = <Spinner size='large' centered />;
    } else if (error || !data) {
        body = (
            <HelpCard>
                <p className='text-sm text-muted-foreground'>The article could not be loaded.</p>
                <button type='button' onClick={() => void refetch()} className='mt-3 text-sm text-accent underline'>
                    Try again
                </button>
            </HelpCard>
        );
    } else {
        body = <HelpArticleView articles={data} slug={params.slug ?? ''} />;
    }

    return <HelpLayout wide>{body}</HelpLayout>;
}
