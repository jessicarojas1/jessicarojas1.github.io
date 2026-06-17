import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { QualityObjective } from '@/types';

const KEY = ['quality-objectives'] as const;

export function useQualityObjectives() {
  return useQuery<QualityObjective[]>({
    queryKey: [...KEY, 'list'],
    queryFn: async () => (await api.get<QualityObjective[]>('/quality-objectives')).data,
    staleTime: 60_000,
  });
}

export function useCreateObjective() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: Partial<QualityObjective>) =>
      (await api.post<QualityObjective>('/quality-objectives', payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useUpdateObjective(id: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: Partial<QualityObjective>) =>
      (await api.patch<QualityObjective>(`/quality-objectives/${id}`, payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useRecordMeasurement(objectiveId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: { value: number; measured_at?: string | null; note?: string | null }) =>
      (await api.post(`/quality-objectives/${objectiveId}/measurements`, payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}
