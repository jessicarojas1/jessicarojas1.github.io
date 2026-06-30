import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

export interface DocumentAcknowledgement {
  id: number;
  document_id: number;
  revision: string;
  user_id: number;
  user_name: string;
  note: string | null;
  acknowledged_at: string;
}

export interface PendingAcknowledgement {
  document_id: number;
  document_number: string;
  title: string;
  current_revision: string | null;
}

const ackKey = (docId: number | string) => ['documents', String(docId), 'acknowledgements'];

/** Who has acknowledged a controlled document. */
export function useDocumentAcknowledgements(docId?: number | string) {
  return useQuery<DocumentAcknowledgement[]>({
    queryKey: ackKey(docId ?? ''),
    queryFn: async () =>
      (await api.get<DocumentAcknowledgement[]>(`/documents/${docId}/acknowledgements`)).data,
    enabled: docId != null && docId !== '',
  });
}

/** Record the current user's read-and-acknowledge attestation for a document. */
export function useAcknowledgeDocument(docId: number | string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (note?: string | null) =>
      (await api.post<DocumentAcknowledgement>(`/documents/${docId}/acknowledge`, { note: note ?? null }))
        .data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ackKey(docId) });
      qc.invalidateQueries({ queryKey: ['documents', 'acknowledgements', 'pending'] });
    },
  });
}

/** Controlled documents the current user still needs to acknowledge. */
export function usePendingAcknowledgements() {
  return useQuery<PendingAcknowledgement[]>({
    queryKey: ['documents', 'acknowledgements', 'pending'],
    queryFn: async () =>
      (await api.get<PendingAcknowledgement[]>('/documents/acknowledgements/pending')).data,
    staleTime: 60_000,
  });
}
