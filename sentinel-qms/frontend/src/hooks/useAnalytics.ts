import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { AnalyticsTrends, ParetoResponse } from '@/types';

/** Fetch aggregated analytics trends for the Analytics page. */
export function useAnalyticsTrends(months = 6) {
  return useQuery<AnalyticsTrends>({
    queryKey: ['analytics', 'trends', months],
    queryFn: async () => {
      const { data } = await api.get<AnalyticsTrends>('/analytics/trends', {
        params: { months },
      });
      return data;
    },
  });
}

/** Pareto of nonconformances by a chosen driver. */
export function useAnalyticsPareto(dimension: string) {
  return useQuery<ParetoResponse>({
    queryKey: ['analytics', 'pareto', dimension],
    queryFn: async () => {
      const { data } = await api.get<ParetoResponse>('/analytics/pareto', {
        params: { dimension },
      });
      return data;
    },
  });
}
