import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { FodEvent, FodZone } from '@/types';

const KEY = ['fod'] as const;

export function useFodZones() {
  return useQuery<FodZone[]>({
    queryKey: [...KEY, 'zones'],
    queryFn: async () => (await api.get<FodZone[]>('/fod/zones')).data,
    staleTime: 60_000,
  });
}

export function useFodEvents() {
  return useQuery<FodEvent[]>({
    queryKey: [...KEY, 'events'],
    queryFn: async () => (await api.get<FodEvent[]>('/fod/events')).data,
    staleTime: 60_000,
  });
}

export function useCreateFodZone() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: Partial<FodZone>) =>
      (await api.post<FodZone>('/fod/zones', payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useUpdateFodZone() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, payload }: { id: number; payload: Partial<FodZone> }) =>
      (await api.patch<FodZone>(`/fod/zones/${id}`, payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useDeleteFodZone() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/fod/zones/${id}`);
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useCreateFodEvent() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: Partial<FodEvent>) =>
      (await api.post<FodEvent>('/fod/events', payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useUpdateFodEvent() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, payload }: { id: number; payload: Partial<FodEvent> }) =>
      (await api.patch<FodEvent>(`/fod/events/${id}`, payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useDeleteFodEvent() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/fod/events/${id}`);
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useRaiseNcrForFodEvent() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) =>
      (await api.post<{ ncr_id: number; ncr_number: string }>(`/fod/events/${id}/raise-ncr`)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}
