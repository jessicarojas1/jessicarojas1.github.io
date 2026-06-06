import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { AnalyticsTrends } from '@/types';

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
