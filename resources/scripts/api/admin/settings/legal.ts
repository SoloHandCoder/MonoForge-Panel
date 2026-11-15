import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import {
    adminGetLegalDocumentsOptions,
    adminGetLegalDocumentsQueryKey,
    adminUpdateLegalDocumentMutation,
} from '@/api/generated/@tanstack/react-query.gen';
import type { AdminGetLegalDocumentsResponse, AdminUpdateLegalDocumentResponse } from '@/api/generated';
import { isLegalDocumentId, legalDocuments } from '@/api/legal';
import { setSiteSettingsQueryData } from '@/api/settings/queries';
import type { LegalDocumentId } from '@/api/settings/types';
import { notifyHttpError } from '@/plugins/notifications';

/** A legal document as the admin editor sees it. */
export interface AdminLegalDocument {
    document: LegalDocumentId;
    title: string;
    content: string;
    /** Still the shipped starter text. */
    isDefault: boolean;
    /** An https page holding the document instead, or empty when the panel hosts it. */
    externalUrl: string;
    publicUrl: string;
    version: number;
    updatedAt: string;
}

type DocumentResponse = AdminUpdateLegalDocumentResponse;

const toDocument = (row: DocumentResponse): AdminLegalDocument | null => {
    const document = row.document ?? '';

    return isLegalDocumentId(document)
        ? {
              document,
              title: row.title ?? '',
              content: row.content ?? '',
              isDefault: row.is_default ?? false,
              externalUrl: row.external_url ?? '',
              publicUrl: row.public_url ?? `/legal/${document}`,
              version: row.version ?? 1,
              updatedAt: row.updated_at ?? '',
          }
        : null;
};

export const toAdminLegalDocuments = (response: AdminGetLegalDocumentsResponse): AdminLegalDocument[] =>
    (response.data ?? []).map(toDocument).filter((document): document is AdminLegalDocument => document !== null);

export const adminLegalDocumentsQueryOptions = () => adminGetLegalDocumentsOptions();

export const useAdminLegalDocuments = () =>
    useQuery({ ...adminLegalDocumentsQueryOptions(), select: toAdminLegalDocuments });

export interface LegalDocumentValues {
    document: LegalDocumentId;
    content: string;
    externalUrl: string;
    /** Raise the version so users accept the document again. */
    reaccept: boolean;
}

export const updateAdminLegalDocumentInput = (values: LegalDocumentValues) => ({
    path: { document: values.document },
    body: {
        content: values.content.trim() === '' ? null : values.content,
        external_url: values.externalUrl.trim() === '' ? null : values.externalUrl.trim(),
        reaccept: values.reaccept,
    },
});

export const useUpdateAdminLegalDocument = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...adminUpdateLegalDocumentMutation(),
        onSuccess: async (response, { body }) => {
            const saved = toDocument(response);

            if (saved) {
                // Links across the panel follow the new URL and version straight away.
                setSiteSettingsQueryData(queryClient, (settings) => ({
                    ...settings,
                    legal: {
                        documents: {
                            ...legalDocuments(settings),
                            [saved.document]: {
                                title: saved.title,
                                url: saved.externalUrl || saved.publicUrl,
                                external: saved.externalUrl !== '',
                                version: saved.version,
                                updatedAt: saved.updatedAt,
                            },
                        },
                        pending: settings.legal?.pending ?? [],
                    },
                }));
            }

            await queryClient.invalidateQueries({ queryKey: adminGetLegalDocumentsQueryKey() });
            toast.success(`${saved?.title ?? 'Document'} saved`, {
                description: body.reaccept
                    ? `Now version ${saved?.version ?? ''}. Signed-in players are asked to accept it again.`
                    : 'Players are not asked to accept it again.',
            });
        },
        onError: (error) => notifyHttpError(error, 'Unable to save the document'),
    });
};
