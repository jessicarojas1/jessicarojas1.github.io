import { useCallback } from 'react';
import { useMutation, useQueryClient, type QueryKey } from '@tanstack/react-query';
import { api } from '@/lib/api';

/** A single row failure surfaced by a bulk CSV import. */
export interface ImportRowError {
  row: number;
  message: string;
}

/** Outcome of a bulk CSV import (mirrors the backend ImportResult schema). */
export interface ImportResult {
  created: number;
  failed: number;
  errors: ImportRowError[];
}

/**
 * Returns a callback that downloads the CSV import template for `resource`
 * through the authenticated `api` instance (the endpoint requires a bearer
 * token, so a plain anchor href will not work) and triggers a browser save.
 */
export function useImportTemplateUrl(resource: string) {
  return useCallback(async () => {
    const { data } = await api.get<Blob>(`/${resource}/import/template`, {
      responseType: 'blob',
    });
    const url = URL.createObjectURL(data);
    const link = document.createElement('a');
    link.href = url;
    link.download = `${resource.replace(/\//g, '-')}-import-template.csv`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  }, [resource]);
}

/**
 * POST a CSV file as multipart/form-data to `/{resource}/import`, returning the
 * ImportResult and invalidating the supplied list query on success.
 */
export function useBulkImport(resource: string, listQueryKey: QueryKey) {
  const qc = useQueryClient();
  return useMutation<ImportResult, unknown, File>({
    mutationFn: async (file: File) => {
      const form = new FormData();
      form.append('file', file);
      // Let the browser set the multipart boundary by sending undefined.
      const { data } = await api.post<ImportResult>(`/${resource}/import`, form, {
        headers: { 'Content-Type': undefined },
      });
      return data;
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: listQueryKey });
    },
  });
}
