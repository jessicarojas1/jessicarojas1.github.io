import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { CounterfeitAlert, SourcingRecord } from '@/types';

const KEY = ['counterfeit'] as const;

export function useSourcingRecords() {
  return useQuery<SourcingRecord[]>({
    queryKey: [...KEY, 'sourcing'],
    queryFn: async () => (await api.get<SourcingRecord[]>('/counterfeit/sourcing')).data,
    staleTime: 60_000,
  });
}

export function useCounterfeitAlerts() {
  return useQuery<CounterfeitAlert[]>({
    queryKey: [...KEY, 'alerts'],
    queryFn: async () => (await api.get<CounterfeitAlert[]>('/counterfeit/alerts')).data,
    staleTime: 60_000,
  });
}

export function useCreateSourcing() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: Partial<SourcingRecord>) =>
      (await api.post<SourcingRecord>('/counterfeit/sourcing', payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useUpdateSourcing() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, payload }: { id: number; payload: Partial<SourcingRecord> }) =>
      (await api.patch<SourcingRecord>(`/counterfeit/sourcing/${id}`, payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useDeleteSourcing() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/counterfeit/sourcing/${id}`);
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useCreateAlert() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: Partial<CounterfeitAlert>) =>
      (await api.post<CounterfeitAlert>('/counterfeit/alerts', payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useUpdateAlert() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ id, payload }: { id: number; payload: Partial<CounterfeitAlert> }) =>
      (await api.patch<CounterfeitAlert>(`/counterfeit/alerts/${id}`, payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useDeleteAlert() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/counterfeit/alerts/${id}`);
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

interface NcrLinkResult {
  ncr_id: number;
  ncr_number: string;
}

export function useRaiseNcrForSourcing() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) =>
      (await api.post<NcrLinkResult>(`/counterfeit/sourcing/${id}/raise-ncr`)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}

export function useRaiseNcrForAlert() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (id: number) =>
      (await api.post<NcrLinkResult>(`/counterfeit/alerts/${id}/raise-ncr`)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}
