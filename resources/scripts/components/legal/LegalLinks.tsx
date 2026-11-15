import { Fragment } from 'react';
import { LEGAL_DOCUMENT_IDS, useLegalDocuments } from '@/api/legal';
import type { LegalDocumentId } from '@/api/settings/types';
import { cn } from '@/lib/cn';

const LABELS = { terms: 'Terms', privacy: 'Privacy', refunds: 'Refunds' } as const satisfies Record<
    LegalDocumentId,
    string
>;

/** One legal document as a link that opens in a new tab. */
export function LegalLink({ document, children }: { document: LegalDocumentId; children?: string }) {
    const documents = useLegalDocuments();

    return (
        <a
            href={documents[document].url}
            target='_blank'
            rel='noopener noreferrer'
            className='font-medium text-accent underline underline-offset-2 hover:text-foreground'
        >
            {children ?? documents[document].title}
        </a>
    );
}

/** "Terms · Privacy · Refunds", each opening in a new tab. */
export default function LegalLinks({ className }: { className?: string }) {
    const documents = useLegalDocuments();

    return (
        <nav
            aria-label='Legal'
            className={cn('flex flex-wrap items-center gap-x-1.5 text-xs text-muted-foreground', className)}
        >
            {LEGAL_DOCUMENT_IDS.map((id, index) => (
                <Fragment key={id}>
                    {index > 0 && <span aria-hidden>·</span>}
                    <a
                        href={documents[id].url}
                        target='_blank'
                        rel='noopener noreferrer'
                        className='no-underline hover:text-foreground'
                    >
                        {LABELS[id]}
                    </a>
                </Fragment>
            ))}
        </nav>
    );
}
