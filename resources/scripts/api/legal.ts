import { useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { clientAcceptLegalDocumentsMutation } from '@/api/generated/@tanstack/react-query.gen';
import { setSiteSettingsQueryData, useSiteSettings } from '@/api/settings/queries';
import type { LegalDocumentId, LegalDocumentSummary, SiteSettings } from '@/api/settings/types';
import { notifyHttpError } from '@/plugins/notifications';

export const LEGAL_DOCUMENT_IDS = ['terms', 'privacy', 'refunds'] as const satisfies readonly LegalDocumentId[];

const TITLES = {
    terms: 'Terms of Service',
    privacy: 'Privacy Policy',
    refunds: 'Refund Policy',
} as const satisfies Record<LegalDocumentId, string>;

export const isLegalDocumentId = (value: string): value is LegalDocumentId =>
    value === 'terms' || value === 'privacy' || value === 'refunds';

/** A document as an older page bootstrap without `legal` describes it: the panel's own page at version 1. */
const fallback = (id: LegalDocumentId): LegalDocumentSummary => ({
    title: TITLES[id],
    url: `/legal/${id}`,
    external: false,
    version: 1,
    updatedAt: '',
});

export const legalDocuments = (settings: SiteSettings | undefined) =>
    ({
        terms: settings?.legal?.documents.terms ?? fallback('terms'),
        privacy: settings?.legal?.documents.privacy ?? fallback('privacy'),
        refunds: settings?.legal?.documents.refunds ?? fallback('refunds'),
    }) satisfies Record<LegalDocumentId, LegalDocumentSummary>;

/** The Terms of Service, Privacy Policy and Refund Policy with their links and versions. */
export const useLegalDocuments = () => legalDocuments(useSiteSettings());

/** The documents the signed-in user has to accept again before using the panel. */
export const usePendingLegalDocuments = (): LegalDocumentId[] => useSiteSettings()?.legal?.pending ?? [];

/** Accepts the current Terms of Service and Privacy Policy, and lifts the prompt. */
export const useAcceptLegalDocuments = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...clientAcceptLegalDocumentsMutation(),
        onSuccess: () => {
            setSiteSettingsQueryData(queryClient, (settings) => ({
                ...settings,
                legal: { documents: legalDocuments(settings), pending: [] },
            }));
            toast.success('Thanks! You can carry on.');
        },
        onError: (error) => notifyHttpError(error, 'Unable to accept the updated documents'),
    });
};
