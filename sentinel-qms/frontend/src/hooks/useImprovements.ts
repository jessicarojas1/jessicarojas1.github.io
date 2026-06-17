import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Improvement } from '@/types';

const KEY = ['improvements'] as const;

export function useImprovements() {
  return useQuery<Improvement[]>({
    queryKey: [...KEY, 'list'],
    queryFn: async () => (await api.get<Improvement[]>('/improvements')).data,
    staleTime: 60_000,
  });
}

export function useCreateImprovement() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: Partial<Improvement>) =>
      (await api.post<Improvement>('/improvements', payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useUpdateImprovement(id: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: Partial<Improvement>) =>
      (await api.patch<Improvement>(`/improvements/${id}`, payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}
