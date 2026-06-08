import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { AuditProgramDetail, AuditProgramItem, AuditProgramSummary } from '@/types';

const KEY = ['audit-programs'] as const;

export function useAuditPrograms() {
  return useQuery<AuditProgramSummary[]>({
    queryKey: [...KEY, 'list'],
    queryFn: async () => (await api.get<AuditProgramSummary[]>('/audit-programs')).data,
    staleTime: 60_000,
  });
}

export function useAuditProgram(id: string | number | undefined) {
  return useQuery<AuditProgramDetail>({
    queryKey: [...KEY, 'detail', String(id)],
    enabled: id != null,
    queryFn: async () => (await api.get<AuditProgramDetail>(`/audit-programs/${id}`)).data,
  });
}

export function useCreateProgram() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: { name: string; year: number; objectives?: string }) =>
      (await api.post<AuditProgramDetail>('/audit-programs', payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useUpdateProgram() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, payload }: { id: number; payload: Partial<AuditProgramSummary> }) =>
      (await api.patch<AuditProgramDetail>(`/audit-programs/${id}`, payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useAddProgramItem(programId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: Partial<AuditProgramItem>) =>
      (await api.post<AuditProgramItem>(`/audit-programs/${programId}/items`, payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useUpdateProgramItem() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, payload }: { id: number; payload: Partial<AuditProgramItem> }) =>
      (await api.patch<AuditProgramItem>(`/audit-programs/items/${id}`, payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useDeleteProgramItem() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/audit-programs/items/${id}`);
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}
