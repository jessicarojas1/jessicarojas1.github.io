import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { DashboardSummary } from '@/types';

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
