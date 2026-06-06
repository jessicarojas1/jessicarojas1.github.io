import { useCallback } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { AttachmentRecord } from '@/types';

function attachmentsKey(entityType: string, entityId: string) {
  return ['attachments', entityType, entityId] as const;
}

/** List attachments linked to a single entity. */
export function useAttachments(entityType: string, entityId?: string | number | null) {
  const id = entityId == null ? '' : String(entityId);
  return useQuery<AttachmentRecord[]>({
    queryKey: attachmentsKey(entityType, id),
    enabled: Boolean(entityType) && id !== '',
    queryFn: async () => {
      const { data } = await api.get<AttachmentRecord[]>('/attachments', {
        params: { entity_type: entityType, entity_id: id },
      });
      return data ?? [];
    },
  });
}

export interface UploadAttachmentVars {
  entityType: string;
  entityId: string | number;
  file: File;
  onProgress?: (percent: number) => void;
}

/** Upload a file as multipart/form-data, then invalidate the attachments list. */
export function useUploadAttachment() {
  const qc = useQueryClient();
  return useMutation<AttachmentRecord, unknown, UploadAttachmentVars>({
    mutationFn: async ({ entityType, entityId, file, onProgress }) => {
      const form = new FormData();
      form.append('entity_type', entityType);
      form.append('entity_id', String(entityId));
      form.append('file', file);
      // Let axios/browser set the multipart boundary by sending undefined.
      const { data } = await api.post<{ attachment: AttachmentRecord }>(
        '/attachments',
        form,
        {
          headers: { 'Content-Type': undefined },
          onUploadProgress: (e) => {
            if (onProgress && e.total) {
              onProgress(Math.round((e.loaded / e.total) * 100));
            }
          },
        },
      );
      return data.attachment;
    },
    onSuccess: (_data, vars) => {
      qc.invalidateQueries({
        queryKey: attachmentsKey(vars.entityType, String(vars.entityId)),
      });
    },
  });
}

/**
 * Download an attachment through the authenticated `api` instance (the endpoint
 * requires a bearer token, so a plain anchor href will not work) and trigger a
 * browser save via an object URL.
 */
export function useDownloadAttachment() {
  return useCallback(async (attachment: AttachmentRecord) => {
    const { data } = await api.get<Blob>(`/attachments/${attachment.id}/download`, {
      responseType: 'blob',
    });
    const url = URL.createObjectURL(data);
    const link = document.createElement('a');
    link.href = url;
    link.download = attachment.original_filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  }, []);
}
