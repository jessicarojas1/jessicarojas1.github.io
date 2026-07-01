import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { RetentionPolicy } from '@/types';

const KEY = ['retention-policies'] as const;

export function useRetentionPolicies() {
  return useQuery<RetentionPolicy[]>({
    queryKey: [...KEY, 'list'],
    queryFn: async () => (await api.get<RetentionPolicy[]>('/retention-policies')).data,
    staleTime: 60_000,
  });
}

export function useRetentionPolicy(id: number | undefined) {
  return useQuery<RetentionPolicy>({
    queryKey: [...KEY, 'detail', id],
    queryFn: async () => (await api.get<RetentionPolicy>(`/retention-policies/${id}`)).data,
    enabled: Boolean(id),
  });
}

export function useCreateRetentionPolicy() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: Partial<RetentionPolicy>) =>
      (await api.post<RetentionPolicy>('/retention-policies', payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useUpdateRetentionPolicy(id: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: Partial<RetentionPolicy>) =>
      (await api.patch<RetentionPolicy>(`/retention-policies/${id}`, payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}
