/* oxlint-disable rules/design-tokens -- the page backdrop and card radius are the brand's fixed sign-in look */
import { useParams } from '@tanstack/react-router';
import { useEffect } from 'react';
import { isLegalDocumentId, LEGAL_DOCUMENT_IDS, useLegalDocuments } from '@/api/legal';
import type { LegalDocumentId } from '@/api/settings/types';
import { getBootstrapLegalPage } from '@/bootstrap';
import { NotFound } from '@/components/elements/ScreenBlock';
import { formatLegalDate } from '@/components/legal/formatLegalDate';
import { cn } from '@/lib/cn';
import Markdown from '@/lib/markdown';
import { Main } from '@/views/a11y';
import { setPageTitle } from '@/views/pageTitle';

const SHORT_TITLES = {
    terms: 'Terms',
    privacy: 'Privacy',
    refunds: 'Refunds',
} as const satisfies Record<LegalDocumentId, string>;

function DocumentTabs({ current }: { current: LegalDocumentId }) {
    const documents = useLegalDocuments();

    return (
        <nav
            aria-label='Legal documents'
            className='flex flex-wrap gap-1 rounded-full border border-border bg-card/70 p-1'
        >
            {LEGAL_DOCUMENT_IDS.map((id) => (
                // Full page loads: every document is rendered into its own page by the server.
                <a
                    key={id}
                    href={documents[id].url}
                    aria-current={id === current ? 'page' : undefined}
                    className={cn(
                        'rounded-full px-3 py-1.5 text-sm no-underline transition-colors',
                        id === current
                            ? 'bg-accent text-accent-foreground'
                            : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                    )}
                >
                    {SHORT_TITLES[id]}
                </a>
            ))}
        </nav>
    );
}

/**
 * The public /legal/{terms,privacy,refunds} pages, in the violet sign-in look. The server renders
 * the document into the page bootstrap (or redirects to its external page), so they work for
 * visitors who are not signed in.
 */
export default function LegalPage() {
    const params: { document?: string } = useParams({ strict: false });
    const id = params.document ?? '';
    const page = getBootstrapLegalPage();
    const matches = isLegalDocumentId(id) && page?.document === id;

    useEffect(() => {
        if (matches && page) {
            setPageTitle(`${page.title} | MonoForge`);
        }
    }, [matches, page]);

    if (!isLegalDocumentId(id)) {
        return <NotFound />;
    }

    const updated = page ? formatLegalDate(page.updatedAt) : '';

    return (
        <div className='relative isolate min-h-screen bg-[radial-gradient(120%_120%_at_80%_0%,#241a44,#0e0d15_60%)] px-4 py-8 sm:py-12'>
            <div className='mx-auto flex w-full max-w-3xl flex-col gap-6'>
                <header className='flex flex-wrap items-center justify-between gap-4'>
                    <a
                        href='/'
                        className='flex items-center gap-2.5 text-lg font-semibold tracking-wide text-foreground no-underline'
                    >
                        <img src='/favicons/favicon.svg' alt='' className='size-8' />
                        MonoForge
                    </a>
                    <DocumentTabs current={id} />
                </header>
                <Main className='rounded-[18px] border border-border bg-card p-6 text-card-foreground shadow-lg sm:p-10'>
                    {matches ? (
                        <>
                            <p className='mb-6 text-xs font-medium tracking-wide text-muted-foreground uppercase'>
                                {updated ? `Last updated ${updated}` : page.title}
                                <span aria-hidden> · </span>Version {page.version}
                            </p>
                            <Markdown source={page.content} />
                        </>
                    ) : (
                        <p className='text-sm text-muted-foreground'>
                            <a href={`/legal/${id}`} className='font-medium text-accent underline underline-offset-2'>
                                Open the document
                            </a>
                        </p>
                    )}
                </Main>
                <p className='text-center text-xs text-muted-foreground'>
                    &copy; <span suppressHydrationWarning>{new Date().getFullYear()}</span> MonoForge
                    <span aria-hidden> · </span>
                    <a href='/' className='no-underline hover:text-foreground'>
                        Back to the panel
                    </a>
                </p>
            </div>
        </div>
    );
}
