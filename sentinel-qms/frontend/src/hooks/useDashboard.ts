import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { DashboardSummary, ExecutiveDashboard } from '@/types';

export function useDashboard() {
  return useQuery<DashboardSummary>({
    queryKey: ['dashboard', 'summary'],
    queryFn: async () => {
      const { data } = await api.get<DashboardSummary>('/dashboard/summary');
      return data;
    },
    staleTime: 60_000,
  });
}

export function useExecutiveDashboard() {
  return useQuery<ExecutiveDashboard>({
    queryKey: ['dashboard', 'executive'],
    queryFn: async () => {
      const { data } = await api.get<ExecutiveDashboard>('/dashboard/executive');
      return data;
    },
    staleTime: 60_000,
  });
}
