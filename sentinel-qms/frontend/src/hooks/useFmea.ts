import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Fmea, FmeaItem } from '@/types';

const KEY = ['fmea'] as const;

export function useFmeas() {
  return useQuery<Fmea[]>({
    queryKey: [...KEY, 'list'],
    queryFn: async () => (await api.get<Fmea[]>('/fmea')).data,
    staleTime: 60_000,
  });
}

export function useFmea(id: string | number | undefined) {
  return useQuery<Fmea>({
    queryKey: [...KEY, 'detail', String(id)],
    enabled: id != null,
    queryFn: async () => (await api.get<Fmea>(`/fmea/${id}`)).data,
  });
}

export function useCreateFmea() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: Partial<Fmea>) => (await api.post<Fmea>('/fmea', payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useAddFmeaItem(fmeaId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: Partial<FmeaItem>) =>
      (await api.post<FmeaItem>(`/fmea/${fmeaId}/items`, payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useDeleteFmeaItem() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (itemId: number) => {
      await api.delete(`/fmea/items/${itemId}`);
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}
