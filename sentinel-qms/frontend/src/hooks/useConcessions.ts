import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Concession } from '@/types';

const KEY = ['concessions'] as const;

export function useConcessions() {
  return useQuery<Concession[]>({
    queryKey: [...KEY, 'list'],
    queryFn: async () => (await api.get<Concession[]>('/concessions')).data,
    staleTime: 60_000,
  });
}

export function useCreateConcession() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: Partial<Concession>) =>
      (await api.post<Concession>('/concessions', payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useUpdateConcession() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, payload }: { id: number; payload: Partial<Concession> }) =>
      (await api.patch<Concession>(`/concessions/${id}`, payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useDeleteConcession() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/concessions/${id}`);
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}
