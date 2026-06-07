import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type {
  StandardDetail,
  StandardRequirement,
  StandardSummary,
} from '@/types';

const KEY = ['standards'] as const;

export function useStandards() {
  return useQuery<StandardSummary[]>({
    queryKey: [...KEY, 'list'],
    queryFn: async () => {
      const { data } = await api.get<StandardSummary[]>('/standards');
      return data;
    },
    staleTime: 60_000,
  });
}

export function useStandard(id: string | number | undefined) {
  return useQuery<StandardDetail>({
    queryKey: [...KEY, 'detail', String(id)],
    enabled: id != null,
    queryFn: async () => {
      const { data } = await api.get<StandardDetail>(`/standards/${id}`);
      return data;
    },
  });
}

export function useCreateStandard() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: { code: string; name: string; description?: string }) => {
      const { data } = await api.post<StandardDetail>('/standards', payload);
      return data;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useAddRequirement(standardId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: Partial<StandardRequirement>) => {
      const { data } = await api.post<StandardRequirement>(
        `/standards/${standardId}/requirements`,
        payload,
      );
      return data;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useUpdateRequirement() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, payload }: { id: number; payload: Partial<StandardRequirement> }) => {
      const { data } = await api.patch<StandardRequirement>(
        `/standards/requirements/${id}`,
        payload,
      );
      return data;
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useDeleteRequirement() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/standards/requirements/${id}`);
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}
