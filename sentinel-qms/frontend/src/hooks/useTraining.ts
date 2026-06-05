import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { CompetencyMatrix } from '@/types';

/** Competency matrix endpoint: /training/competency-matrix */
export function useCompetencyMatrix() {
  return useQuery<CompetencyMatrix>({
    queryKey: ['training', 'competency-matrix'],
    queryFn: async () => {
      const { data } = await api.get<CompetencyMatrix>('/training/competency-matrix');
      return data;
    },
  });
}
