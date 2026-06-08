import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { KcDetail, KcMeasurement, KcSummary } from '@/types';

const KEY = ['key-characteristics'] as const;

export function useKeyCharacteristics() {
  return useQuery<KcSummary[]>({
    queryKey: [...KEY, 'list'],
    queryFn: async () => (await api.get<KcSummary[]>('/key-characteristics')).data,
    staleTime: 60_000,
  });
}

export function useKeyCharacteristic(id: string | number | undefined) {
  return useQuery<KcDetail>({
    queryKey: [...KEY, 'detail', String(id)],
    enabled: id != null,
    queryFn: async () => (await api.get<KcDetail>(`/key-characteristics/${id}`)).data,
  });
}

export function useCreateKc() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: Partial<KcSummary>) =>
      (await api.post<KcDetail>('/key-characteristics', payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useUpdateKc(kcId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: Partial<KcSummary>) =>
      (await api.patch<KcDetail>(`/key-characteristics/${kcId}`, payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useAddMeasurement(kcId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: { value: number; measured_at?: string | null; operator?: string | null }) =>
      (await api.post<KcMeasurement>(`/key-characteristics/${kcId}/measurements`, payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useDeleteMeasurement() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/key-characteristics/measurements/${id}`);
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}
