import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { MsaStudy } from '@/types';

const KEY = ['msa-studies'] as const;

export function useMsaStudies() {
  return useQuery<MsaStudy[]>({
    queryKey: [...KEY, 'list'],
    queryFn: async () => (await api.get<MsaStudy[]>('/msa-studies')).data,
    staleTime: 60_000,
  });
}

export function useCreateMsa() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: Partial<MsaStudy>) =>
      (await api.post<MsaStudy>('/msa-studies', payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useUpdateMsa() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, payload }: { id: number; payload: Partial<MsaStudy> }) =>
      (await api.patch<MsaStudy>(`/msa-studies/${id}`, payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useDeleteMsa() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/msa-studies/${id}`);
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}
