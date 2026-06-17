import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { CustomerSurvey } from '@/types';

const KEY = ['customer-satisfaction'] as const;

export interface CsatSummary {
  average_overall: number | null;
  count: number;
}

export function useCustomerSurveys() {
  return useQuery<CustomerSurvey[]>({
    queryKey: [...KEY, 'list'],
    queryFn: async () => (await api.get<CustomerSurvey[]>('/customer-satisfaction')).data,
    staleTime: 60_000,
  });
}

export function useCsatSummary() {
  return useQuery<CsatSummary>({
    queryKey: [...KEY, 'summary'],
    queryFn: async () => (await api.get<CsatSummary>('/customer-satisfaction/summary')).data,
    staleTime: 60_000,
  });
}

export function useCreateSurvey() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: Partial<CustomerSurvey>) =>
      (await api.post<CustomerSurvey>('/customer-satisfaction', payload)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}
