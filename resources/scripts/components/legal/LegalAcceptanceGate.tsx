import { ScrollText } from 'lucide-react';
import { useAcceptLegalDocuments, useLegalDocuments, usePendingLegalDocuments } from '@/api/legal';
import { useLogout } from '@/api/auth/queries';
import type { LegalDocumentId } from '@/api/settings/types';
import Button from '@/components/elements/Button';
import { Dialog } from '@/components/elements/dialog';
import Icon from '@/components/elements/Icon';
import { formatLegalDate } from '@/components/legal/formatLegalDate';

/** Pages the prompt stays away from: sign-in, the documents themselves and where admins edit them. */
export const isLegalGateExempt = (pathname: string): boolean =>
    pathname.startsWith('/auth') || pathname.startsWith('/legal/') || pathname.startsWith('/panel/settings/legal');

const NAMES = {
    terms: 'Terms of Service',
    privacy: 'Privacy Policy',
    refunds: 'Refund Policy',
} as const satisfies Record<LegalDocumentId, string>;

const headline = (pending: LegalDocumentId[]): string => {
    if (pending.length === 1 && pending[0] === 'privacy') {
        return 'We updated our Privacy Policy';
    }

    return pending.includes('privacy') ? 'We updated our Terms and Privacy Policy' : 'We updated our Terms';
};

/**
 * The prompt signed-in users see when the Terms of Service or Privacy Policy changed since they
 * accepted them. It blocks the panel until they accept (or sign out), but stays off the sign-in
 * pages, the documents and Admin > Settings > Legal, so an admin can always fix a mistake.
 * API keys are never blocked: this is only in the panel's pages.
 */
export default function LegalAcceptanceGate({ pathname }: { pathname: string }) {
    const pending = usePendingLegalDocuments();
    const documents = useLegalDocuments();
    const accept = useAcceptLegalDocuments();
    const logout = useLogout();

    if (pending.length === 0 || isLegalGateExempt(pathname)) {
        return null;
    }

    return (
        <Dialog
            open
            onClose={() => {}}
            hideCloseIcon
            preventExternalClose
            title={headline(pending)}
            description='Please take a moment to read what changed. You can keep using MonoForge once you accept.'
        >
            <ul className='grid gap-2'>
                {pending.map((id) => {
                    const updated = formatLegalDate(documents[id].updatedAt);

                    return (
                        <li
                            key={id}
                            className='flex items-center gap-3 rounded-lg border border-border bg-muted/40 p-3'
                        >
                            <Icon icon={ScrollText} className='size-4 shrink-0 text-muted-foreground' aria-hidden />
                            <div className='min-w-0 flex-1'>
                                <a
                                    href={documents[id].url}
                                    target='_blank'
                                    rel='noopener noreferrer'
                                    className='font-medium text-accent underline underline-offset-2 hover:text-foreground'
                                >
                                    {NAMES[id]}
                                </a>
                                {updated && <p className='text-xs text-muted-foreground'>Updated {updated}</p>}
                            </div>
                        </li>
                    );
                })}
            </ul>
            <p className='mt-4 text-xs text-muted-foreground'>
                By selecting Accept you agree to the current Terms of Service and Privacy Policy.
            </p>
            <Dialog.Footer>
                <Button.Text isSecondary onClick={() => logout.mutate({})} disabled={logout.isPending}>
                    Sign out
                </Button.Text>
                <Button
                    isLoading={accept.isPending}
                    disabled={accept.isPending}
                    onClick={() =>
                        accept.mutate({
                            body: {
                                terms_version: documents.terms.version,
                                privacy_version: documents.privacy.version,
                            },
                        })
                    }
                >
                    Accept
                </Button>
            </Dialog.Footer>
        </Dialog>
    );
}
