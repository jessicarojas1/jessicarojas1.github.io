import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

export interface SavedView {
  id: number;
  page_key: string;
  name: string;
  params: Record<string, unknown>;
}

export function useSavedViews(pageKey: string | undefined) {
  return useQuery<SavedView[]>({
    queryKey: ['saved-views', pageKey],
    enabled: Boolean(pageKey),
    queryFn: async () =>
      (await api.get<SavedView[]>('/saved-views', { params: { page_key: pageKey } })).data,
  });
}

export function useCreateSavedView() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: { page_key: string; name: string; params: Record<string, unknown> }) =>
      (await api.post<SavedView>('/saved-views', payload)).data,
    onSuccess: (_d, vars) => qc.invalidateQueries({ queryKey: ['saved-views', vars.page_key] }),
  });
}

export function useDeleteSavedView(pageKey: string | undefined) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/saved-views/${id}`);
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['saved-views', pageKey] }),
  });
}
