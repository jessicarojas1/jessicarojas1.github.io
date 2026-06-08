import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { ApqpDetail, ApqpProject, PpapElement } from '@/types';

const KEY = ['apqp'] as const;

export function useApqpProjects() {
  return useQuery<ApqpProject[]>({
    queryKey: [...KEY, 'list'],
    queryFn: async () => (await api.get<ApqpProject[]>('/apqp')).data,
    staleTime: 60_000,
  });
}

export function useApqpProject(id: string | number | undefined) {
  return useQuery<ApqpDetail>({
    queryKey: [...KEY, 'detail', String(id)],
    enabled: id != null,
    queryFn: async () => (await api.get<ApqpDetail>(`/apqp/${id}`)).data,
  });
}

export function useCreateApqp() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: Partial<ApqpProject>) =>
      (await api.post<ApqpDetail>('/apqp', payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useUpdateApqp() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, payload }: { id: number; payload: Partial<ApqpProject> }) =>
      (await api.patch<ApqpDetail>(`/apqp/${id}`, payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useUpdatePpapElement() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, payload }: { id: number; payload: Partial<PpapElement> }) =>
      (await api.patch<PpapElement>(`/apqp/elements/${id}`, payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}
