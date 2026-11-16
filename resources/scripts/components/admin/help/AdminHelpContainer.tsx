import { Link } from '@tanstack/react-router';
import { useState } from 'react';
import { useAdminGuides } from '@/api/admin/settings/help';
import type { HelpArticle } from '@/api/help';
import { httpErrorToHuman } from '@/api/http';
import AdminContentBlock from '@/components/admin/AdminContentBlock';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import TitledGreyBox from '@/components/elements/TitledGreyBox';
import { cn } from '@/lib/cn';
import Markdown from '@/lib/markdown';

/** The guides with a switcher between them. Exported for tests. */
export function AdminGuides({ guides }: { guides: HelpArticle[] }) {
    const [selected, setSelected] = useState(guides[0]?.slug ?? '');
    const guide = guides.find((entry) => entry.slug === selected) ?? guides[0];

    if (!guide) {
        return (
            <p className='text-sm text-muted-foreground'>
                There are no guides for administrators. They can be turned back on in{' '}
                <Link to='/panel/settings/help' className='text-accent underline'>
                    Settings → Help
                </Link>
                .
            </p>
        );
    }

    return (
        <div>
            <div className='mb-6 flex flex-wrap gap-2' role='tablist' aria-label='Guides'>
                {guides.map((entry) => (
                    <button
                        key={entry.slug}
                        type='button'
                        role='tab'
                        aria-selected={entry.slug === guide.slug}
                        onClick={() => setSelected(entry.slug)}
                        className={cn(
                            'rounded-full border px-4 py-1.5 text-sm transition-colors',
                            entry.slug === guide.slug
                                ? 'border-accent bg-accent text-accent-foreground'
                                : 'border-border text-muted-foreground hover:text-foreground'
                        )}
                    >
                        {entry.title}
                    </button>
                ))}
            </div>
            <TitledGreyBox title={guide.category}>
                <div role='tabpanel' className='max-w-3xl'>
                    <Markdown source={guide.content} />
                </div>
            </TitledGreyBox>
        </div>
    );
}

/** Admin > Help: the guides for running the panel, for root administrators. */
export default function AdminHelpContainer() {
    const { data, error, refetch } = useAdminGuides();

    let body = <Spinner size='large' centered />;

    if (data) {
        body = <AdminGuides guides={data} />;
    } else if (error) {
        body = <ServerError message={httpErrorToHuman(error)} onRetry={() => refetch()} />;
    }

    return (
        <AdminContentBlock
            title='Admin · Help'
            heading='Help'
            description='Guides for running MonoForge. The player help center is at /help; edit both in Settings → Help.'
        >
            {body}
        </AdminContentBlock>
    );
}
